<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use StaticPHP\Utils\Models\Queue\Commands;
use StaticPHP\Utils\Models\Queue\Queue;

/**
 * `staticphp queue status`, `failed`, `retry` and `forget` against redis.
 *
 * The commands were written for the database driver and know nothing about either. That is
 * the thing being checked: the same code prints the same tables when what is underneath it
 * is a stream.
 */
class QueueRedisCommandsTest extends QueueRedisCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->lines = [];
    }

    public function testStatusPrintsTheBacklog(): void
    {
        $this->queue->push('A', [], 0, 'default', 0, null, 3);
        $this->queue->push('B', [], 3600, 'default', 0, null, 3);
        $this->queue->push('C', [], 0, 'urgent', 0, null, 3);
        $this->queue->reserve(['urgent'], 30, 'worker-1');

        $this->assertSame(0, $this->commands()->status());

        $printed = $this->printed();
        $this->assertStringContainsString('queue', $printed);
        $this->assertMatchesRegularExpression('/^default\s+1\s+1\s+0\s+2$/m', $printed);
        $this->assertMatchesRegularExpression('/^urgent\s+0\s+0\s+1\s+1$/m', $printed);
        $this->assertStringContainsString('failed: 0', $printed);
    }

    public function testStatusSaysSoWhenThereIsNothing(): void
    {
        $this->assertSame(0, $this->commands()->status());

        $this->assertStringContainsString('No jobs queued.', $this->printed());
    }

    public function testFailedListsWhatBroke(): void
    {
        $id = $this->failOne('Importer', "Connection refused\n#0 somewhere");

        $this->assertSame(0, $this->commands()->failed(20));

        $printed = $this->printed();
        $this->assertStringContainsString("#{$id}", $printed);
        $this->assertStringContainsString('Importer', $printed);
        $this->assertStringContainsString('Connection refused', $printed);
        $this->assertStringNotContainsString('#0 somewhere', $printed, 'the trace stays out of the summary');
    }

    public function testFailedSaysSoWhenNothingHas(): void
    {
        $this->assertSame(0, $this->commands()->failed(20));

        $this->assertStringContainsString('Nothing has failed.', $this->printed());
    }

    public function testRetryPutsAJobBack(): void
    {
        $id = $this->failOne('Importer', 'boom');

        $this->assertSame(0, $this->commands()->retry($id, false, 3));

        $this->assertStringContainsString('Requeued 1 job.', $this->printed());
        $this->assertSame(0, $this->queue->failedCount());
        $this->assertSame(1, $this->queue->pending());
    }

    public function testRetryNeedsToBeToldWhatToDo(): void
    {
        $this->assertSame(2, $this->commands()->retry(null, false, 3));

        $this->assertStringContainsString('--id=N or --all', $this->printed());
    }

    public function testForgetDeletesEverythingThatFailed(): void
    {
        $this->failOne('One', 'boom');
        $this->failOne('Two', 'boom');

        $this->assertSame(0, $this->commands()->forget(null, true, null));

        $this->assertStringContainsString('Deleted 2 rows.', $this->printed());
        $this->assertSame(0, $this->queue->failedCount());
    }

    public function testForgetRejectsADateItCannotRead(): void
    {
        $this->assertSame(2, $this->commands()->forget(null, false, 'yesterday'));

        $this->assertStringContainsString('--before must be', $this->printed());
    }

    public function testInstallHasNothingToDoForAQueueThatIsNotOnADatabase(): void
    {
        $this->assertSame(0, $this->commands()->install('/nowhere', '/nowhere', time()));

        $this->assertStringContainsString('Nothing to install', $this->printed());
    }

    public function testWorkRunsWhatIsQueuedAndStops(): void
    {
        SpyHandler::reset();
        Queue::setDriver($this->queue);
        $this->queue->push(SpyHandler::class, ['n' => 1], 0, 'default', 0, null, 3);

        $this->assertSame(0, $this->commands()->work(['default'], 30, 1, 0, 0, 0, true));

        $this->assertCount(1, SpyHandler::$calls);
        $this->assertStringContainsString('Ran 1 job', $this->printed());

        SpyHandler::reset();
    }

    /**
     * @return Commands
     */
    private function commands(): Commands
    {
        return new Commands($this->queue, '', function (string $line = ''): void {
            $this->lines[] = $line;
        });
    }

    /**
     * @return string
     */
    private function printed(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * @param  string $name
     * @param  string $error
     * @return int
     */
    private function failOne(string $name, string $error): int
    {
        $this->queue->push($name, [], 0, 'default', 0, null, 1);
        $job = $this->queue->reserve(['default'], 30, 'worker-1');
        $this->assertNotNull($job);
        $this->queue->fail($job, $error);

        return $job->id;
    }
}
