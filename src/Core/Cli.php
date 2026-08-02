<?php

namespace StaticPHP\Core;

/**
 * The command line tools the framework ships.
 *
 * `./staticphp` in the skeleton dispatches these before Helpers/Bootstrap.php runs, because
 * routing config has no notion of a cli-only route: reached through a controller these
 * would also answer over http, on tools whose job is to change the schema, delete
 * translation keys and delete history.
 *
 * The map lives here rather than in the skeleton so that adding a framework command does
 * not need a matching skeleton release. The skeleton merges its own over the top, so an
 * application can replace one of these with its own:
 *
 *     $cliCommands = $localCommands + StaticPHP\Core\Cli::commands();
 */
final class Cli
{
    /**
     * Command name to the class that runs it.
     *
     * Every entry answers `run(array $arguments, string $basePath): int`, where $arguments
     * is everything after the command name and the return value is the process exit code.
     *
     * @access public
     * @static
     * @return array<string, class-string>
     */
    public static function commands(): array
    {
        return [
            'migrate' => \StaticPHP\Utils\Models\Migrations\Cli::class,
            'i18n' => \StaticPHP\Utils\Models\Translation\Cli::class,
            'audit' => \StaticPHP\Utils\Models\Audit\Cli::class,
            'sessions' => \StaticPHP\Utils\Models\Sessions\Cli::class,
            'queue' => \StaticPHP\Utils\Models\Queue\Cli::class,
            'crypto' => \StaticPHP\Utils\Models\Crypto\Cli::class,
            'doctor' => \StaticPHP\Utils\Models\Doctor\Cli::class,
        ];
    }
}
