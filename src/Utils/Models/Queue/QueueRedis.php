<?php

namespace StaticPHP\Utils\Models\Queue;

use Redis;

/**
 * The queue, on redis streams.
 *
 * Streams rather than lists, because a list hands a job out and forgets it: the worker that
 * popped it and then died took the job with it. A stream keeps every delivered entry in the
 * consumer group's pending list until somebody acknowledges it, and XAUTOCLAIM hands one
 * back once it has been idle longer than the visibility timeout. That is the same "a claim
 * is a deadline, not a flag" the database driver gets from `reserved_until`, and it is the
 * reason to reach for streams over anything simpler.
 *
 * What it does not get you is the thing the database driver is for. A push here is a second
 * write to a second system, so it cannot join the transaction that caused it: commit the
 * invoice, fail to reach redis, and the email is never queued - or queue the email, roll the
 * invoice back, and it goes out for something that does not exist. Nothing about streams
 * addresses that, and no amount of redis durability settings will. Reach for this driver
 * when the volume genuinely warrants it, or when the jobs are ones you can afford to lose,
 * and reach for QueueDatabase otherwise.
 *
 * The layout, which is worth knowing before reading a key by hand:
 *
 *   {prefix}j:{id}            hash, the job - the same fields the database driver's row has
 *   {prefix}q:{queue}:s:{n}   stream, one per priority, holding the ids that are ready
 *   {prefix}q:{queue}:levels  sorted set of the priorities that queue has streams for
 *   {prefix}q:{queue}:delayed sorted set of ids scored by when they may run
 *   {prefix}u:{key}           the job id holding a unique key
 *   {prefix}failed            sorted set of ids scored by when they failed
 *   {prefix}queues            set of queue names, so status can enumerate them
 *   {prefix}seq               the id counter
 *
 * The stream carries the job id and nothing else. Everything mutable - attempts above all -
 * lives in the hash, because a stream entry cannot be edited and a retry that has to be
 * delayed has to leave the stream anyway. So the stream is an index of what is ready and the
 * hash is the job; losing track of that is the fastest way to misread the code below.
 *
 * Timestamps are split on purpose. `available_at`, `reserved_until` and the sorted set
 * scores are unix seconds, because lua compares them. `created_at` and `failed_at` are
 * "Y-m-d H:i:s" in utc, because a human reads them and the database driver spells them the
 * same way.
 *
 * Not cluster aware: a job's keys are spread across the queue, the job hash and the id
 * counter, which is more than one slot, and the scripts below assume they can reach all of
 * them. One server, or a primary with replicas.
 *
 * Needs redis 6.2 or newer, for XAUTOCLAIM.
 */
class QueueRedis implements QueueInterface, QueueReports
{
    /**
     * How many delayed jobs one reserve may promote into a stream.
     *
     * A bound rather than "all of them" because a scheduled burst - ten thousand jobs all
     * due at midnight - would otherwise hold the server inside one script while it moved
     * them. Fifty per poll drains a burst in seconds and leaves redis answering everybody
     * else in between.
     *
     * @var int
     * @access private
     */
    private const PROMOTE_LIMIT = 50;

    /**
     * One consumer name for every worker.
     *
     * Worker ids are host:pid and so are different on every restart, which would leave the
     * group's consumer list growing a dead name per deploy for somebody to clean up later.
     * The group hands out distinct entries whatever name asks for them, and idle time is
     * kept per entry rather than per consumer, so sharing one name costs nothing. Who is
     * actually holding a job is written to the job's own `reserved_by`.
     *
     * @var string
     * @access private
     */
    private const CONSUMER = 'shared';

    /**
     * Queue a job.
     *
     * The unique key is checked and taken inside the script, because doing it in two round
     * trips is exactly the race the key exists to prevent. A key pointing at a job that is
     * no longer there is treated as free - that only happens if something removed the hash
     * by hand, and refusing to queue for ever afterwards would be the worse answer.
     *
     * @var string
     * @access private
     */
    private const PUSH = <<<'LUA'
    local p, queue, uk = ARGV[1], ARGV[2], ARGV[7]

    if uk ~= '' then
        local held = redis.call('GET', p .. 'u:' .. uk)
        if held then
            if redis.call('EXISTS', p .. 'j:' .. held) == 1 then
                return held
            end
            redis.call('DEL', p .. 'u:' .. uk)
        end
    end

