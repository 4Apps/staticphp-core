<?php

namespace StaticPHP\Utils\Models\Queue;

use PDO;
use StaticPHP\Utils\Models\Db;

/**
 * The queue, in two tables on the application's own database.
 *
 * The reason to keep jobs here rather than somewhere faster is that a push can then be part
 * of the transaction that caused it. Queue the confirmation email inside the transaction
 * that writes the payment and either both happen or neither does; no other backend can say
 * that, because no other backend is the same database.
 *
 * Reserving is a candidate SELECT followed by a guarded UPDATE, and correctness rests on
 * the guard - `WHERE id = ? AND (reserved_until IS NULL OR reserved_until <= ?)` claims the
 * row only if nobody else already did. `FOR UPDATE SKIP LOCKED` is added where the server
 * supports it, which turns "workers wait for each other" into "workers step past each
 * other", but it is an optimisation and not what makes the claim safe.
 */
class QueueDatabase implements QueueInterface
{
    /**
     * Table names are concatenated into the query, so nothing but a plain identifier -
     * optionally schema-qualified - is accepted.
     *
     * @var string
     * @access private
     */
    private const TABLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /**
     * How many rows to lose a race over before reporting the queue empty.
     *
     * With SKIP LOCKED a lost race is close to impossible; without it, two workers picking
     * the same candidate is ordinary. Retrying a few times covers that. Giving up rather
     * than looping forever means a queue that is genuinely contended returns to the worker
     * loop, which sleeps and comes back, instead of spinning here.
     *
     * @var int
     * @access private
     */
    private const CLAIM_ROUNDS = 5;

    private string $connection;
    private string $table;
    private string $failedTable;
    private ?string $driverName = null;
    private ?bool $skipLocked = null;

    /**
     * @access public
     * @param string $connection  Entry of config['db']['pdo']
     * @param string $table       Where pending jobs live
     * @param string $failedTable Where jobs go when they run out of attempts
     */
    public function __construct(string $connection, string $table, string $failedTable)
    {
        self::assertTableName($table);
        self::assertTableName($failedTable);

        $this->connection = $connection;
        $this->table = $table;
        $this->failedTable = $failedTable;
    }

