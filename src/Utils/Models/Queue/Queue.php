<?php

namespace StaticPHP\Utils\Models\Queue;

use StaticPHP\Core\Models\Config;

/**
 * The job queue.
 *
 * Nothing here runs a job. Pushing is what the application does, running is what
 * `staticphp queue work` does in a process of its own, and the only thing joining them is
 * a table. That separation is the point: the request returns as soon as the row is
 * committed, and the work happens somewhere a timeout cannot reach it.
 *
 * The database backend makes the push part of the caller's transaction, which is the whole
 * argument for keeping jobs in the same database as the work that causes them:
 *
 *     Db::transaction(function () use ($invoice) {
 *         Db::insert('invoices', $invoice);
 *         Queue::push(SendInvoice::class, ['id' => Db::lastInsertId()]);
 *     });
 *
 * Either the invoice and its email both exist or neither does. No other backend can offer
 * that, because no other backend is the same database.
 *
 * @example Queue::push(SendInvoice::class, ['id' => 42], delay: 60, queue: 'mail');
 */
class Queue
{
    /**
     * Defaults for anything config['queue'] does not say, so the queue works before an
     * application has written a config file for it.
     *
     * @var array<string, mixed>
     * @access private
     */
    private const DEFAULTS = [
        'driver' => 'database',
        'connection' => 'default',
        'table' => 'queue_jobs',
        'failed_table' => 'queue_failed_jobs',
        'redis' => [],
        'queue' => 'default',
        'tries' => 3,
        'backoff' => [10, 60, 300],
        'timeout' => 300,
        'sleep' => 1,
        'handlers' => [],
    ];

    private static ?QueueInterface $driver = null;

    /** @var ?array<string, mixed> */
    private static ?array $settings = null;

    /**
     * Queue a job.
     *
     * Returns the job id, or the id of the job already holding $unique. Safe to call inside
     * a transaction, and on the database backend that is how it should be called.
     *
     * @access public
     * @static
     * @param  string               $name     Handler class, or a key of config['queue']['handlers']
     * @param  array<string, mixed> $payload  Identifiers and arguments, not objects
     * @param  int                  $delay    Seconds before it may run
     * @param  ?string              $queue    Null uses config['queue']['queue']
     * @param  int                  $priority Higher runs first
     * @param  ?string              $unique   At most one pending job per key, when set
     * @param  ?int                 $tries    Null uses config['queue']['tries']
     * @return int
     */
    public static function push(
        string $name,
        array $payload = [],
        int $delay = 0,
        ?string $queue = null,
        int $priority = 0,
        ?string $unique = null,
        ?int $tries = null
    ): int {
        // Checked here rather than left for the worker, because a name that resolves to
        // nothing fails on every attempt and then sits in the failed table. Better to know
        // at the call site, while there is still a stack trace worth reading.
        self::assertResolvable($name);

        return self::driver()->push(
            $name,
            $payload,
            $delay,
            ($queue ?? self::settingString('queue', 'default')),
            $priority,
            $unique,
            ($tries ?? self::settingInt('tries', 3))
        );
    }

    /**
     * How many jobs could be picked up right now.
     *
     * @access public
     * @static
     * @param  ?string $queue
     * @return int
     */
    public static function pending(?string $queue = null): int
    {
        return self::driver()->pending($queue);
    }

    /**
     * The configured backend.
     *
     * @access public
     * @static
     * @return QueueInterface
     * @throws QueueError
     */
    public static function driver(): QueueInterface
    {
        if (self::$driver === null) {
            self::$driver = self::build(self::settingString('driver', 'database'));
        }

        return self::$driver;
    }

    /**
     * @access public
     * @static
     * @param  string $driver
     * @return QueueInterface
     * @throws QueueError
     */
    public static function build(string $driver): QueueInterface
    {
        return match ($driver) {
            'database' => new QueueDatabase(
                self::settingString('connection', 'default'),
                self::settingString('table', 'queue_jobs'),
                self::settingString('failed_table', 'queue_failed_jobs')
            ),
            'redis' => QueueRedis::connect(self::settingArray('redis')),
            default => throw new QueueError(
                "config['queue']['driver'] is \"{$driver}\"; it has to be \"database\" or \"redis\""
            ),
        };
    }

    /**
     * Replace the backend, for tests and for an application that assembles its own.
     *
     * @access public
     * @static
     * @param  ?QueueInterface $driver
     * @return void
     */
    public static function setDriver(?QueueInterface $driver): void
    {
        self::$driver = $driver;
    }