    local id = redis.call('INCR', p .. 'seq')
    local job = p .. 'j:' .. id
    local level = ARGV[5]

    redis.call('HSET', job,
        'queue', queue, 'name', ARGV[3], 'payload', ARGV[4],
        'attempts', '0', 'max_attempts', ARGV[6], 'priority', level,
        'unique_key', uk, 'available_at', ARGV[8], 'reserved_until', '0',
        'reserved_by', '', 'last_error', '', 'created_at', ARGV[9], 'entry', '')

    if uk ~= '' then
        redis.call('SET', p .. 'u:' .. uk, id)
    end

    redis.call('SADD', p .. 'queues', queue)

    if tonumber(ARGV[8]) > tonumber(ARGV[10]) then
        redis.call('ZADD', p .. 'q:' .. queue .. ':delayed', tonumber(ARGV[8]), id)
    else
        local entry = redis.call('XADD', p .. 'q:' .. queue .. ':s:' .. level, '*', 'job', id)
        redis.call('HSET', job, 'entry', entry)
        redis.call('ZADD', p .. 'q:' .. queue .. ':levels', tonumber(level), level)
    end

    return tostring(id)
    LUA;

    /**
     * Claim the next due job from one queue.
     *
     * Three things in one round trip, in the order they have to happen: move whatever is due
     * out of the delayed set and into its stream, then walk the priorities from highest
     * down, taking an abandoned job before a new one at each. Reclaiming first is why a
     * worker that died does not leave its job at the back of the queue.
     *
     * The group is created at id 0 rather than $, because jobs are pushed long before any
     * worker starts and $ would skip every one of them.
     *
     * XAUTOCLAIM decides what is abandoned by how long an entry has gone unacknowledged,
     * which is this worker's timeout rather than the one whoever claimed it was running. So
     * the job's own `reserved_until` gets the last word: a reclaim that arrives while the
     * previous holder is still inside its own, longer timeout parks the job until that
     * deadline instead of running it a second time. The reverse - a dead worker that used a
     * shorter timeout than the one reclaiming - is not an error, only a wait, and the way to
     * avoid both is to run one timeout across the fleet.
     *
     * @var string
     * @access private
     */
    private const RESERVE = <<<'LUA'
    local p, queue = ARGV[1], ARGV[2]
    local now, timeout = tonumber(ARGV[3]), tonumber(ARGV[4])
    local worker, group, consumer = ARGV[5], ARGV[6], ARGV[7]

    local levels = p .. 'q:' .. queue .. ':levels'
    local delayed = p .. 'q:' .. queue .. ':delayed'

    local due = redis.call('ZRANGEBYSCORE', delayed, '-inf', now, 'LIMIT', 0, tonumber(ARGV[8]))
    for i = 1, #due do
        local id = due[i]
        if redis.call('ZREM', delayed, id) == 1 then
            local job = p .. 'j:' .. id
            local level = redis.call('HGET', job, 'priority')
            if level then
                local entry = redis.call('XADD', p .. 'q:' .. queue .. ':s:' .. level, '*', 'job', id)
                redis.call('HSET', job, 'entry', entry)
                redis.call('ZADD', levels, tonumber(level), level)
            end
        end
    end

    local ranked = redis.call('ZREVRANGE', levels, 0, -1)
    for i = 1, #ranked do
        local stream = p .. 'q:' .. queue .. ':s:' .. ranked[i]
        redis.pcall('XGROUP', 'CREATE', stream, group, '0', 'MKSTREAM')

        local taken = nil
        local auto = redis.call('XAUTOCLAIM', stream, group, consumer, timeout * 1000, '0-0', 'COUNT', 1)
        if auto and auto[2] and auto[2][1] and auto[2][1][2] then
            taken = auto[2][1]
        end

        if not taken then
            local read = redis.call('XREADGROUP', 'GROUP', group, consumer,
                'COUNT', 1, 'STREAMS', stream, '>')
            if read and read[1] and read[1][2] and read[1][2][1] then
                taken = read[1][2][1]
            end
        end

