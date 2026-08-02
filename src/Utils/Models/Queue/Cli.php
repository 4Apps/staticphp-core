<?php

namespace StaticPHP\Utils\Models\Queue;

use PDO;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Db;

/**
 * The `staticphp queue` command.
 *
 * Reached from ./staticphp before Bootstrap.php runs, for the same reason as the other
 * framework commands: `work` is a process that runs for hours, and a route that could
 * reach it would be a route that never returns.
 */
class Cli
{
    /**
     * One line for the command list in `staticphp --help`.
     *
     * Separate from USAGE because that opens with a synopsis and a table, and nothing
     * sensible can be lifted out of it - the entry point reads this constant when it is
     * there and leaves the column blank when it is not.
     *
     * @var string
     * @access public
     */
    public const DESCRIPTION = 'Run queued jobs, and inspect or retry the failures';

    /**
     * @var string
     * @access public
     */
    public const USAGE = <<<TXT
    Usage: staticphp queue <command> [options]

    Which backend these talk to is config['queue']['driver'], not an option here: a worker
    reading one queue while a push writes another is worse than either on its own.

    Commands:
      install             Write the queue schema into the migrations directory
      work                Run jobs until told to stop
      status              What is waiting, scheduled or held by a worker
      failed              List recent failures
      retry               Put failed jobs back on the queue
      forget              Delete failed jobs

    Work options:
      --queue=A,B         Queues to watch, in precedence order (default: config)
      --once              Run at most one job, then exit
      --stop-when-empty   Exit when the queue drains rather than waiting
      --max-jobs=N        Exit after N jobs
      --max-time=N        Exit after N seconds. For cron: --max-time=55
      --memory=N          Exit once the process is using N megabytes
      --sleep=N           Seconds to wait when there is nothing to do (default: config)
      --timeout=N         Visibility timeout, in seconds (default: config)

    Other options:
      --id=N              retry, forget: one job
      --all               retry, forget: every failed job
      --before=DATE       forget: failures older than YYYY-MM-DD
      --limit=N           failed: how many to list (default: 20)
      --dir=PATH          install: migrations directory
      --connection=NAME   Entry of config['db']['pdo'] to use (default: config)
      --project=NAME      Application under src/ to load config from (default: Application)
      --help              This text

    TXT;

    /**
     * Options that are flags rather than name=value pairs.
     *
     * @var string[]
     * @access private
     */
    private const FLAGS = ['once', 'stop-when-empty', 'all'];

    /**
     * Run the command.
     *
     * @access public
     * @static
     * @param  string[] $arguments Everything after "queue"
     * @param  string   $basePath  Repository root, the directory holding src/
     * @return int Process exit code
     */
    public static function run(array $arguments, string $basePath): int
    {
        if (PHP_SAPI !== 'cli') {
            return 1;
        }

        $parsed = self::parse($arguments);
        if (is_int($parsed) === true) {
            return $parsed;
        }

        [$command, , $options] = $parsed;

        $bootstrapped = self::bootstrap($basePath, self::optionString($options, 'project', 'Application'));
        if ($bootstrapped !== 0) {
            return $bootstrapped;
        }

        return self::dispatch($command, $options);
    }

    /**
     * Split arguments into command, positional arguments and options.
     *
     * @access private
     * @static
     * @param  string[] $arguments
     * @return array{0: string, 1: list<string>, 2: array<string, bool|string|null>}|int
     */
    private static function parse(array $arguments): array|int
    {
        $command = null;
        $positional = [];
        $options = [
            'once' => false,
            'stop-when-empty' => false,
            'all' => false,
            'queue' => null,
            'max-jobs' => null,
            'max-time' => null,
            'memory' => null,
            'sleep' => null,
            'timeout' => null,
            'id' => null,
            'before' => null,
            'limit' => null,
            'dir' => null,
            'connection' => null,
            'project' => 'Application',
        ];

        foreach ($arguments as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                echo self::USAGE;

                return 0;
            }

            if (str_starts_with($argument, '--') === false) {
                if ($command === null) {
                    $command = $argument;
                } else {
                    $positional[] = $argument;
                }

                continue;
            }

            $name = substr($argument, 2);
            $value = null;
            if (str_contains($name, '=') === true) {
                [$name, $value] = explode('=', $name, 2);
            }

            if (array_key_exists($name, $options) === false) {
                fwrite(STDERR, "error: unknown option --{$name}\n");

                return 2;
            }

            if (in_array($name, self::FLAGS, true) === true) {
                $options[$name] = true;
                continue;
            }

            if ($value === null || $value === '') {
                fwrite(STDERR, "error: --{$name} needs a value, as --{$name}=something\n");

                return 2;
            }

            $options[$name] = $value;
        }

