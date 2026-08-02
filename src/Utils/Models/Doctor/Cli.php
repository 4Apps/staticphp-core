<?php

namespace StaticPHP\Utils\Models\Doctor;

use StaticPHP\Core\Models\Config;

/**
 * The `staticphp doctor` command.
 *
 * Reached from ./staticphp before Bootstrap.php runs, like the other framework commands.
 * Here the reason is also practical: doctor exists to be run when the application does not
 * boot, so it must not need the application to boot first.
 */
class Cli
{
    /**
     * @var string
     * @access public
     */
    public const USAGE = <<<TXT
    Usage: staticphp doctor [options]

    Checks php, extensions, database connections, migration state, the cache
    directory and the audit table. Reads only - nothing is created or applied.

    Options:
      --offline           Skip anything that opens a connection
      --strict            Exit non-zero on warnings as well as failures
      --project=NAME      Application under src/ to load config from (default: Application)
      --help              This text

    Exit codes: 0 all clear, 1 something failed, 2 the command could not run.

    TXT;

    /**
     * Run the command.
     *
     * @access public
     * @static
     * @param  string[] $arguments Everything after "doctor"
     * @param  string   $basePath  Repository root, the directory holding src/
     * @return int Process exit code
     */
    public static function run(array $arguments, string $basePath): int
    {
        if (PHP_SAPI !== 'cli') {
            return 1;
        }

        $project = 'Application';
        $offline = false;
        $strict = false;

        foreach ($arguments as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                echo self::USAGE;

                return 0;
            }

            $name = (str_starts_with($argument, '--') === true ? substr($argument, 2) : $argument);
            $value = null;
            if (str_contains($name, '=') === true) {
                [$name, $value] = explode('=', $name, 2);
            }

            switch ($name) {
                case 'offline':
                    $offline = true;
                    break;

                case 'strict':
                    $strict = true;
                    break;

                case 'project':
                    if ($value === null || $value === '') {
                        fwrite(STDERR, "error: --project needs a value, as --project=Name\n");

                        return 2;
                    }

                    $project = $value;
                    break;

                default:
                    fwrite(STDERR, "error: unknown option {$argument}\n");

                    return 2;
            }
        }

        $bootstrapped = self::bootstrap($basePath, $project);
        if ($bootstrapped !== 0) {
            return $bootstrapped;
        }

        $commands = new Commands(Config::$items, function (string $line = ''): void {
            echo $line . "\n";
        }, $offline);

        return $commands->run($strict);
    }

    /**
     * Define the framework paths, register the autoloader and load configuration.
     *
     * Framework defaults are deliberately not filled in here, unlike in the other commands.
     * Doctor reports on the application's configuration, and quietly loading the package's
     * own Db.php underneath would have it check a connection nobody configured.
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

        return 0;
    }
}