        if taken then
            local entry, id = taken[1], taken[2][2]
            local job = p .. 'j:' .. id
            local until_ = tonumber(redis.call('HGET', job, 'reserved_until') or '0') or 0
            if redis.call('EXISTS', job) == 0 then
                redis.call('XACK', stream, group, entry)
                redis.call('XDEL', stream, entry)
            elseif until_ > now then
                redis.call('ZADD', p .. 'q:' .. queue .. ':delayed', until_, id)
                redis.call('HSET', job, 'entry', '')
                redis.call('XACK', stream, group, entry)
                redis.call('XDEL', stream, entry)
            else
                redis.call('HINCRBY', job, 'attempts', 1)
                redis.call('HSET', job, 'reserved_by', worker,
                    'reserved_until', now + timeout, 'entry', entry)
                return {id, entry, redis.call('HGETALL', job)}
            end
        end
    end

    return nil
    LUA;

    /**
     * The job is done: drop the stream entry, the hash and the unique key together.
     *
     * @var string
     * @access private
     */
    private const COMPLETE = <<<'LUA'
    local p, queue, id, entry, group = ARGV[1], ARGV[2], ARGV[3], ARGV[4], ARGV[5]
    local job = p .. 'j:' .. id
    local level = redis.call('HGET', job, 'priority') or '0'
    local stream = p .. 'q:' .. queue .. ':s:' .. level

    redis.call('XACK', stream, group, entry)
    redis.call('XDEL', stream, entry)

    local uk = redis.call('HGET', job, 'unique_key')
    if uk and uk ~= '' then
        redis.call('DEL', p .. 'u:' .. uk)
    end

    redis.call('DEL', job)

    return 1
    LUA;

    /**
     * Put the job back, delayed.
     *
     * The delayed set is written before the stream entry is acknowledged, so a redis that
     * dies mid-script cannot leave the job in neither place. Both at once is recoverable -
     * the entry has no hash behind it any more only after the promote, and reserve drops an
     * entry whose hash is gone - whereas neither is a job that quietly never runs again.
     *
     * @var string
     * @access private
     */
    private const RELEASE = <<<'LUA'
    local p, queue, id, entry, group = ARGV[1], ARGV[2], ARGV[3], ARGV[4], ARGV[5]
    local job = p .. 'j:' .. id
    local level = redis.call('HGET', job, 'priority') or '0'
    local stream = p .. 'q:' .. queue .. ':s:' .. level

    redis.call('ZADD', p .. 'q:' .. queue .. ':delayed', tonumber(ARGV[6]), id)
    redis.call('HSET', job, 'available_at', ARGV[6], 'reserved_until', '0',
        'reserved_by', '', 'last_error', ARGV[7], 'entry', '')

    redis.call('XACK', stream, group, entry)
    redis.call('XDEL', stream, entry)

    return 1
    LUA;

    /**
     * The job is out of attempts.
     *
     * The hash stays exactly where it was and joins the failed set instead of being copied
     * anywhere, so `queue retry` puts back the job that failed rather than a reconstruction
     * of it. The unique key goes, because the job is no longer pending and holding the key
     * would block the next one for ever.
     *
     * @var string
     * @access private
     */
    private const FAIL = <<<'LUA'
    local p, queue, id, entry, group = ARGV[1], ARGV[2], ARGV[3], ARGV[4], ARGV[5]
    local job = p .. 'j:' .. id
    local level = redis.call('HGET', job, 'priority') or '0'
    local stream = p .. 'q:' .. queue .. ':s:' .. level

    redis.call('XACK', stream, group, entry)
    redis.call('XDEL', stream, entry)

    local uk = redis.call('HGET', job, 'unique_key')
    if uk and uk ~= '' then
        redis.call('DEL', p .. 'u:' .. uk)
        redis.call('HSET', job, 'unique_key', '')
    end

    redis.call('HSET', job, 'error', ARGV[6], 'failed_at', ARGV[8],
        'reserved_until', '0', 'reserved_by', '', 'entry', '')
    redis.call('ZADD', p .. 'failed', tonumber(ARGV[7]), id)

    return 1
    LUA;

    /**
     * Put one failed job back on its queue.
     *
     * Same terms as the database driver: a fresh attempt count, because whatever broke was
     * usually fixed outside the job, and no priority or unique key, because both belonged to
     * the push and the key may well have been reused since.
     *
     * @var string
     * @access private
     */
    private const REQUEUE = <<<'LUA'
    local p, id = ARGV[1], ARGV[2]
    local job = p .. 'j:' .. id

    redis.call('ZREM', p .. 'failed', id)

    if redis.call('EXISTS', job) == 0 then
        return 0
    end

