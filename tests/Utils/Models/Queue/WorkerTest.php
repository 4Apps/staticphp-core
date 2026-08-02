<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Queue\Handler;
use StaticPHP\Utils\Models\Queue\Job;
use StaticPHP\Utils\Models\Queue\Queue;
use StaticPHP\Utils\Models\Queue\QueueError;
use StaticPHP\Utils\Models\Queue\QueueInterface;
use StaticPHP\Utils\Models\Queue\Worker;

class WorkerTest extends QueueCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();

        SpyHandler::reset();
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        SpyHandler::reset();

        parent::tearDown();
    }

    /**
     * @param  ?callable(string): Handler $resolver
     * @param  ?QueueInterface            $queue
     * @return Worker
     */
    private function worker(?callable $resolver = null, ?QueueInterface $queue = null): Worker
    {
        return new Worker(
            ($queue ?? $this->queue),
            function (string $line = ''): void {
                $this->lines[] = $line;
            },
            $resolver,
            'test-worker'
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
     * Errors from a permanently failed job go to the error log as well as to the output.
     * Point that somewhere the suite can throw away rather than at stderr.
     *
     * @param  callable(): void $work
     * @return string
     */
    private function withQuietErrorLog(callable $work): string
    {
        $log = sys_get_temp_dir() . '/sp_queue_' . bin2hex(random_bytes(6)) . '.log';
        $previous = ini_get('error_log');

        ini_set('error_log', $log);

        try {
            $work();
        } finally {
            ini_set('error_log', (is_string($previous) ? $previous : ''));
        }

        $contents = (is_file($log) ? file_get_contents($log) : '');
        if (is_file($log) === true) {
            unlink($log);
        }

        return (is_string($contents) ? $contents : '');
    }

    /**
     * @param  string $column
     * @param  int    $id
     * @return int Unix timestamp
     */
    private function stampOf(string $column, int $id): int
    {
        $value = $this->text($this->row($id), $column);
        $parsed = strtotime($value . ' UTC');

        return (is_int($parsed) ? $parsed : 0);
    }

    public function testASuccessfulJobRunsOnceAndLeavesNothingBehind(): void
    {
        Queue::push(SpyHandler::class, ['invoice' => 9]);

        $this->assertTrue($this->worker()->runNext(['default']));

        $this->assertCount(1, SpyHandler::$calls);
        $this->assertSame(['invoice' => 9], SpyHandler::$calls[0]['payload']);
        $this->assertSame(1, SpyHandler::$calls[0]['attempt']);
        $this->assertSame([], $this->rows(), 'a finished job is deleted, not marked');
        $this->assertStringContainsString('done in', $this->printed());
    }

    public function testThereIsNothingToReportOnAnEmptyQueue(): void
    {
        $this->assertFalse($this->worker()->runNext(['default']));
        $this->assertSame([], SpyHandler::$calls);
    }

    public function testAFailingJobIsPutBackWithTheConfiguredBackoff(): void
    {
        Config::$items['queue'] = ['connection' => $this->connection, 'backoff' => [90]];
        Queue::reset();
        Queue::setDriver($this->queue);

        SpyHandler::$behaviour = function (): void {
            throw new \RuntimeException('smtp said no');
        };

        $id = Queue::push(SpyHandler::class, [], tries: 3);
        $before = time();

        $this->worker()->runNext(['default']);

        $row = $this->row($id);
        $this->assertNotNull($row, 'a job with attempts left stays on the queue');
        $this->assertSame(1, $this->number($row, 'attempts'));
        $this->assertStringContainsString('smtp said no', $this->text($row, 'last_error'));
        $this->assertGreaterThanOrEqual($before + 90, $this->stampOf('available_at', $id));
        $this->assertStringContainsString('retrying in 90s', $this->printed());
    }

    public function testTheLastAttemptGoesToTheFailedTable(): void
    {
        SpyHandler::$behaviour = function (): void {
            throw new \RuntimeException('smtp said no');
        };

        Queue::push(SpyHandler::class, ['id' => 3], tries: 1);

        $logged = $this->withQuietErrorLog(function (): void {
            $this->worker()->runNext(['default']);
        });

        $this->assertSame([], $this->rows());

        $failed = $this->rows('queue_failed_jobs');
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('smtp said no', $this->text($failed[0], 'error'));
        $this->assertStringContainsString('RuntimeException', $this->text($failed[0], 'error'));
        $this->assertStringContainsString('failed for good', $this->printed());
        $this->assertStringContainsString('failed permanently', $logged);
    }

    public function testARetriedJobEventuallyRunsOutOfAttempts(): void
    {
        Config::$items['queue'] = ['connection' => $this->connection, 'backoff' => 0];
        Queue::reset();
        Queue::setDriver($this->queue);

        SpyHandler::$behaviour = function (): void {
            throw new \RuntimeException('still broken');
        };

        Queue::push(SpyHandler::class, [], tries: 3);

        $this->withQuietErrorLog(function (): void {
            for ($i = 0; $i < 3; $i++) {
                $this->worker()->runNext(['default']);
            }
        });

        $this->assertCount(3, SpyHandler::$calls, 'three attempts, as configured');
        $this->assertSame([], $this->rows());
        $this->assertCount(1, $this->rows('queue_failed_jobs'));
    }

    /**
     * Releasing is for work that has not failed but is not ready, so it must not eat an
     * attempt the way a thrown exception does.
     */
    public function testAReleasedJobGoesBackWithoutBeingAFailure(): void
    {
        SpyHandler::$behaviour = function (array $payload, Job $job): void {
            $job->release(45);
        };

        $id = Queue::push(SpyHandler::class, [], tries: 3);
        $before = time();

        $this->worker()->runNext(['default']);

        $row = $this->row($id);
        $this->assertNotNull($row);
        $this->assertSame('', $this->text($row, 'last_error'), 'a release is not an error');
        $this->assertGreaterThanOrEqual($before + 45, $this->stampOf('available_at', $id));
        $this->assertStringContainsString('released, back in 45s', $this->printed());
    }

    public function testAHandlerThatCannotBeBuiltFailsTheJob(): void
    {
        $this->queue->push('Application\\Jobs\\Renamed', [], 0, 'default', 0, null, 1);

        $this->withQuietErrorLog(function (): void {
            $this->worker()->runNext(['default']);
        });

        $failed = $this->rows('queue_failed_jobs');
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('does not exist', $this->text($failed[0], 'error'));
    }

    public function testRunStopsAfterTheJobItWasAskedFor(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Queue::push(SpyHandler::class, ['n' => $i]);
        }

        $code = $this->worker()->run(['default'], 60, 0, 2, 0, 0, false);

        $this->assertSame(0, $code);
        $this->assertCount(2, SpyHandler::$calls);
        $this->assertCount(1, $this->rows(), 'the third is still queued');
        $this->assertStringContainsString('ran 2 jobs', $this->printed());
    }

    public function testRunStopsWhenTheQueueDrains(): void
    {
        Queue::push(SpyHandler::class);
        Queue::push(SpyHandler::class);

        $code = $this->worker()->run(['default'], 60, 0, 0, 0, 0, true);

        $this->assertSame(0, $code);
        $this->assertCount(2, SpyHandler::$calls);
        $this->assertStringContainsString('Nothing left to do', $this->printed());
        $this->assertStringContainsString('Ran 2 jobs', $this->printed());
    }

    /**
     * A database that has gone away and stayed away is not something this process can fix
     * by waiting. Exiting non-zero is how a supervisor is told to start a fresh one.
     */
    public function testTheWorkerGivesUpAfterRepeatedReserveFailures(): void
    {
        $broken = new class implements QueueInterface {
            public int $tries = 0;

            /**
             * @param  array<string, mixed> $payload
             * @return int
             */
            public function push(
                string $name,
                array $payload,
                int $delay,
                string $queue,
                int $priority,
                ?string $unique,
                int $maxAttempts
            ): int {
                return 0;
            }

            /**
             * @param  list<string> $queues
             * @return ?Job
             */
            public function reserve(array $queues, int $timeout, string $worker): ?Job
            {
                $this->tries++;

                throw new QueueError('the database has gone away');
            }

            public function delete(Job $job): void
            {
            }

            public function release(Job $job, int $delay, string $error): void
            {
            }

            public function fail(Job $job, string $error): void
            {
            }

            public function pending(?string $queue = null): int
            {
                return 0;
            }
        };

        $code = $this->worker(null, $broken)->run(['default'], 60, 0, 0, 0, 0, false);

        $this->assertSame(1, $code, 'a supervisor should see this as a crash');
        $this->assertSame(5, $broken->tries);
        $this->assertStringContainsString('unreadable five times running', $this->printed());
    }

    public function testTheWorkerRecoversWhenTheQueueComesBack(): void
    {
        $flaky = new class ($this->queue) implements QueueInterface {
            public int $tries = 0;
            private QueueInterface $real;

            public function __construct(QueueInterface $real)
            {
                $this->real = $real;
            }

            /**
             * @param  array<string, mixed> $payload
             * @return int
             */
            public function push(
                string $name,
                array $payload,
                int $delay,
                string $queue,
                int $priority,
                ?string $unique,
                int $maxAttempts
            ): int {
                return $this->real->push($name, $payload, $delay, $queue, $priority, $unique, $maxAttempts);
            }

            /**
             * @param  list<string> $queues
             * @return ?Job
             */
            public function reserve(array $queues, int $timeout, string $worker): ?Job
            {
                $this->tries++;

                if ($this->tries <= 3) {
                    throw new QueueError('not yet');
                }

                return $this->real->reserve($queues, $timeout, $worker);
            }

            public function delete(Job $job): void
            {
                $this->real->delete($job);
            }

            public function release(Job $job, int $delay, string $error): void
            {
                $this->real->release($job, $delay, $error);
            }

            public function fail(Job $job, string $error): void
            {
                $this->real->fail($job, $error);
            }

            public function pending(?string $queue = null): int
            {
                return $this->real->pending($queue);
            }
        };

        Queue::push(SpyHandler::class);

        $code = $this->worker(null, $flaky)->run(['default'], 60, 0, 0, 0, 0, true);

        $this->assertSame(0, $code);
        $this->assertCount(1, SpyHandler::$calls, 'the job ran once the queue answered again');
        $this->assertSame([], $this->rows());
    }
}
