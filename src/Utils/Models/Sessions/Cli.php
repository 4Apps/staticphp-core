<?php

namespace StaticPHP\Utils\Models\Sessions;

use PDO;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Db;

/**
 * The `staticphp sessions` command.
 *
 * Reached from ./staticphp before Bootstrap.php runs, for the same reason as migrate, i18n
 * and audit: routing config has no notion of a cli-only route, so a controller would also
 * answer over http - here on a tool that writes schema.
 */
class Cli
{
    /**
     * @var string
     * @access public
     */
    public const USAGE = <<<TXT
    Usage: staticphp sessions <command> [options]

    Commands:
      install             Write the session table into the migrations directory

    Options:
      --dir=PATH          install: migrations directory to write into
      --connection=NAME   Entry of config['db']['pdo'] to use (default: sessions)
      --project=NAME      Application under src/ to load config from (default: Application)
      --help              This text

    TXT;

    /**
     * Run the command.
     *
     * @access public
     * @static
     * @param  string[] $arguments Everything after "sessions"
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
     * @return array{0: string, 1: list<string>, 2: array<string, string|null>}|int
     *         The triple, or an exit code when there is nothing to run
     */
    private static function parse(array $arguments): array|int
    {
        $command = null;
        $positional = [];
        $options = [
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
     * A command line option as a string.
     *
     * @access private
     * @static
     * @param  array<string, string|null> $options
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
     * @param  array<string, string|null> $options
     * @return int
     */
    private static function dispatch(string $command, array $options): int
    {
        if ($command !== 'install') {
            fwrite(STDERR, "error: unknown command \"{$command}\"\n\n");
            echo self::USAGE;

            return 2;
        }

        $connectionName = self::optionString($options, 'connection', self::defaultConnection());

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

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $commands = new Commands((is_string($driver) ? $driver : ''), function (string $line = ''): void {
            echo $line . "\n";
        });

        $migrations = (array) Config::get('migrations', []);
        $migrationsDir = $migrations['dir'] ?? null;

        return $commands->install(
            self::optionString(
                $options,
                'dir',
                (is_string($migrationsDir) ? $migrationsDir : APP_PATH . '/Migrations')
            ),
            SP_PATH . '/Utils/Files/Sessions',
            time()
        );
    }

    /**
     * Which connection to reach for when none was named.
     *
     * SessionsPgsql defaults to a connection called "sessions" so that session traffic can
     * be pointed at its own database, but plenty of applications never set one up and keep
     * sessions alongside everything else.
     *
     * @access private
     * @static
     * @return string
     */
    private static function defaultConnection(): string
    {
        $configs = self::pdoConfigs();

        return (isset($configs['sessions']) ? 'sessions' : 'default');
    }
}