    local queue = redis.call('HGET', job, 'queue')
    if not queue or queue == '' then
        queue = 'default'
    end

    redis.call('HSET', job, 'attempts', '0', 'max_attempts', ARGV[3], 'priority', '0',
        'unique_key', '', 'available_at', ARGV[4], 'reserved_until', '0',
        'reserved_by', '', 'last_error', '', 'error', '', 'failed_at', '')

    local entry = redis.call('XADD', p .. 'q:' .. queue .. ':s:0', '*', 'job', id)
    redis.call('HSET', job, 'entry', entry)
    redis.call('ZADD', p .. 'q:' .. queue .. ':levels', 0, '0')
    redis.call('SADD', p .. 'queues', queue)

    return 1
    LUA;

    private Redis $redis;
    private string $prefix;
    private string $group;

    /** @var array<string, string> */
    private array $scripts = [];

    /**
     * @access public
     * @param Redis  $redis
     * @param string $prefix Namespace for every key this driver owns
     * @param string $group  Consumer group name
     */
    public function __construct(Redis $redis, string $prefix = 'queue:', string $group = 'workers')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->group = ($group === '' ? 'workers' : $group);
    }

    /**
     * Connect using config['queue']['redis'].
     *
     * The connection is built here rather than borrowed from Cache\CacheRedis because that
     * one turns on the php serializer, which would rewrite every value this driver puts in a
     * stream field, and because a cache is a thing you are allowed to lose.
     *
     * @access public
     * @static
     * @param  array<string, mixed> $settings
     * @return self
     * @throws QueueError
     */
    public static function connect(array $settings): self
    {
        if (extension_loaded('redis') === false) {
            throw new QueueError('The redis queue driver needs ext-redis; install it or use the database driver');
        }

        $redis = new Redis();
        $host = self::settingString($settings, 'hostname', '127.0.0.1');
        $port = self::settingInt($settings, 'port', 6379);

        try {
            $connected = $redis->connect($host, $port, (float) self::settingInt($settings, 'timeout', 2));
        } catch (\Throwable $exception) {
            throw new QueueError("Could not reach redis at {$host}:{$port}: {$exception->getMessage()}");
        }

        if ($connected === false) {
            throw new QueueError("Could not reach redis at {$host}:{$port}");
        }

        $password = self::settingString($settings, 'password');
        if ($password !== '') {
            $user = self::settingString($settings, 'username');
            $redis->auth($user === '' ? $password : [$user, $password]);
        }

        $database = self::settingInt($settings, 'database', 0);
        if ($database !== 0) {
            $redis->select($database);
        }

        return new self(
            $redis,
            self::settingString($settings, 'prefix', 'queue:'),
            self::settingString($settings, 'group', 'workers')
        );
    }

    /**
     * @access public
     * @return Redis
     */
    public function redis(): Redis
    {
        return $this->redis;
    }

    /**
     * @access public
     * @return string
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * @access public
     * @return string
     */
    public function group(): string
    {
        return $this->group;
    }

    /**
     * Queue a job.
     *
     * @access public
     * @param  string               $name
     * @param  array<string, mixed> $payload
     * @param  int                  $delay
     * @param  string               $queue
     * @param  int                  $priority
     * @param  ?string              $unique
     * @param  int                  $maxAttempts
     * @return int
     */
    public function push(
        string $name,
        array $payload,
        int $delay,
        string $queue,
        int $priority,
        ?string $unique,
        int $maxAttempts
    ): int {
        if ($name === '') {
            throw new QueueError('A job needs a handler name');
        }

        $now = time();
        $result = $this->evaluate('push', self::PUSH, [
            $this->prefix,
            ($queue === '' ? 'default' : $queue),
            $name,
            self::encode($payload),
            (string) $priority,
            (string) max(1, $maxAttempts),
            ($unique ?? ''),
            (string) ($now + max(0, $delay)),
            self::stamp($now),
            (string) $now,
        ]);

        return (is_numeric($result) ? (int) $result : 0);
    }

    /**
     * Claim the next due job from the first of these queues that has one.
     *
     * @access public
     * @param  list<string> $queues
     * @param  int          $timeout
     * @param  string       $worker
     * @return ?Job
     */
    public function reserve(array $queues, int $timeout, string $worker): ?Job
    {
        foreach ($queues as $queue) {
            $job = $this->reserveFrom(($queue === '' ? 'default' : $queue), max(1, $timeout), $worker);
            if ($job !== null) {
                return $job;
            }
        }

        return null;
    }

    /**
     * @access private
     * @param  string $queue
     * @param  int    $timeout
     * @param  string $worker
     * @return ?Job
     */
    private function reserveFrom(string $queue, int $timeout, string $worker): ?Job
    {
        $claimed = $this->evaluate('reserve', self::RESERVE, [
            $this->prefix,
            $queue,
            (string) time(),
            (string) $timeout,
            substr($worker, 0, 64),
            $this->group,
            self::CONSUMER,
            (string) self::PROMOTE_LIMIT,
        ]);

        if (is_array($claimed) === false || count($claimed) < 3) {
            return null;
        }

        $id = (is_numeric($claimed[0]) ? (int) $claimed[0] : 0);
        $entry = (is_string($claimed[1]) ? $claimed[1] : '');
        $row = (is_array($claimed[2]) ? self::pairs($claimed[2]) : []);

        return $this->toJob($id, $entry, $row);
    }

    /**
     * The job is done.
     *
     * @access public
     * @param  Job $job
     * @return void
     */
    public function delete(Job $job): void
    {
        $this->evaluate('complete', self::COMPLETE, [
            $this->prefix,
            $job->queue,
            (string) $job->id,
            $job->handle,
            $this->group,
        ]);
    }

    /**
     * Put the job back.
     *
     * @access public
     * @param  Job    $job
     * @param  int    $delay
     * @param  string $error
     * @return void
     */
    public function release(Job $job, int $delay, string $error): void
    {
        $this->evaluate('release', self::RELEASE, [
            $this->prefix,
            $job->queue,
            (string) $job->id,
            $job->handle,
            $this->group,
            (string) (time() + max(0, $delay)),
            self::fit($error),
        ]);
    }

    /**
     * Move the job to the failed set.
     *
     * @access public
     * @param  Job    $job
     * @param  string $error
     * @return void
     */
    public function fail(Job $job, string $error): void
    {
        $this->failJob($job->queue, $job->id, $job->handle, $error);
    }

    /**
     * @access private
     * @param  string $queue
     * @param  int    $id
     * @param  string $entry
     * @param  string $error
     * @return void
     */
    private function failJob(string $queue, int $id, string $entry, string $error): void
    {
        $now = time();
        $this->evaluate('fail', self::FAIL, [
            $this->prefix,
            $queue,
            (string) $id,
            $entry,
            $this->group,
            self::fit($error),
            (string) $now,
            self::stamp($now),
        ]);
    }

    /**
     * How many jobs could be picked up right now.
     *
     * @access public
     * @param  ?string $queue
     * @return int
     */
    public function pending(?string $queue = null): int
    {
        $total = 0;

        foreach (($queue === null ? $this->queues() : [$queue]) as $name) {
            $counts = $this->countQueue($name);
            $total += $counts['pending'];
        }

        return $total;
    }

    /**
     * The backlog per queue.
     *
     * @access public
     * @return list<array{queue: string, pending: int, delayed: int, reserved: int, total: int}>
     */
    public function stats(): array
    {
        $out = [];

        foreach ($this->queues() as $name) {
            $counts = $this->countQueue($name);

            if ($counts['total'] === 0) {
                continue;
            }

            $out[] = [
                'queue' => $name,
                'pending' => $counts['pending'],
                'delayed' => $counts['delayed'],
                'reserved' => $counts['reserved'],
                'total' => $counts['total'],
            ];
        }

        return $out;
    }

    /**
     * Count one queue the four ways `queue status` prints.
     *
     * "reserved" is the size of the consumer group's pending list, which counts every entry
     * a worker was handed and has not acknowledged - including one whose claim has already
     * run out and which the next reserve will take back. Reading the exact split would mean
     * asking for every pending entry's idle time, which is a lot of work to move a job one
     * column left in a status table.
     *
     * @access private
     * @param  string $queue
     * @return array{pending: int, delayed: int, reserved: int, total: int}
     */
    private function countQueue(string $queue): array
    {
        $now = time();
        $inStreams = 0;
        $held = 0;

        foreach ($this->levels($queue) as $level) {
            $stream = $this->streamKey($queue, $level);
            $inStreams += self::asInt($this->call(fn(): mixed => $this->redis->xLen($stream)));
            $held += $this->heldIn($stream);
        }

        $delayedKey = $this->key("q:{$queue}:delayed");
        $due = self::asInt($this->call(fn(): mixed => $this->redis->zCount($delayedKey, '-inf', (string) $now)));
        $scheduled = self::asInt($this->call(fn(): mixed => $this->redis->zCard($delayedKey))) - $due;

        $pending = max(0, $inStreams - $held) + $due;
        $scheduled = max(0, $scheduled);

        return [
            'pending' => $pending,
            'delayed' => $scheduled,
            'reserved' => $held,
            'total' => $pending + $scheduled + $held,
        ];
    }

    /**
     * @access private
     * @param  string $stream
     * @return int
     */
    private function heldIn(string $stream): int
    {
        // XPENDING on a group that does not exist is an error, and a queue that has been
        // pushed to but never worked has streams and no group yet. Reporting on it should
        // not be the thing that creates one either, so an absent group counts as nobody
        // holding anything.
        if ($this->hasGroup($stream) === false) {
            return 0;
        }

        $summary = $this->call(fn(): mixed => $this->redis->xPending($stream, $this->group));

        return (is_array($summary) ? self::asInt($summary[0] ?? 0) : 0);
    }

    /**
     * @access private
     * @param  string $stream
     * @return bool
     */
    private function hasGroup(string $stream): bool
    {
        try {
            $groups = $this->redis->xInfo('GROUPS', $stream);
        } catch (\Throwable) {
            return false;
        }

        foreach (self::asList($groups) as $group) {
            $name = (is_array($group) ? ($group['name'] ?? null) : null);
            if ($name === $this->group) {
                return true;
            }
        }

        return false;
    }

    /**
     * @access public
     * @return int
     */
    public function failedCount(): int
    {
        $key = $this->key('failed');

        return self::asInt($this->call(fn(): mixed => $this->redis->zCard($key)));
    }

    /**
     * Recent failures, newest first.
     *
     * @access public
     * @param  int $limit
     * @return list<array<string, mixed>>
     */
    public function failedRows(int $limit): array
    {
        $key = $this->key('failed');
        $ids = $this->call(fn(): mixed => $this->redis->zRevRange($key, 0, max(1, $limit) - 1));

        $out = [];
        foreach (self::asList($ids) as $id) {
            $row = $this->row(self::asInt($id));
            if ($row !== []) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Put failed jobs back on the queue.
     *
     * @access public
     * @param  ?int $id
     * @param  int  $maxAttempts
     * @return int
     */
    public function retryFailed(?int $id, int $maxAttempts): int
    {
        $requeued = 0;

        foreach (($id === null ? $this->failedIds(null) : [$id]) as $one) {
            $done = $this->evaluate('requeue', self::REQUEUE, [
                $this->prefix,
                (string) $one,
                (string) max(1, $maxAttempts),
                (string) time(),
            ]);

            if (self::asInt($done) === 1) {
                $requeued++;
            }
        }

        return $requeued;
    }

    /**
     * Delete failed jobs, by id or by age.
     *
     * @access public
     * @param  ?int    $id
     * @param  ?string $before
     * @return int
     */
    public function forgetFailed(?int $id, ?string $before): int
    {
        $ids = ($id === null ? $this->failedIds($before) : [$id]);
        if ($ids === []) {
            return 0;
        }

        $key = $this->key('failed');
        $deleted = 0;

        foreach ($ids as $one) {
            $removed = self::asInt($this->call(fn(): mixed => $this->redis->zRem($key, (string) $one)));
            if ($removed === 0 && $id !== null) {
                continue;
            }

            $job = $this->jobKey($one);
            $this->call(fn(): mixed => $this->redis->del($job));
            $deleted++;
        }

        return $deleted;
    }

    /**
     * The ids in the failed set, oldest first, optionally only those older than a moment.
     *
     * @access private
     * @param  ?string $before
     * @return list<int>
     */
    private function failedIds(?string $before): array
    {
        $key = $this->key('failed');

        $ids = ($before === null
            ? $this->call(fn(): mixed => $this->redis->zRange($key, 0, -1))
            : $this->call(
                fn(): mixed => $this->redis->zRangeByScore($key, '-inf', '(' . self::timestampOf($before))
            ));

        $out = [];
        foreach (self::asList($ids) as $one) {
            $out[] = self::asInt($one);
        }

        return $out;
    }

    /**
     * The queue names anything has ever been pushed to.
     *
     * @access private
     * @return list<string>
     */
    private function queues(): array
    {
        $key = $this->key('queues');
        $names = $this->call(fn(): mixed => $this->redis->sMembers($key));

        $out = [];
        foreach (self::asList($names) as $name) {
            if (is_string($name) === true && $name !== '') {
                $out[] = $name;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * The priorities one queue has a stream for, highest first.
     *
     * @access private
     * @param  string $queue
     * @return list<string>
     */
    private function levels(string $queue): array
    {
        $key = $this->key("q:{$queue}:levels");
        $levels = $this->call(fn(): mixed => $this->redis->zRevRange($key, 0, -1));

        $out = [];
        foreach (self::asList($levels) as $level) {
            if (is_numeric($level) === true) {
                $out[] = (string) $level;
            }
        }

        return $out;
    }

    /**
     * One job as the commands want to read it.
     *
     * @access private
     * @param  int $id
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $key = $this->jobKey($id);
        $hash = $this->call(fn(): mixed => $this->redis->hGetAll($key));

        if (is_array($hash) === false || $hash === []) {
            return [];
        }

        $row = [];
        foreach ($hash as $field => $value) {
            $row[(string) $field] = $value;
        }

        $row['id'] = $id;

        return $row;
    }

    /**
     * Build a Job from a reserved hash, or fail it and return null.
     *
     * A payload that will not decode can never run, so it goes straight to the failed set
     * the way an unreadable row does on the database driver.
     *
     * @access private
     * @param  int                  $id
     * @param  string               $entry
     * @param  array<string, mixed> $row
     * @return ?Job
     */
    private function toJob(int $id, string $entry, array $row): ?Job
    {
        if ($id === 0 || $row === []) {
            return null;
        }

        $queue = self::text($row, 'queue', 'default');
        $json = self::text($row, 'payload', '[]');
        $decoded = json_decode($json, true);

        if (is_array($decoded) === false) {
            $this->failJob($queue, $id, $entry, 'Payload is not valid JSON, so no handler could be given it.');

            return null;
        }

        $out = [];
        foreach ($decoded as $field => $value) {
            $out[(string) $field] = $value;
        }

        return new Job(
            $id,
            $queue,
            self::text($row, 'name'),
            $out,
            $json,
            max(1, self::asInt($row['attempts'] ?? 0)),
            max(1, self::asInt($row['max_attempts'] ?? 1)),
            $entry,
        );
    }

    /**
     * Run a script, sending the body only when redis has forgotten it.
     *
     * Scripts are loaded once and called by hash after that, because the reserve script is
     * two kilobytes and a worker polling once a second would otherwise spend its life
     * uploading it. A redis that has restarted or been SCRIPT FLUSHed answers NOSCRIPT, and
     * that is the one case worth sending the body again for.
     *
     * Every body is a constant in this class and nothing caller-supplied is ever spliced
     * into one. Job names, payloads and queue names arrive as ARGV, which redis passes to
     * the script as data and never parses as lua.
     *
     * @access private
     * @param  string       $name
     * @param  string       $body
     * @param  list<string> $arguments
     * @return mixed
     * @throws QueueError
     */
    private function evaluate(string $name, string $body, array $arguments): mixed
    {
        $sha = ($this->scripts[$name] ?? null);

        if ($sha === null) {
            $loaded = $this->call(fn(): mixed => $this->redis->script('load', $body));
            $sha = (is_string($loaded) ? $loaded : '');
            $this->scripts[$name] = $sha;
        }

        $this->redis->clearLastError();

        try {
            $result = ($sha === ''
                ? $this->redis->eval($body, $arguments, 0)
                : $this->redis->evalSha($sha, $arguments, 0));
            $error = (string) $this->redis->getLastError();
        } catch (\Throwable $exception) {
            $result = false;
            $error = $exception->getMessage();
        }

        if (str_contains($error, 'NOSCRIPT') === true) {
            unset($this->scripts[$name]);
            $this->redis->clearLastError();
            $result = $this->call(fn(): mixed => $this->redis->eval($body, $arguments, 0));
            $error = (string) $this->redis->getLastError();
        }

        if ($error !== '') {
            $this->redis->clearLastError();

            throw new QueueError("The queue's {$name} script failed: {$error}");
        }

        return $result;
    }

    /**
     * Run one redis command, reporting a dead connection in the queue's own terms.
     *
     * The worker treats a throw from reserve() as "the queue is unreadable" and backs off,
     * which is the right response to redis being down and the wrong response to an uncaught
     * RedisException tearing the process down instead.
     *
     * @access private
     * @param  callable(): mixed $command
     * @return mixed
     * @throws QueueError
     */
    private function call(callable $command): mixed
    {
        try {
            return $command();
        } catch (\Throwable $exception) {
            throw new QueueError("The queue could not reach redis: {$exception->getMessage()}");
        }
    }

    /**
     * @access private
     * @param  string $suffix
     * @return string
     */
    private function key(string $suffix): string
    {
        return $this->prefix . $suffix;
    }

    /**
     * @access private
     * @param  int $id
     * @return string
     */
    private function jobKey(int $id): string
    {
        return $this->prefix . 'j:' . $id;
    }

    /**
     * @access private
     * @param  string $queue
     * @param  string $level
     * @return string
     */
    private function streamKey(string $queue, string $level): string
    {
        return $this->prefix . 'q:' . $queue . ':s:' . $level;
    }

    /**
     * HGETALL as lua returns it - field, value, field, value - as an array.
     *
     * @access private
     * @static
     * @param  array<mixed, mixed> $flat
     * @return array<string, mixed>
     */
    private static function pairs(array $flat): array
    {
        $values = array_values($flat);
        $out = [];

        for ($i = 0; $i + 1 < count($values); $i += 2) {
            $field = $values[$i];
            if (is_scalar($field) === true) {
                $out[(string) $field] = $values[$i + 1];
            }
        }

        return $out;
    }

    /**
     * @access private
     * @static
     * @param  int $timestamp
     * @return string
     */
    private static function stamp(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->format('Y-m-d H:i:s');
    }

    /**
     * A "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" moment, read as utc, as unix seconds.
     *
     * @access private
     * @static
     * @param  string $moment
     * @return int
     * @throws QueueError
     */
    private static function timestampOf(string $moment): int
    {
        $when = \DateTimeImmutable::createFromFormat(
            (strlen($moment) === 10 ? 'Y-m-d|' : 'Y-m-d H:i:s'),
            $moment,
            new \DateTimeZone('UTC')
        );

        if ($when === false) {
            throw new QueueError("\"{$moment}\" is not a date the queue can read");
        }

        return $when->getTimestamp();
    }

    /**
     * @access private
     * @static
     * @param  array<string, mixed> $payload
     * @return string
     */
    private static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new QueueError("A job payload has to be JSON encodable: {$exception->getMessage()}");
        }
    }

    /**
     * @access private
     * @static
     * @param  string $error
     * @return string
     */
    private static function fit(string $error): string
    {
        return (strlen($error) > 60000 ? substr($error, 0, 60000) . "\n... truncated" : $error);
    }

    /**
     * @access private
     * @static
     * @param  array<string, mixed> $row
     * @param  string               $field
     * @param  string               $default
     * @return string
     */
    private static function text(array $row, string $field, string $default = ''): string
    {
        $value = ($row[$field] ?? null);

        return (is_string($value) && $value !== '' ? $value : $default);
    }

    /**
     * @access private
     * @static
     * @param  mixed $value
     * @return int
     */
    private static function asInt(mixed $value): int
    {
        return (is_numeric($value) ? (int) $value : 0);
    }

    /**
     * @access private
     * @static
     * @param  mixed $value
     * @return list<mixed>
     */
    private static function asList(mixed $value): array
    {
        return (is_array($value) ? array_values($value) : []);
    }

    /**
     * @access private
     * @static
     * @param  array<string, mixed> $settings
     * @param  string               $key
     * @param  string               $default
     * @return string
     */
    private static function settingString(array $settings, string $key, string $default = ''): string
    {
        $value = ($settings[$key] ?? null);

        return (is_string($value) && $value !== '' ? $value : $default);
    }

    /**
     * @access private
     * @static
     * @param  array<string, mixed> $settings
     * @param  string               $key
     * @param  int                  $default
     * @return int
     */
    private static function settingInt(array $settings, string $key, int $default = 0): int
    {
        $value = ($settings[$key] ?? null);

        return (is_numeric($value) ? (int) $value : $default);
    }
}