    /**
     * @access public
     * @static
     * @param  string $table
     * @return void
     * @throws QueueError
     */
    public static function assertTableName(string $table): void
    {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new QueueError("\"{$table}\" is not a plain table name");
        }
    }

    /**
     * @access public
     * @return string
     */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * @access public
     * @return string
     */
    public function failedTable(): string
    {
        return $this->failedTable;
    }

    /**
     * @access public
     * @return string
     */
    public function connection(): string
    {
        return $this->connection;
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

        $key = ($unique === null || $unique === '' ? null : $unique);
        if ($key !== null) {
            $existing = $this->idByUniqueKey($key);
            if ($existing !== null) {
                return $existing;
            }
        }

        $now = time();
        $row = [
            'queue' => ($queue === '' ? 'default' : $queue),
            'name' => $name,
            'payload' => self::encode($payload),
            'attempts' => 0,
            'max_attempts' => max(1, $maxAttempts),
            'priority' => $priority,
            'unique_key' => $key,
            'available_at' => $this->stamp($now + max(0, $delay)),
            'last_error' => '',
            'created_at' => $this->stamp($now),
        ];

        try {
            // Wrapped even though it is one statement, because losing the unique_key race
            // aborts the surrounding transaction on postgres. Nested, this takes a
            // savepoint, so the caller's transaction survives the duplicate and carries on.
            $id = Db::transaction(fn(): int => $this->insert($row), $this->connection);
        } catch (\PDOException $exception) {
            $existing = ($key === null ? null : $this->idByUniqueKey($key));
            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }

        return (is_int($id) ? $id : 0);
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
            $job = $this->reserveFrom($queue, max(1, $timeout), $worker);
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
        for ($round = 0; $round < self::CLAIM_ROUNDS; $round++) {
            $now = time();
            $nowStamp = $this->stamp($now);
            $untilStamp = $this->stamp($now + $timeout);

            // The select and the claim share a transaction so that FOR UPDATE still holds
            // the row when the UPDATE runs. The job itself is run well outside it - holding
            // a transaction open for the length of a job is how a queue takes a database
            // down with it.
            $row = Db::transaction(
                function () use ($queue, $nowStamp, $untilStamp, $worker): mixed {
                    $id = $this->candidate($queue, $nowStamp);
                    if ($id === null) {
                        return null;
                    }

                    if ($this->claim($id, $nowStamp, $untilStamp, $worker) === false) {
                        return false;
                    }

                    return Db::fetch("SELECT * FROM {$this->table} WHERE id = ?", [$id], $this->connection);
                },
                $this->connection
            );

            if ($row === null) {
                return null;
            }

            // Somebody else claimed it between the select and the update
            if ($row === false) {
                continue;
            }

            $job = $this->toJob($row);
            if ($job !== null) {
                return $job;
            }

            // The row was unreadable and has been failed. Something else may still be due.
        }

        return null;
    }

    /**
     * The next job that could be claimed, without claiming it.
     *
     * @access private
     * @param  string $queue
     * @param  string $now
     * @return ?int
     */
    private function candidate(string $queue, string $now): ?int
    {
        $row = Db::fetch(
            "SELECT id FROM {$this->table}"
            . ' WHERE queue = ? AND available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?)'
            . ' ORDER BY priority DESC, available_at, id'
            . ' LIMIT 1'
            . $this->lockClause(),
            [$queue, $now, $now],
            $this->connection
        );

        return self::columnInt($row, 'id');
    }

    /**
     * Take the row, if it is still there to take.
     *
     * @access private
     * @param  int    $id
     * @param  string $now
     * @param  string $until
     * @param  string $worker
     * @return bool
     */
    private function claim(int $id, string $now, string $until, string $worker): bool
    {
        $statement = Db::query(
            "UPDATE {$this->table} SET reserved_until = ?, reserved_by = ?, attempts = attempts + 1"
            . ' WHERE id = ? AND (reserved_until IS NULL OR reserved_until <= ?)',
            [$until, substr($worker, 0, 64), $id, $now],
            $this->connection
        );

        return $statement->rowCount() === 1;
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
        Db::query("DELETE FROM {$this->table} WHERE id = ?", [$job->id], $this->connection);
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
        Db::query(
            "UPDATE {$this->table}"
            . ' SET reserved_until = NULL, reserved_by = NULL, available_at = ?, last_error = ?'
            . ' WHERE id = ?',
            [$this->stamp(time() + max(0, $delay)), self::fit($error), $job->id],
            $this->connection
        );
    }

    /**
     * Move the job to the failed table.
     *
     * @access public
     * @param  Job    $job
     * @param  string $error
     * @return void
     */
    public function fail(Job $job, string $error): void
    {
        $this->moveToFailed($job->queue, $job->name, $job->payloadJson, $job->attempts, $error, $job->id);
    }

    /**
     * @access private
     * @param  string $queue
     * @param  string $name
     * @param  string $payload
     * @param  int    $attempts
     * @param  string $error
     * @param  int    $id
     * @return void
     */
    private function moveToFailed(
        string $queue,
        string $name,
        string $payload,
        int $attempts,
        string $error,
        int $id
    ): void {
        Db::transaction(
            function () use ($queue, $name, $payload, $attempts, $error, $id): void {
                Db::insert($this->failedTable, [
                    'queue' => $queue,
                    'name' => $name,
                    'payload' => $payload,
                    'attempts' => $attempts,
                    'error' => self::fit($error),
                    'failed_at' => $this->stamp(time()),
                ], $this->connection);

                Db::query("DELETE FROM {$this->table} WHERE id = ?", [$id], $this->connection);
            },
            $this->connection
        );
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
        $now = $this->stamp(time());
        $sql = "SELECT COUNT(*) AS total FROM {$this->table}"
            . ' WHERE available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?)';
        $params = [$now, $now];

        if ($queue !== null) {
            $sql .= ' AND queue = ?';
            $params[] = $queue;
        }

        return (self::columnInt(Db::fetch($sql, $params, $this->connection), 'total') ?? 0);
    }

    /**
     * The backlog, split the three ways that matter when something looks stuck.
     *
     * @access public
     * @return list<array{queue: string, pending: int, delayed: int, reserved: int, total: int}>
     */
    public function stats(): array
    {
        $now = $this->stamp(time());

        $rows = Db::fetchAll(
            'SELECT queue,'
            . ' SUM(CASE WHEN available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?)'
            . ' THEN 1 ELSE 0 END) AS pending,'
            . ' SUM(CASE WHEN available_at > ? THEN 1 ELSE 0 END) AS delayed,'
            . ' SUM(CASE WHEN reserved_until > ? THEN 1 ELSE 0 END) AS reserved,'
            . ' COUNT(*) AS total'
            . " FROM {$this->table} GROUP BY queue ORDER BY queue",
            [$now, $now, $now, $now],
            $this->connection
        );

        $out = [];
        foreach ($rows as $row) {
            $data = self::rowArray($row);
            if ($data === null) {
                continue;
            }

            $queue = ($data['queue'] ?? '');
            $out[] = [
                'queue' => (is_string($queue) ? $queue : ''),
                'pending' => (self::columnInt($row, 'pending') ?? 0),
                'delayed' => (self::columnInt($row, 'delayed') ?? 0),
                'reserved' => (self::columnInt($row, 'reserved') ?? 0),
                'total' => (self::columnInt($row, 'total') ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @access public
     * @return int
     */
    public function failedCount(): int
    {
        $row = Db::fetch("SELECT COUNT(*) AS total FROM {$this->failedTable}", [], $this->connection);

        return (self::columnInt($row, 'total') ?? 0);
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
        $limit = max(1, $limit);
        $rows = Db::fetchAll(
            "SELECT * FROM {$this->failedTable} ORDER BY failed_at DESC, id DESC LIMIT {$limit}",
            [],
            $this->connection
        );

        $out = [];
        foreach ($rows as $row) {
            $data = self::rowArray($row);
            if ($data !== null) {
                $out[] = $data;
            }
        }

        return $out;
    }

    /**
     * Put failed jobs back on the queue.
     *
     * The retried job starts over with a fresh attempt count, because the reason it failed
     * is usually something that was fixed outside it. It does not get its unique_key back -
     * that column belongs to the pending job, and the key may well have been reused while
     * this one sat in the failed table.
     *
     * @access public
     * @param  ?int $id          One job, or null for all of them
     * @param  int  $maxAttempts
     * @return int How many were requeued
     */
    public function retryFailed(?int $id, int $maxAttempts): int
    {
        $sql = "SELECT * FROM {$this->failedTable}";
        $params = [];

        if ($id !== null) {
            $sql .= ' WHERE id = ?';
            $params[] = $id;
        }

        $requeued = 0;
        foreach (Db::fetchAll($sql . ' ORDER BY id', $params, $this->connection) as $row) {
            $data = self::rowArray($row);
            if ($data === null) {
                continue;
            }

            $failedId = (self::columnInt($row, 'id') ?? 0);
            if ($failedId === 0) {
                continue;
            }

            Db::transaction(
                function () use ($data, $failedId, $maxAttempts): void {
                    Db::insert($this->table, [
                        'queue' => self::text($data, 'queue', 'default'),
                        'name' => self::text($data, 'name'),
                        'payload' => self::text($data, 'payload', '[]'),
                        'attempts' => 0,
                        'max_attempts' => max(1, $maxAttempts),
                        'priority' => 0,
                        'unique_key' => null,
                        'available_at' => $this->stamp(time()),
                        'last_error' => '',
                        'created_at' => $this->stamp(time()),
                    ], $this->connection);

                    Db::query("DELETE FROM {$this->failedTable} WHERE id = ?", [$failedId], $this->connection);
                },
                $this->connection
            );

            $requeued++;
        }

        return $requeued;
    }

    /**
     * Delete failed jobs, by id or by age.
     *
     * @access public
     * @param  ?int    $id
     * @param  ?string $before YYYY-MM-DD or YYYY-MM-DD HH:MM:SS
     * @return int
     */
    public function forgetFailed(?int $id, ?string $before): int
    {
        $sql = "DELETE FROM {$this->failedTable}";
        $params = [];

        if ($id !== null) {
            $sql .= ' WHERE id = ?';
            $params[] = $id;
        } elseif ($before !== null) {
            $sql .= ' WHERE failed_at < ?';
            $params[] = $before;
        }

        return Db::query($sql, $params, $this->connection)->rowCount();
    }

    /**
     * Build a Job from a reserved row, or fail the row and return null.
     *
     * A row whose payload will not decode can never run, so retrying it is just a slower
     * way of reaching the same place. It goes straight to the failed table where somebody
     * can look at it.
     *
     * @access private
     * @param  mixed $row
     * @return ?Job
     */
    private function toJob(mixed $row): ?Job
    {
        $data = self::rowArray($row);
        if ($data === null) {
            return null;
        }

        $id = (self::columnInt($row, 'id') ?? 0);
        $json = self::text($data, 'payload', '[]');
        $decoded = json_decode($json, true);

        if (is_array($decoded) === false) {
            $this->moveToFailed(
                self::text($data, 'queue', 'default'),
                self::text($data, 'name'),
                $json,
                (self::columnInt($row, 'attempts') ?? 0),
                'Payload is not valid JSON, so no handler could be given it.',
                $id
            );

            return null;
        }

        return new Job(
            $id,
            self::text($data, 'queue', 'default'),
            self::text($data, 'name'),
            self::stringKeyed($decoded),
            $json,
            (self::columnInt($row, 'attempts') ?? 0),
            max(1, self::columnInt($row, 'max_attempts') ?? 1),
        );
    }

    /**
     * @access private
     * @param  array<string, mixed> $row
     * @return int
     */
    private function insert(array $row): int
    {
        // Postgres has no lastInsertId without a sequence name, and guessing the name from
        // the table is how that breaks on a renamed sequence.
        if ($this->driver() === 'pgsql') {
            return (self::columnInt(
                Db::insert($this->table, $row, $this->connection, 'RETURNING id'),
                'id'
            ) ?? 0);
        }

        Db::insert($this->table, $row, $this->connection);

        return (Db::lastInsertId('', false, $this->connection) ?? 0);
    }

    /**
     * @access private
     * @param  string $unique
     * @return ?int
     */
    private function idByUniqueKey(string $unique): ?int
    {
        return self::columnInt(
            Db::fetch("SELECT id FROM {$this->table} WHERE unique_key = ?", [$unique], $this->connection),
            'id'
        );
    }

    /**
     * `FOR UPDATE SKIP LOCKED` where the server has it, nothing where it does not.
     *
     * @access private
     * @return string
     */
    private function lockClause(): string
    {
        return match ($this->driver()) {
            'pgsql' => ' FOR UPDATE SKIP LOCKED',
            'mysql' => ($this->mysqlSkipsLocked() ? ' FOR UPDATE SKIP LOCKED' : ''),
            default => '',
        };
    }

    /**
     * Whether this MySQL or MariaDB is new enough for SKIP LOCKED.
     *
     * Asked once per process and by version rather than by trying it, because the failure
     * mode of trying is a syntax error inside a transaction, which on some setups takes the
     * transaction with it.
     *
     * @access private
     * @return bool
     */
    private function mysqlSkipsLocked(): bool
    {
        if ($this->skipLocked !== null) {
            return $this->skipLocked;
        }

        $version = Db::init($this->connection)->getAttribute(PDO::ATTR_SERVER_VERSION);
        $version = (is_string($version) ? $version : '');

        preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches);
        $number = ($matches[1] ?? '0.0.0');

        $this->skipLocked = (stripos($version, 'mariadb') !== false
            ? version_compare($number, '10.6.0', '>=')
            : version_compare($number, '8.0.0', '>='));

        return $this->skipLocked;
    }

    /**
     * @access private
     * @return string
     */
    private function driver(): string
    {
        if ($this->driverName === null) {
            $name = Db::init($this->connection)->getAttribute(PDO::ATTR_DRIVER_NAME);
            $this->driverName = (is_string($name) ? $name : '');
        }

        return $this->driverName;
    }

    /**
     * A moment in UTC, spelled the way this driver reads it.
     *
     * Every comparison the queue makes is against a value bound from here rather than
     * against the server's CURRENT_TIMESTAMP, so a worker and the database disagreeing
     * about the timezone cannot make a job run early or never. Postgres is given an
     * explicit offset because timestamptz would otherwise read a bare string in the
     * session's timezone.
     *
     * @access private
     * @param  int $timestamp
     * @return string
     */
    private function stamp(int $timestamp): string
    {
        $format = ($this->driver() === 'pgsql' ? 'Y-m-d H:i:sP' : 'Y-m-d H:i:s');

        return (new \DateTimeImmutable('@' . $timestamp))->format($format);
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
     * Trim an error to something a text column and a human can both take.
     *
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
     * A row as an array, whichever fetch mode the connection is configured for.
     *
     * @access private
     * @static
     * @param  mixed $row
     * @return ?array<string, mixed>
     */
    private static function rowArray(mixed $row): ?array
    {
        if (is_array($row) === true) {
            return self::stringKeyed($row);
        }

        if (is_object($row) === true) {
            return self::stringKeyed(get_object_vars($row));
        }

        return null;
    }

    /**
     * @access private
     * @static
     * @param  array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @access private
     * @static
     * @param  mixed  $row
     * @param  string $column
     * @return ?int
     */
    private static function columnInt(mixed $row, string $column): ?int
    {
        $data = self::rowArray($row);
        $value = ($data === null ? null : ($data[$column] ?? null));

        return (is_numeric($value) ? (int) $value : null);
    }

    /**
     * @access private
     * @static
     * @param  array<string, mixed> $row
     * @param  string               $column
     * @param  string               $default
     * @return string
     */
    private static function text(array $row, string $column, string $default = ''): string
    {
        $value = ($row[$column] ?? null);

        return (is_string($value) && $value !== '' ? $value : $default);
    }
}
