<?php

namespace StaticPHP\Utils\Models\Sessions;

use StaticPHP\Utils\Models\Migrations\Discovery;

/**
 * The sessions commands, driver agnostic.
 *
 * Every command returns a process exit code and writes through the $out callable rather
 * than echoing, so the whole surface is testable without capturing output buffers.
 */
class Commands
{
    private string $driver;

    /** @var callable */
    private $out;

    /**
     * @access public
     * @param string   $driver PDO driver name
     * @param callable $out    Receives one line at a time, without a trailing newline
     */
    public function __construct(string $driver, callable $out)
    {
        $this->driver = $driver;
        $this->out = $out;
    }

    /**
     * @access private
     * @param  string $line
     * @return void
     */
    private function line(string $line = ''): void
    {
        ($this->out)($line);
    }

    /**
     * Write the session schema into the application's migrations directory.
     *
     * Generated into the application's timeline rather than shipped as a framework
     * migration, for the reason set out in Audit\Commands::install(): Discovery sorts one
     * directory by filename and Tracker checksums what it applied, so a migration owned by
     * the package would sort by its release date and make every composer update look like
     * checksum drift on a file the application cannot edit.
     *
     * @access public
     * @param  string $migrationsDir Where the application keeps its .sql files
     * @param  string $filesDir      Where the shipped templates live
     * @param  int    $now           Unix timestamp for the filename
     * @return int
     */
    public function install(string $migrationsDir, string $filesDir, int $now): int
    {
        $template = rtrim($filesDir, '/') . "/install.{$this->driver}.sql";

        if (is_file($template) === false) {
            $this->line("error: no session table template for {$this->driver}");
            $this->line('Core only ships a database session handler for postgres. On the other');
            $this->line('drivers use one of the cache backed handlers instead.');

            return 2;
        }

        if (is_dir($migrationsDir) === false) {
            $this->line("error: no migrations directory at {$migrationsDir}");

            return 2;
        }

        $sql = @file_get_contents($template);
        if ($sql === false) {
            $this->line("error: could not read {$template}");

            return 1;
        }

        // Built by Discovery rather than by hand, because the name has to satisfy the
        // pattern that tool globs for.
        $target = rtrim($migrationsDir, '/') . '/' . Discovery::newFilename('create sessions', $now);

        if (is_file($target) === true) {
            $this->line("error: {$target} already exists");

            return 1;
        }

        if (@file_put_contents($target, $sql) === false) {
            $this->line("error: could not write {$target}");

            return 1;
        }

        $this->line("Wrote {$target}");
        $this->line('Review it, then: staticphp migrate apply');

        return 0;
    }
}
