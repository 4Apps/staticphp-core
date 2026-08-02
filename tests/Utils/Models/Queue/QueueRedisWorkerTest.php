<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Queue\Job;
use StaticPHP\Utils\Models\Queue\Worker;

/**
 * The worker, over the redis driver.
 *
 * WorkerTest already covers the loop itself against the database. What is worth proving
 * again here is that the same loop finishes, retries and gives up correctly when the thing
 * underneath it is a stream and a consumer group rather than a table.
 */
class QueueRedisWorkerTest extends QueueRedisCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        SpyHandler::reset();
        $this->lines = [];
        Config::$items['queue'] = ['driver' => 'redis', 'backoff' => 30];
    }

    protected function tearDown(): void
    {
        SpyHandler::reset();

        parent::tearDown();
    }

    public function testAJobRunsAndIsGone(): void
    {
        $this->queue->push(SpyHandler::class, ['invoice' => 12], 0, 'default', 0, null, 3);

        $this->assertTrue($this->worker()->runNext(['default'], 30));

        $this->assertCount(1, SpyHandler::$calls);
        $this->assertSame(['invoice' => 12], SpyHandler::$calls[0]['payload']);
        $this->assertSame(0, $this->queue->pending());
        $this->assertSame(0, $this->queue->failedCount());
        $this->assertStringContainsString('done in', implode("\n", $this->lines));
    }

    public function testAnEmptyQueueIsNotAJob(): void
    {
        $this->assertFalse($this->worker()->runNext(['default'], 30));
        $this->assertSame([], SpyHandler::$calls);
    }

    public function testAThrowingHandlerIsRetriedAfterTheBackoff(): void
    {
        $id = $this->queue->push(SpyHandler::class, [], 0, 'default', 0, null, 3);
        SpyHandler::$behaviour = static function (): void {
            throw new \RuntimeException('the api said no');
        };

        $before = time();
        $this->assertTrue($this->worker()->runNext(['default'], 30));

        $this->assertSame(0, $this->queue->failedCount(), 'there are attempts left');
        $this->assertSame(0, $this->queue->pending(), 'it is waiting out the backoff');
        $this->assertStringContainsString('retrying in 30s', implode("\n", $this->lines));

        $due = $this->redis->zScore($this->prefix . 'q:default:delayed', (string) $id);
        $this->assertIsFloat($due);
        $this->assertGreaterThanOrEqual($before + 30, (int) $due);
        $this->assertStringContainsString('the api said no', $this->field($id, 'last_error'));
    }

    public function testTheLastAttemptFailsForGood(): void
    {
        $id = $this->queue->push(SpyHandler::class, [], 0, 'default', 0, null, 1);
        SpyHandler::$behaviour = static function (): void {
            throw new \RuntimeException('nothing left to try');
        };

        $this->assertTrue($this->worker()->runNext(['default'], 30));

        $this->assertSame(1, $this->queue->failedCount());
        $this->assertSame(0, $this->queue->pending());
        $this->assertStringContainsString('failed for good', implode("\n", $this->lines));

        $rows = $this->queue->failedRows(10);
        $this->assertSame($id, $rows[0]['id']);
        $this->assertStringContainsString('nothing left to try', $this->cell($rows[0], 'error'));
    }

    public function testAHandlerThatReleasesItselfDoesNotSpendAnAttempt(): void
    {
        $id = $this->queue->push(SpyHandler::class, [], 0, 'default', 0, null, 3);
        SpyHandler::$behaviour = static function (array $payload, Job $job): void {
            $job->release(120);
        };

        $this->assertTrue($this->worker()->runNext(['default'], 30));

        $this->assertSame('', $this->field($id, 'last_error'), 'released is not failed');
        $this->assertStringContainsString('released, back in 120s', implode("\n", $this->lines));

        $this->makeDue('default', $id, time() - 1);
        SpyHandler::$behaviour = null;

        $this->assertTrue($this->worker()->runNext(['default'], 30));
        $this->assertSame(2, SpyHandler::$calls[1]['attempt']);
    }

    public function testAJobNamingNothingRunnableFailsRatherThanLooping(): void
    {
        // Pushed past Queue::push(), which would refuse the name, the way a job queued
        // before its class was deleted arrives.
        $id = $this->queue->push(SpyHandler::class, [], 0, 'default', 0, null, 1);
        $this->redis->hSet($this->prefix . 'j:' . $id, 'name', 'Application\\Jobs\\Deleted');

        $this->assertTrue($this->worker()->runNext(['default'], 30));

        $this->assertSame(0, $this->queue->pending());
        $this->assertSame(1, $this->queue->failedCount());
    }

    public function testTheLoopStopsWhenTheQueueDrains(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->queue->push(SpyHandler::class, ['n' => $i], 0, 'default', 0, null, 3);
        }

        $code = $this->worker()->run(['default'], 30, 1, 0, 0, 0, true);

        $this->assertSame(0, $code);
        $this->assertCount(3, SpyHandler::$calls);
        $this->assertStringContainsString('Ran 3 jobs', implode("\n", $this->lines));
    }

    public function testMaxJobsStopsTheLoopEarly(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->queue->push(SpyHandler::class, ['n' => $i], 0, 'default', 0, null, 3);
        }

        $this->assertSame(0, $this->worker()->run(['default'], 30, 1, 2, 0, 0, false));

        $this->assertCount(2, SpyHandler::$calls);
        $this->assertSame(1, $this->queue->pending());
    }

    /**
     * @return Worker
     */
    private function worker(): Worker
    {
        return new Worker(
            $this->queue,
            function (string $line = ''): void {
                $this->lines[] = $line;
            },
            null,
            'test-worker'
        );
    }
}
