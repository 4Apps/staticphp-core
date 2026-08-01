<?php

namespace StaticPHP\Utils\Models\Audit;

use PDO;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Db;

/**
 * The `staticphp audit` command.
 *
 * Reached from ./staticphp before Bootstrap.php runs, for the same reason as migrate and
 * i18n: routing config has no notion of a cli-only route, so a controller would also answer
 * over http - here on a tool that writes schema and deletes history.
 */
class Cli
{
    /**
     * @var string
     * @access public
     */
    public const USAGE = <<<TXT
    Usage: staticphp audit <command> [options]

    Commands:
      install             Write the audit schema into the migrations directory
      prune               Delete trail rows older than a date

    Options:
      --before=DATE       prune: delete rows older than this, YYYY-MM-DD
      --batch=N           prune: rows per statement (default: 10000)
      --dry-run           prune: count what would go, delete nothing
      --dir=PATH          install: migrations directory to write into
      --table=NAME        Audit table (default: from config)
      --connection=NAME   Entry of config['db']['pdo'] to use (default: from config)
      --project=NAME      Application under src/ to load config from (default: Application)
      --help              This text

    TXT;

    /**
     * Options that are flags rather than name=value pairs.
     *
     * @var string[]
     * @access private
     */
    private const FLAGS = ['dry-run'];

    /**
     * Run the command.
     *
     * @access public
     * @static
     * @param  string[] $arguments Everything after "audit"
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
     *         The triple, or an exit code when there is nothing to run
     */
    private static function parse(array $arguments): array|int
    {
        $command = null;
        $positional = [];
        $options = [
            'dry-run' => false,
            'before' => null,
            'batch' => null,
            'dir' => null,
            'table' => null,
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
     * The audit settings, whatever Config happens to hold.
     *
     * @access private
     * @static
     * @return array<string, mixed>
     */
    private static function auditConfig(): array
    {
        $audit = Config::$items['audit'] ?? null;

        return (is_array($audit) ? $audit : []);
    }

    /**
     * A command line option as a string.
     *
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
     * A command line flag.
     *
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

        if (self::auditConfig() === []) {
            Config::load(['Audit'], 'Utils', 'staticphp');
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
        $audit = self::auditConfig();

        $configured = $audit['connection'] ?? null;
        $connectionName = self::optionString(
            $options,
            'connection',
            (is_string($configured) ? $configured : 'default')
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

        $table = self::table($options, $audit);
        if ($table === null) {
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
        $commands = new Commands($pdo, (is_string($driver) ? $driver : ''), function (string $line = ''): void {
            echo $line . "\n";
        });

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
                    SP_PATH . '/Utils/Files/Audit',
                    $table,
                    time()
                );

            case 'prune':
                $before = self::optionString($options, 'before');
                if ($before === '') {
                    fwrite(STDERR, "error: prune needs --before=YYYY-MM-DD\n");

                    return 2;
                }

                $batch = self::optionString($options, 'batch', '10000');

                return $commands->prune(
                    $table,
                    $before,
                    (is_numeric($batch) ? (int) $batch : 0),
                    self::optionFlag($options, 'dry-run')
                );

            default:
                fwrite(STDERR, "error: unknown command \"{$command}\"\n\n");
                echo self::USAGE;

                return 2;
        }
    }

    /**
     * Which table the command operates on.
     *
     * A deployment that splits the trail configures a callable, which answers per event and
     * so cannot answer here. Those installations name the table on the command line, one
     * run per table.
     *
     * @access private
     * @static
     * @param  array<string, bool|string|null> $options
     * @param  array<string, mixed>            $audit
     * @return ?string Null when it cannot be decided, having said why
     */
    private static function table(array $options, array $audit): ?string
    {
        $override = self::optionString($options, 'table');
        if ($override !== '') {
            return $override;
        }

        $configured = $audit['table'] ?? null;
        if (is_string($configured) === true && $configured !== '') {
            return $configured;
        }

        if ($configured === null) {
            return 'audit_log';
        }

        fwrite(
            STDERR,
            "error: config['audit']['table'] is a resolver, so it names a different table per event.\n"
            . "Name the one to work on with --table=NAME.\n"
        );

        return null;
    }
}
