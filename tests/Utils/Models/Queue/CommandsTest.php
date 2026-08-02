<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use StaticPHP\Utils\Models\Queue\Commands;
use StaticPHP\Utils\Models\Queue\Job;
use StaticPHP\Utils\Models\Queue\Queue;
use StaticPHP\Utils\Models\Queue\QueueDatabase;

class CommandsTest extends QueueCase
{
    /** @var list<string> */
    private array $lines = [];

    private string $dir;

    /**
     * 2026-08-03 00:00:00 UTC, so the generated filename is fixed rather than today's.
     *
     * @var int
     */
    private const NOW = 1785715200;

    protected function setUp(): void
    {
        parent::setUp();

        SpyHandler::reset();
        $this->lines = [];

        $this->dir = sys_get_temp_dir() . '/sp_queue_migrations_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            if (is_string($file) === true && is_file($file) === true) {
                unlink($file);
            }
        }

        if (is_dir($this->dir) === true) {
            rmdir($this->dir);
        }

        SpyHandler::reset();

        parent::tearDown();
    }

    /**
     * @param  string         $driver
     * @param  ?QueueDatabase $queue
     * @return Commands
     */
    private function commands(string $driver = 'sqlite', ?QueueDatabase $queue = null): Commands
    {
        return new Commands(
            ($queue ?? $this->queue),
            $driver,
            function (string $line = ''): void {
                $this->lines[] = $line;
            }
        );
    }

    /**
     * @return string
     */
    private function printed(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * @return string
     */
    private function templates(): string
    {
        return SP_PATH . '/Utils/Files/Queue';
    }

    /**
     * Fail a job so there is something in the failed table to talk about.
     *
     * @param  string $name
     * @return void
     */
    private function failOne(string $name = 'demo'): void
    {
        $this->queue->push($name, ['id' => 1], 0, 'reports', 0, null, 1);
        $job = $this->queue->reserve(['reports'], 60, 'worker-1');
        $this->assertInstanceOf(Job::class, $job);
        $this->queue->fail($job, "RuntimeException: it broke\n#0 somewhere");
    }

    public function testInstallWritesAMigrationTheMigrateCommandWillFind(): void
    {
        $code = $this->commands()->install($this->dir, $this->templates(), self::NOW);

        $this->assertSame(0, $code);

        $target = $this->dir . '/2026-08-03-000000-create-queue-tables.sql';
        $this->assertFileExists($target);

        $name = basename($target);
        $this->assertMatchesRegularExpression('/^(\d{4}-\d{2}-\d{2}-\d{6})-([a-z0-9-]+)\.sql$/', $name);

        $sql = (string) file_get_contents($target);
        $this->assertStringContainsString('CREATE TABLE queue_jobs', $sql);
        $this->assertStringContainsString('CREATE TABLE queue_failed_jobs', $sql);
        $this->assertStringContainsString('staticphp migrate apply', $this->printed());
    }

    public function testInstallRenamesBothTablesWhenTheyAreConfigured(): void
    {
        $renamed = new QueueDatabase($this->connection, 'bg_jobs', 'bg_dead_jobs');

        $this->assertSame(0, $this->commands('sqlite', $renamed)->install($this->dir, $this->templates(), self::NOW));

        $sql = (string) file_get_contents($this->dir . '/2026-08-03-000000-create-queue-tables.sql');

        $this->assertStringContainsString('CREATE TABLE bg_jobs', $sql);
        $this->assertStringContainsString('CREATE TABLE bg_dead_jobs', $sql);
        $this->assertStringNotContainsString('queue_jobs', $sql);
        $this->assertStringNotContainsString('queue_failed_jobs', $sql);

        // The failed table has to be substituted first, or its index names come out of the
        // rename half rewritten
        $this->assertStringContainsString('idx_bg_dead_jobs_failed_at', $sql);
    }

    public function testInstallSaysSoWhenThereIsNoTemplateForTheDriver(): void
    {
        $this->assertSame(2, $this->commands('oracle')->install($this->dir, $this->templates(), self::NOW));
        $this->assertStringContainsString('no install template for oracle', $this->printed());
    }

    public function testInstallSaysSoWhenTheMigrationsDirectoryIsMissing(): void
    {
        $this->assertSame(2, $this->commands()->install($this->dir . '/nope', $this->templates(), self::NOW));
        $this->assertStringContainsString('no migrations directory', $this->printed());
    }

    public function testInstallWillNotOverwriteWhatIsAlreadyThere(): void
    {
        $this->assertSame(0, $this->commands()->install($this->dir, $this->templates(), self::NOW));
        $this->assertSame(1, $this->commands()->install($this->dir, $this->templates(), self::NOW));
        $this->assertStringContainsString('already exists', $this->printed());
    }

    public function testStatusSplitsTheBacklogPerQueue(): void
    {
        $this->queue->push('a', [], 0, 'default', 0, null, 3);
        $this->queue->push('b', [], 3600, 'mail', 0, null, 3);

        $this->assertSame(0, $this->commands()->status());

        $output = $this->printed();
        $this->assertStringContainsString('pending', $output);
        $this->assertStringContainsString('default', $output);
        $this->assertStringContainsString('mail', $output);
        $this->assertStringContainsString('failed: 0', $output);
    }

    public function testStatusOnAQueueWithNothingInIt(): void
    {
        $this->assertSame(0, $this->commands()->status());
        $this->assertStringContainsString('No jobs queued.', $this->printed());
    }

    public function testFailedListsWhatBrokeAndHowToPutItBack(): void
    {
        $this->failOne('SendInvoice');

        $this->assertSame(0, $this->commands()->failed(20));

        $output = $this->printed();
        $this->assertStringContainsString('SendInvoice', $output);
        $this->assertStringContainsString('reports', $output);
        $this->assertStringContainsString('RuntimeException: it broke', $output);
        $this->assertStringNotContainsString('#0 somewhere', $output, 'the trace stays in the table');
        $this->assertStringContainsString('staticphp queue retry --id=', $output);
    }

    public function testFailedOnAnEmptyTable(): void
    {
        $this->assertSame(0, $this->commands()->failed(20));
        $this->assertStringContainsString('Nothing has failed.', $this->printed());
    }

    public function testFailedRejectsANonsenseLimit(): void
    {
        $this->assertSame(2, $this->commands()->failed(0));
        $this->assertStringContainsString('--limit must be at least 1', $this->printed());
    }

    public function testRetryNeedsToBeToldWhatToPutBack(): void
    {
        $this->assertSame(2, $this->commands()->retry(null, false, 3));
        $this->assertStringContainsString('--id=N or --all', $this->printed());
    }

    public function testRetryPutsEverythingBackOnTheQueue(): void
    {
        $this->failOne();

        $this->assertSame(0, $this->commands()->retry(null, true, 3));
        $this->assertStringContainsString('Requeued 1 job.', $this->printed());
        $this->assertCount(1, $this->rows());
        $this->assertSame([], $this->rows('queue_failed_jobs'));
    }

    public function testRetryOfAnIdThatIsNotThereSaysSo(): void
    {
        $this->assertSame(1, $this->commands()->retry(999, false, 3));
        $this->assertStringContainsString('Nothing to requeue.', $this->printed());
    }

    public function testForgetNeedsToBeToldWhatToDelete(): void
    {
        $this->assertSame(2, $this->commands()->forget(null, false, null));
        $this->assertStringContainsString('--id=N, --before=YYYY-MM-DD or --all', $this->printed());
    }

    public function testForgetRejectsADateItCannotRead(): void
    {
        $this->assertSame(2, $this->commands()->forget(null, false, 'last tuesday'));
        $this->assertStringContainsString('--before must be YYYY-MM-DD', $this->printed());
    }

    public function testForgetDeletesTheFailedRows(): void
    {
        $this->failOne();

        $this->assertSame(0, $this->commands()->forget(null, true, null));
        $this->assertStringContainsString('Deleted 1 row.', $this->printed());
        $this->assertSame([], $this->rows('queue_failed_jobs'));
    }

    public function testWorkDrainsTheQueue(): void
    {
        Queue::push(SpyHandler::class, ['n' => 1]);
        Queue::push(SpyHandler::class, ['n' => 2]);

        $code = $this->commands()->work(['default'], 60, 0, 0, 0, 0, true);

        $this->assertSame(0, $code);
        $this->assertCount(2, SpyHandler::$calls);
        $this->assertSame([], $this->rows());
    }
}
