<?php

namespace StaticPHP\Utils\Models\Queue;

use StaticPHP\Utils\Models\Migrations\Discovery;

/**
 * The queue commands, driver agnostic.
 *
 * Every command returns a process exit code and writes through the $out callable rather
 * than echoing, so the whole surface is testable without capturing output buffers.
 */
class Commands
{
    private QueueDatabase $queue;
    private string $driver;

    /** @var callable */
    private $out;

    /**
     * @access public
     * @param QueueDatabase $queue
     * @param string        $driver PDO driver name: pgsql, mysql or sqlite
     * @param callable      $out    Receives one line at a time, without a trailing newline
     */
    public function __construct(QueueDatabase $queue, string $driver, callable $out)
    {
        $this->queue = $queue;
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
     * Core does not ship a migration of its own, for the same reason the audit trail does
     * not: Discovery sorts by filename and Tracker checksums what it applied, so a
     * framework-owned file would sort by release date and turn every `composer update`
     * into checksum drift on a file the application cannot edit.
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

        // The failed table first: replacing "queue_jobs" first would leave the index names
        // derived from "queue_failed_jobs" half rewritten.
        if ($this->queue->failedTable() !== 'queue_failed_jobs') {
            $sql = str_replace('queue_failed_jobs', $this->queue->failedTable(), $sql);
        }

        if ($this->queue->table() !== 'queue_jobs') {
            $sql = str_replace('queue_jobs', $this->queue->table(), $sql);
        }

        // Built by Discovery rather than by hand, because the name has to satisfy the
        // pattern that tool globs for.
        $target = rtrim($migrationsDir, '/') . '/' . Discovery::newFilename('create queue tables', $now);

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
     * Run jobs.
     *
     * @access public
     * @param  list<string> $queues
     * @param  int          $timeout
     * @param  int          $sleep
     * @param  int          $maxJobs
     * @param  int          $maxTime
     * @param  int          $memoryLimit
     * @param  bool         $stopWhenEmpty
     * @return int
     */
    public function work(
        array $queues,
        int $timeout,
        int $sleep,
        int $maxJobs,
        int $maxTime,
        int $memoryLimit,
        bool $stopWhenEmpty
    ): int {
        $worker = new Worker($this->queue, $this->out);

        return $worker->run($queues, $timeout, $sleep, $maxJobs, $maxTime, $memoryLimit, $stopWhenEmpty);
    }

    /**
     * What is waiting, what is scheduled and what somebody is holding.
     *
     * @access public
     * @return int
     */
    public function status(): int
    {
        try {
            $rows = $this->queue->stats();
            $failed = $this->queue->failedCount();
        } catch (\Throwable $exception) {
            $this->line("error: cannot read the queue: {$exception->getMessage()}");

            return 1;
        }

        if ($rows === []) {
            $this->line('No jobs queued.');
        } else {
            $this->line(sprintf('%-24s %9s %9s %9s %9s', 'queue', 'pending', 'delayed', 'held', 'total'));

            foreach ($rows as $row) {
                $this->line(sprintf(
                    '%-24s %9d %9d %9d %9d',
                    $row['queue'],
                    $row['pending'],
                    $row['delayed'],
                    $row['reserved'],
                    $row['total']
                ));
            }
        }

        $this->line('');
        $this->line("failed: {$failed}");

        if ($failed > 0) {
            $this->line('Read them with: staticphp queue failed');
        }

        return 0;
    }

    /**
     * List recent failures.
     *
     * @access public
     * @param  int $limit
     * @return int
     */
    public function failed(int $limit): int
    {
        if ($limit < 1) {
            $this->line('error: --limit must be at least 1');

            return 2;
        }

        try {
            $rows = $this->queue->failedRows($limit);
        } catch (\Throwable $exception) {
            $this->line("error: cannot read {$this->queue->failedTable()}: {$exception->getMessage()}");

            return 1;
        }

        if ($rows === []) {
            $this->line('Nothing has failed.');

            return 0;
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                '#%-6s %-20s %-16s %s (%s attempts)',
                self::text($row, 'id'),
                self::text($row, 'failed_at'),
                self::text($row, 'queue'),
                self::text($row, 'name'),
                self::text($row, 'attempts', '0')
            ));

            // The first line of the error only. The trace is in the table for whoever wants
            // it, and printing it here would bury the list it is meant to summarise.
            $error = strtok(self::text($row, 'error'), "\n");
            if (is_string($error) === true) {
                $this->line("        {$error}");
            }
        }

        $this->line('');
        $this->line('Requeue one with: staticphp queue retry --id=N');

        return 0;
    }

    /**
     * Put failed jobs back on the queue.
     *
     * @access public
     * @param  ?int $id
     * @param  bool $all
     * @param  int  $maxAttempts
     * @return int
     */
    public function retry(?int $id, bool $all, int $maxAttempts): int
    {
        if ($id === null && $all === false) {
            $this->line('error: retry needs --id=N or --all');

            return 2;
        }

        try {
            $count = $this->queue->retryFailed($id, $maxAttempts);
        } catch (\Throwable $exception) {
            $this->line("error: could not requeue: {$exception->getMessage()}");

            return 1;
        }

        if ($count === 0) {
            $this->line('Nothing to requeue.');

            return ($id === null ? 0 : 1);
        }

        $this->line("Requeued {$count} " . ($count === 1 ? 'job' : 'jobs') . '.');

        return 0;
    }

    /**
     * Delete failed jobs.
     *
     * @access public
     * @param  ?int    $id
     * @param  bool    $all
     * @param  ?string $before YYYY-MM-DD or YYYY-MM-DD HH:MM:SS
     * @return int
     */
    public function forget(?int $id, bool $all, ?string $before): int
    {
        if ($id === null && $all === false && $before === null) {
            $this->line('error: forget needs --id=N, --before=YYYY-MM-DD or --all');

            return 2;
        }

        if ($before !== null && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $before) !== 1) {
            $this->line("error: --before must be YYYY-MM-DD or \"YYYY-MM-DD HH:MM:SS\", got \"{$before}\"");

            return 2;
        }

        try {
            $count = $this->queue->forgetFailed($id, $before);
        } catch (\Throwable $exception) {
            $this->line("error: could not delete: {$exception->getMessage()}");

            return 1;
        }

        $this->line("Deleted {$count} " . ($count === 1 ? 'row' : 'rows') . '.');

        return 0;
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

        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return (is_scalar($value) && $value !== '' ? (string) $value : $default);
    }
}
