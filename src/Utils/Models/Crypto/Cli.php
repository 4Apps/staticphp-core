<?php

namespace StaticPHP\Utils\Models\Crypto;

use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Db;

/**
 * The `staticphp crypto` command.
 *
 * Reached from ./staticphp before Bootstrap.php runs, for the same reason as the other
 * framework commands, and with more at stake than most: a controller that could be reached
 * over http would be one that prints key material.
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
    public const DESCRIPTION = 'Generate key material and re-encrypt stored columns';

    /**
     * @var string
     * @access public
     */
    public const USAGE = <<<TXT
    Usage: staticphp crypto <command> [options]

    Commands:
      key                 Print fresh key material for an environment variable
      rotate              Re-encrypt a column under the current key

    Options:
      --table=NAME        rotate: table holding the column
      --column=NAME       rotate: encrypted column
      --id=NAME           rotate: primary key to page through (default: id)
      --batch=N           rotate: rows read per statement (default: 500)
      --dry-run           rotate: report what would change, change nothing
      --connection=NAME   Entry of config['db']['pdo'] to use (default: default)
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
     * @param  string[] $arguments Everything after "crypto"
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

        // Handled before the bootstrap, and not merely before the database. Generating the
        // first key is what somebody does while setting an application up, and requiring
        // one to exist would make the key depend on the thing that needs the key.
        if ($command === 'key') {
            return (new Commands(null, self::out()))->key();
        }

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
            'dry-run' => false,
            'table' => null,
            'column' => null,
            'id' => null,
            'batch' => null,
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
     * Where the commands write their output.
     *
     * @access private
     * @static
     * @return callable(string): void
     */
    private static function out(): callable
    {
        return function (string $line = ''): void {
            echo $line . "\n";
        };
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

        if (self::pdoConfigs() === []) {
            Config::load(['Db'], 'Utils', 'staticphp');
        }

        if (empty(Config::$items['crypto'])) {
            Config::load(['Crypto'], 'Utils', 'staticphp');
        }

        return 0;
    }

    /**
     * Run the requested command.
     *
     * @access private
     * @static
     * @param  string $command
     * @param  array<string, bool|string|null> $options
     * @return int
     */
    private static function dispatch(string $command, array $options): int
    {
        if ($command !== 'rotate') {
            fwrite(STDERR, "error: unknown command \"{$command}\"\n\n");
            echo self::USAGE;

            return 2;
        }

        $table = self::optionString($options, 'table');
        $column = self::optionString($options, 'column');

        if ($table === '' || $column === '') {
            fwrite(STDERR, "error: rotate needs --table=NAME and --column=NAME\n");

            return 2;
        }

        $connectionName = self::optionString($options, 'connection', 'default');
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

        $batch = self::optionString($options, 'batch', '500');

        return (new Commands($pdo, self::out()))->rotate(
            $table,
            $column,
            self::optionString($options, 'id', 'id'),
            (is_numeric($batch) ? (int) $batch : 0),
            self::optionFlag($options, 'dry-run')
        );
    }
}
