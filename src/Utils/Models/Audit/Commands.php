<?php

namespace StaticPHP\Utils\Models\Audit;

use PDO;
use StaticPHP\Utils\Models\Migrations\Discovery;

/**
 * The audit commands, driver agnostic.
 *
 * Every command returns a process exit code and writes through the $out callable rather
 * than echoing, so the whole surface is testable without capturing output buffers.
 */
class Commands
{
    private PDO $pdo;
    private string $driver;

    /** @var callable */
    private $out;

    /**
     * @access public
     * @param PDO      $pdo
     * @param string   $driver PDO driver name: pgsql, mysql or sqlite
     * @param callable $out    Receives one line at a time, without a trailing newline
     */
    public function __construct(PDO $pdo, string $driver, callable $out)
    {
        $this->pdo = $pdo;
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
     * Write the schema into the application's migrations directory.
     *
     * Core does not ship a migration of its own and does not create the table at runtime.
     * Discovery globs one directory and sorts by filename, and Tracker checksums every file
     * it applied, so a framework-owned migration would sort by the day the package was
     * released and turn every `composer update` into checksum drift on a file the
     * application cannot edit. Generating one hands it over instead: it is the
     * application's file, in the application's timeline, and it can be edited before it is
     * applied.
     *
     * @access public
     * @param  string $migrationsDir Where the application keeps its .sql files
     * @param  string $filesDir      Where the shipped templates live
     * @param  string $table         Table to create, if not the default
     * @param  int    $now           Unix timestamp for the filename
     * @return int
     */
    public function install(string $migrationsDir, string $filesDir, string $table, int $now): int
    {
        $template = rtrim($filesDir, '/') . "/install.{$this->driver}.sql";

        if (is_file($template) === false) {
            $this->line("error: no install template for {$this->driver}");

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

        // Index names are derived from the table name in every template, so renaming the
        // table renames them too and two trails can coexist in one schema.
        if ($table !== 'audit_log') {
            Store::assertTableName($table);
            $sql = str_replace('audit_log', $table, $sql);
        }

        // Built by Discovery rather than by hand, because the name has to satisfy the
        // pattern that tool globs for. Spelling it here means an underscore in the table
        // name produces a file `migrate` then refuses to read.
        $target = rtrim($migrationsDir, '/') . '/' . Discovery::newFilename("create {$table}", $now);

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

    /**
     * Delete trail rows older than a date.
     *
     * In batches, because a single unbounded DELETE against the biggest table in the
     * database is how a retention job becomes an outage.
     *
     * @access public
     * @param  string $table
     * @param  string $before YYYY-MM-DD, or YYYY-MM-DD HH:MM:SS
     * @param  int    $batch  Rows per statement
     * @param  bool   $dryRun Count and report, delete nothing
     * @return int
     */
    public function prune(string $table, string $before, int $batch, bool $dryRun): int
    {
        try {
            Store::assertTableName($table);
        } catch (AuditError $error) {
            $this->line('error: ' . $error->getMessage());

            return 2;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $before) !== 1) {
            $this->line("error: --before must be YYYY-MM-DD or \"YYYY-MM-DD HH:MM:SS\", got \"{$before}\"");

            return 2;
        }

        if ($batch < 1) {
            $this->line('error: --batch must be at least 1');

            return 2;
        }

        $total = $this->countBefore($table, $before);
        if ($total === null) {
            return 1;
        }

        $this->line("{$total} rows in {$table} older than {$before}");

        if ($this->partitioned($table) === true) {
            $this->line('');
            $this->line("{$table} is partitioned. Dropping whole partitions is instant where");
            $this->line('this delete is not:');
            $this->line("  ALTER TABLE {$table} DETACH PARTITION <name>;");
            $this->line('  DROP TABLE <name>;');
        }

        if ($total === 0 || $dryRun === true) {
            if ($dryRun === true && $total > 0) {
                $this->line('');
                $this->line('Nothing deleted (--dry-run).');
            }

            return 0;
        }

        $deleted = 0;
        while ($deleted < $total) {
            // The inner select is wrapped a second time because mysql refuses to read the
            // table it is deleting from in a subquery, and a derived table is the way round
            // it that the other two do not mind.
            $statement = $this->pdo->prepare(
                "DELETE FROM {$table} WHERE id IN ("
                . " SELECT id FROM (SELECT id FROM {$table} WHERE created_at < ? ORDER BY id LIMIT {$batch}) AS batch"
                . ')'
            );
            $statement->execute([$before]);

            $removed = $statement->rowCount();
            if ($removed < 1) {
                break;
            }

            $deleted += $removed;
            $this->line("Deleted {$deleted}/{$total}");
        }

        $this->line("Done. {$deleted} rows removed.");

        return 0;
    }

    /**
     * @access private
     * @param  string $table
     * @param  string $before
     * @return ?int Null when the table cannot be read
     */
    private function countBefore(string $table, string $before): ?int
    {
        try {
            $statement = $this->pdo->prepare("SELECT COUNT(*) AS total FROM {$table} WHERE created_at < ?");
            $statement->execute([$before]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $exception) {
            $this->line("error: cannot read {$table}: {$exception->getMessage()}");

            return null;
        }

        $total = (is_array($row) ? ($row['total'] ?? 0) : 0);

        return (is_numeric($total) ? (int) $total : 0);
    }

    /**
     * Whether this is a partitioned parent table.
     *
     * Only postgres is asked, because it is the only one of the three where the answer
     * changes the advice.
     *
     * @access private
     * @param  string $table
     * @return bool
     */
    private function partitioned(string $table): bool
    {
        if ($this->driver !== 'pgsql') {
            return false;
        }

        try {
            $statement = $this->pdo->prepare('SELECT relkind FROM pg_class WHERE oid = to_regclass(?)');
            $statement->execute([$table]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return false;
        }

        return (is_array($row) && ($row['relkind'] ?? null) === 'p');
    }
}