        if ($command === null) {
            echo self::USAGE;

            return 2;
        }

        return [$command, $positional, $options];
    }

    /**
     * Define the framework paths, register the autoloader and load configuration.
     *
     * @access private
     * @static
     * @param  string $basePath
     * @param  string $project
     * @return int 0 on success, otherwise an exit code
     */
    private static function bootstrap(string $basePath, string $project): int
    {
        $projectPublic = "{$basePath}/src/{$project}/Public";
        if (is_dir($projectPublic) === false) {
            fwrite(STDERR, "error: project \"{$project}\" not found at {$projectPublic}\n");

            return 2;
        }

        if (defined('PUBLIC_PATH') === false) {
            define('PUBLIC_PATH', $projectPublic);
        }

        require_once \StaticPHP\Core\Bootstrap::AUTOLOAD;

        Config::load(['Config']);

        foreach ((array) Config::get('autoload_configs', []) as $item) {
            $parts = explode('/', (is_string($item) ? $item : ''));
            if (count($parts) === 3) {
                Config::load([$parts[2]], $parts[1], $parts[0]);
            } elseif (count($parts) === 2) {
                Config::load([$parts[1]], $parts[0]);
            } else {
                Config::load([$parts[0]]);
            }
        }

        // Framework defaults for anything the application did not load itself
        if (self::pdoConfigs() === []) {
            Config::load(['Db'], 'Utils', 'staticphp');
        }

        if (empty(Config::$items['queue'])) {
            Config::load(['Queue'], 'Utils', 'staticphp');
        }

        if (empty(Config::$items['migrations'])) {
            Config::load(['Migrations'], 'Utils', 'staticphp');
        }

        return 0;
    }

    /**
     * Connect and run the requested command.
     *
     * @access private
     * @static
     * @param  string $command
     * @param  array<string, bool|string|null> $options
     * @return int
     */
    private static function dispatch(string $command, array $options): int
    {
        $driver = Queue::settingString('driver', 'database');

        if ($driver === 'redis') {
            return self::dispatchRedis($command, $options);
        }

        if ($driver !== 'database') {
            fwrite(
                STDERR,
                "error: config['queue']['driver'] is \"{$driver}\"; it has to be \"database\" or \"redis\"\n"
            );

            return 2;
        }

        $connectionName = self::optionString(
            $options,
            'connection',
            Queue::settingString('connection', 'default')
        );

        $dbConfig = self::pdoConfigs()[$connectionName] ?? null;
        if (is_array($dbConfig) === false) {
            $known = implode(', ', array_keys(self::pdoConfigs()));
            fwrite(
                STDERR,
                "error: no database connection \"{$connectionName}\" in config['db']['pdo']"
                . ($known === '' ? "\n" : " (configured: {$known})\n")
            );

            return 2;
        }

        try {
            /** @var array<string, mixed> $dbConfig */
            $pdo = Db::init($connectionName, $dbConfig);
        } catch (\Throwable $exception) {
            fwrite(STDERR, "error: could not connect to \"{$connectionName}\": {$exception->getMessage()}\n");

            return 1;
        }

        try {
            $queue = new QueueDatabase(
                $connectionName,
                Queue::settingString('table', 'queue_jobs'),
                Queue::settingString('failed_table', 'queue_failed_jobs')
            );
        } catch (QueueError $error) {
            fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

            return 2;
        }

        // The commands work through the same driver the application pushes to, so a
        // misconfigured table name is reported once here rather than differently per command
        Queue::setDriver($queue);

        $pdoDriver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return self::execute(
            $command,
            new Commands($queue, (is_string($pdoDriver) ? $pdoDriver : ''), self::writer()),
            $options
        );
    }

    /**
     * The same commands, against redis, and without opening a database connection.
     *
     * A redis queue does not need one, and requiring it anyway would mean a worker host that
     * only runs jobs still had to be given database credentials it never uses.
     *
     * @access private
     * @static
     * @param  string $command
     * @param  array<string, bool|string|null> $options
     * @return int
     */
    private static function dispatchRedis(string $command, array $options): int
    {
        try {
            $queue = QueueRedis::connect(Queue::settingArray('redis'));
        } catch (QueueError $error) {
            fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");

            return 1;
        }

        Queue::setDriver($queue);

        return self::execute($command, new Commands($queue, '', self::writer()), $options);
    }

    /**
     * @access private
     * @static
     * @return callable(string): void
     */
    private static function writer(): callable
    {
        return function (string $line = ''): void {
            echo $line . "\n";
        };
    }

    /**
     * @access private
     * @static
     * @param  string   $command
     * @param  Commands $commands
     * @param  array<string, bool|string|null> $options
     * @return int
     */
    private static function execute(string $command, Commands $commands, array $options): int
    {
        switch ($command) {
            case 'install':
                $migrations = (array) Config::get('migrations', []);
                $migrationsDir = $migrations['dir'] ?? null;

                return $commands->install(
                    self::optionString(
                        $options,
                        'dir',
                        (is_string($migrationsDir) ? $migrationsDir : APP_PATH . '/Migrations')
                    ),
                    SP_PATH . '/Utils/Files/Queue',
                    time()
                );

            case 'work':
                return self::work($commands, $options);

            case 'status':
                return $commands->status();

            case 'failed':
                return $commands->failed(self::optionInt($options, 'limit', 20));

            case 'retry':
                return $commands->retry(
                    self::optionId($options),
                    self::optionFlag($options, 'all'),
                    Queue::settingInt('tries', 3)
                );

            case 'forget':
                return $commands->forget(
                    self::optionId($options),
                    self::optionFlag($options, 'all'),
                    (self::optionString($options, 'before') === ''
                        ? null
                        : self::optionString($options, 'before'))
                );

            default:
                fwrite(STDERR, "error: unknown command \"{$command}\"\n\n");
                echo self::USAGE;

                return 2;
        }
    }

    /**
     * Turn the work options into a worker run.
     *
     * @access private
     * @static
     * @param  Commands $commands
     * @param  array<string, bool|string|null> $options
     * @return int
     */
    private static function work(Commands $commands, array $options): int
    {
        $queues = [];
        foreach (explode(',', self::optionString($options, 'queue', Queue::settingString('queue', 'default'))) as $one) {
            $one = trim($one);
            if ($one !== '') {
                $queues[] = $one;
            }
        }

        $once = self::optionFlag($options, 'once');

        return $commands->work(
            ($queues === [] ? ['default'] : $queues),
            self::optionInt($options, 'timeout', Queue::settingInt('timeout', 300)),
            self::optionInt($options, 'sleep', Queue::settingInt('sleep', 1)),
            ($once === true ? 1 : self::optionInt($options, 'max-jobs', 0)),
            self::optionInt($options, 'max-time', 0),
            self::optionInt($options, 'memory', 0),
            ($once === true || self::optionFlag($options, 'stop-when-empty'))
        );
    }

    /**
     * The configured pdo connections, whatever Config happens to hold.
     *
     * @access private
     * @static
     * @return array<string, mixed>
     */
    private static function pdoConfigs(): array
    {
        $db = Config::$items['db'] ?? null;
        $pdo = (is_array($db) ? ($db['pdo'] ?? null) : null);

        return (is_array($pdo) ? $pdo : []);
    }

    /**
     * @access private
     * @static
     * @param  array<string, bool|string|null> $options
     * @param  string $key
     * @param  string $default
     * @return string
     */
    private static function optionString(array $options, string $key, string $default = ''): string
    {
        $value = $options[$key] ?? null;

        return (is_string($value) && $value !== '' ? $value : $default);
    }

    /**
     * @access private
     * @static
     * @param  array<string, bool|string|null> $options
     * @param  string $key
     * @param  int    $default
     * @return int
     */
    private static function optionInt(array $options, string $key, int $default): int
    {
        $value = $options[$key] ?? null;

        return (is_string($value) && is_numeric($value) ? (int) $value : $default);
    }

    /**
     * @access private
     * @static
     * @param  array<string, bool|string|null> $options
     * @return ?int
     */
    private static function optionId(array $options): ?int
    {
        $value = $options['id'] ?? null;

        return (is_string($value) && is_numeric($value) ? (int) $value : null);
    }

    /**
     * @access private
     * @static
     * @param  array<string, bool|string|null> $options
     * @param  string $key
     * @return bool
     */
    private static function optionFlag(array $options, string $key): bool
    {
        return ($options[$key] ?? false) === true;
    }
}