    /**
     * Forget the cached settings and backend.
     *
     * @access public
     * @static
     * @return void
     */
    public static function reset(): void
    {
        self::$driver = null;
        self::$settings = null;
    }

    /**
     * Build the handler for a job name.
     *
     * @access public
     * @static
     * @param  string $name
     * @return Handler
     * @throws QueueError
     */
    public static function handler(string $name): Handler
    {
        $configured = self::handlers()[$name] ?? null;

        // A callable in config builds the handler itself, which is how a handler with
        // constructor dependencies gets them without the queue knowing what they are.
        if (is_callable($configured) === true) {
            $handler = $configured();
            if ($handler instanceof Handler) {
                return $handler;
            }

            throw new QueueError("config['queue']['handlers']['{$name}'] did not return a " . Handler::class);
        }

        $class = (is_string($configured) && $configured !== '' ? $configured : $name);

        if (class_exists($class) === false) {
            throw new QueueError("No handler \"{$name}\": {$class} does not exist");
        }

        if (is_subclass_of($class, Handler::class) === false) {
            throw new QueueError("{$class} does not implement " . Handler::class);
        }

        return new $class();
    }

    /**
     * How long to wait before attempt $attempt + 1.
     *
     * A list gives one delay per attempt and repeats its last entry afterwards, so
     * [10, 60, 300] retries after ten seconds, then a minute, then five minutes for as long
     * as the attempts last. A plain integer is the same delay every time.
     *
     * @access public
     * @static
     * @param  int $attempt Attempts made so far
     * @return int Seconds
     */
    public static function backoff(int $attempt): int
    {
        $backoff = self::setting('backoff');

        if (is_array($backoff) === true) {
            $steps = array_values($backoff);
            if ($steps === []) {
                return 0;
            }

            $step = ($steps[max(0, $attempt - 1)] ?? $steps[count($steps) - 1]);

            return (is_numeric($step) ? max(0, (int) $step) : 0);
        }

        return (is_numeric($backoff) ? max(0, (int) $backoff) : 0);
    }

    /**
     * @access public
     * @static
     * @return array<string, mixed>
     */
    public static function handlers(): array
    {
        return self::settingArray('handlers');
    }

    /**
     * A settings entry that is itself a block of named settings.
     *
     * @access public
     * @static
     * @param  string $key
     * @return array<string, mixed>
     */
    public static function settingArray(string $key): array
    {
        $value = self::setting($key);
        if (is_array($value) === false) {
            return [];
        }

        $out = [];
        foreach ($value as $name => $entry) {
            if (is_string($name) === true) {
                $out[$name] = $entry;
            }
        }

        return $out;
    }

    /**
     * @access public
     * @static
     * @param  string $key
     * @return mixed
     */
    public static function setting(string $key): mixed
    {
        if (self::$settings === null) {
            $configured = Config::$items['queue'] ?? null;
            $settings = (is_array($configured) ? $configured : []);

            $merged = self::DEFAULTS;
            foreach ($settings as $name => $value) {
                if (is_string($name) === true) {
                    $merged[$name] = $value;
                }
            }

            self::$settings = $merged;
        }

        return (self::$settings[$key] ?? null);
    }

    /**
     * @access public
     * @static
     * @param  string $key
     * @param  string $default
     * @return string
     */
    public static function settingString(string $key, string $default = ''): string
    {
        $value = self::setting($key);

        return (is_string($value) && $value !== '' ? $value : $default);
    }

    /**
     * @access public
     * @static
     * @param  string $key
     * @param  int    $default
     * @return int
     */
    public static function settingInt(string $key, int $default = 0): int
    {
        $value = self::setting($key);

        return (is_numeric($value) ? (int) $value : $default);
    }

    /**
     * Refuse a job name nothing can run.
     *
     * @access private
     * @static
     * @param  string $name
     * @return void
     * @throws QueueError
     */
    private static function assertResolvable(string $name): void
    {
        if ($name === '') {
            throw new QueueError('A job needs a handler name');
        }

        if (array_key_exists($name, self::handlers()) === true) {
            return;
        }

        if (class_exists($name) === false) {
            throw new QueueError(
                "Cannot queue \"{$name}\": no such class, and no config['queue']['handlers'] entry for it"
            );
        }

        if (is_subclass_of($name, Handler::class) === false) {
            throw new QueueError("Cannot queue \"{$name}\": it does not implement " . Handler::class);
        }
    }
}
