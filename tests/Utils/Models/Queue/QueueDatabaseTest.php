<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use StaticPHP\Utils\Models\Db;
use StaticPHP\Utils\Models\Queue\Job;
use StaticPHP\Utils\Models\Queue\QueueDatabase;
use StaticPHP\Utils\Models\Queue\QueueError;

class QueueDatabaseTest extends QueueCase
{
    public function testAPushedJobCanBeReservedWithItsPayloadIntact(): void
    {
        $id = $this->queue->push('demo', ['invoice' => 42, 'copy' => ['a', 'b']], 0, 'default', 0, null, 3);

        $this->assertGreaterThan(0, $id);

        $job = $this->queue->reserve(['default'], 60, 'worker-1');

        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame($id, $job->id);
        $this->assertSame('demo', $job->name);
        $this->assertSame(['invoice' => 42, 'copy' => ['a', 'b']], $job->payload);
        $this->assertSame(1, $job->attempts, 'reserving is the attempt');
        $this->assertSame(3, $job->maxAttempts);
    }

    public function testAReservedJobIsNotHandedOutAgain(): void
    {
        $this->queue->push('demo', [], 0, 'default', 0, null, 3);

        $this->assertInstanceOf(Job::class, $this->queue->reserve(['default'], 60, 'worker-1'));
        $this->assertNull($this->queue->reserve(['default'], 60, 'worker-2'));
    }

    /**
     * The reason the claim is a deadline rather than a flag. A worker that is killed cannot
     * come back to release what it was holding, so the row has to become claimable on its
     * own or the job is lost until somebody notices.
     */
    public function testAJobHeldByADeadWorkerComesBackWhenTheClaimExpires(): void
    {
        $id = $this->queue->push('demo', [], 0, 'default', 0, null, 3);

        $first = $this->queue->reserve(['default'], 60, 'worker-1');
        $this->assertInstanceOf(Job::class, $first);

        $this->expireReservation($id);

        $second = $this->queue->reserve(['default'], 60, 'worker-2');

        $this->assertInstanceOf(Job::class, $second);
        $this->assertSame($id, $second->id);
        $this->assertSame(2, $second->attempts, 'the second pickup is a second attempt');
    }

    public function testADelayedJobIsInvisibleUntilItIsDue(): void
    {
        $this->queue->push('later', [], 3600, 'default', 0, null, 3);

        $this->assertNull($this->queue->reserve(['default'], 60, 'worker-1'));
        $this->assertSame(0, $this->queue->pending());
        $this->assertCount(1, $this->rows(), 'it is queued, just not yet');
    }

    public function testHigherPriorityGoesFirstAndTiesStayInOrder(): void
    {
        $low = $this->queue->push('low', [], 0, 'default', 0, null, 3);
        $high = $this->queue->push('high', [], 0, 'default', 10, null, 3);
        $alsoHigh = $this->queue->push('also-high', [], 0, 'default', 10, null, 3);

        $order = [];
        for ($i = 0; $i < 3; $i++) {
            $job = $this->queue->reserve(['default'], 60, 'worker-1');
            $this->assertInstanceOf(Job::class, $job);
            $order[] = $job->id;
        }

        $this->assertSame([$high, $alsoHigh, $low], $order);
    }

    public function testQueuesAreDrainedInTheOrderTheyAreNamed(): void
    {
        $this->queue->push('slow', [], 0, 'bulk', 0, null, 3);
        $urgent = $this->queue->push('quick', [], 0, 'high', 0, null, 3);

        $job = $this->queue->reserve(['high', 'bulk'], 60, 'worker-1');

        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame($urgent, $job->id, 'the first named queue with work wins');
    }

    public function testAUniqueKeyCollapsesRepeatedPushes(): void
    {
        $first = $this->queue->push('rebuild', ['scope' => 'all'], 0, 'default', 0, 'rebuild-all', 3);
        $second = $this->queue->push('rebuild', ['scope' => 'all'], 0, 'default', 0, 'rebuild-all', 3);

        $this->assertSame($first, $second, 'the second push should find the first');
        $this->assertCount(1, $this->rows());
    }

    public function testTheSameUniqueKeyIsFreeAgainOnceTheJobIsDone(): void
    {
        $this->queue->push('rebuild', [], 0, 'default', 0, 'rebuild-all', 3);

        $job = $this->queue->reserve(['default'], 60, 'worker-1');
        $this->assertInstanceOf(Job::class, $job);
        $this->queue->delete($job);

        $again = $this->queue->push('rebuild', [], 0, 'default', 0, 'rebuild-all', 3);

        $this->assertGreaterThan(0, $again);
        $this->assertCount(1, $this->rows());
    }

    public function testDeletingAFinishedJobLeavesTheTableEmpty(): void
    {
        $this->queue->push('demo', [], 0, 'default', 0, null, 3);

        $job = $this->queue->reserve(['default'], 60, 'worker-1');
        $this->assertInstanceOf(Job::class, $job);

        $this->queue->delete($job);

        $this->assertSame([], $this->rows());
    }

    public function testReleasingPutsItBackWithADelayAndKeepsTheError(): void
    {
        $id = $this->queue->push('demo', [], 0, 'default', 0, null, 3);

        $job = $this->queue->reserve(['default'], 60, 'worker-1');
        $this->assertInstanceOf(Job::class, $job);

        $this->queue->release($job, 3600, 'smtp said no');

        $row = $this->row($id);
        $this->assertSame('', $this->text($row, 'reserved_until'), 'nobody holds it now');
        $this->assertSame('smtp said no', $this->text($row, 'last_error'));
        $this->assertNull($this->queue->reserve(['default'], 60, 'worker-2'), 'the delay should hold');
    }

    public function testFailingMovesTheJobOffTheQueue(): void
    {
        $this->queue->push('demo', ['id' => 7], 0, 'default', 0, null, 1);

        $job = $this->queue->reserve(['default'], 60, 'worker-1');
        $this->assertInstanceOf(Job::class, $job);

        $this->queue->fail($job, 'RuntimeException: nope');

        $this->assertSame([], $this->rows());

        $failed = $this->rows('queue_failed_jobs');
        $this->assertCount(1, $failed);
        $this->assertSame('demo', $this->text($failed[0], 'name'));
        $this->assertSame('{"id":7}', $this->text($failed[0], 'payload'));
        $this->assertSame(1, $this->number($failed[0], 'attempts'));
        $this->assertStringContainsString('nope', $this->text($failed[0], 'error'));
    }

    /**
     * A row nothing can decode will fail identically on every attempt, so retrying it just
     * takes longer to reach the same place.
     */
    public function testAnUnreadablePayloadIsFailedRatherThanRetried(): void
    {
        $this->queue->push('demo', [], 0, 'default', 0, null, 3);
        Db::query("UPDATE queue_jobs SET payload = 'not json'", [], $this->connection);

        $this->assertNull($this->queue->reserve(['default'], 60, 'worker-1'));
        $this->assertSame([], $this->rows());

        $failed = $this->rows('queue_failed_jobs');
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('not valid JSON', $this->text($failed[0], 'error'));
    }

    public function testPendingCountsOnlyWhatCouldRunNow(): void
    {
        $this->queue->push('a', [], 0, 'default', 0, null, 3);
        $this->queue->push('b', [], 0, 'default', 0, null, 3);
        $this->queue->push('c', [], 3600, 'default', 0, null, 3);
        $this->queue->push('d', [], 0, 'other', 0, null, 3);

        $this->assertSame(3, $this->queue->pending(), 'two on default plus one on another queue; c is not due');

        $this->queue->reserve(['default'], 60, 'worker-1');

        $this->assertSame(2, $this->queue->pending(), 'the reserved one no longer counts');
        $this->assertSame(1, $this->queue->pending('default'));
        $this->assertSame(1, $this->queue->pending('other'));
    }

    public function testStatsSplitTheBacklogTheWayTheyAreRead(): void
    {
        $this->queue->push('a', [], 0, 'default', 0, null, 3);
        $this->queue->push('b', [], 3600, 'default', 0, null, 3);
        $this->queue->push('c', [], 0, 'default', 0, null, 3);
        $this->queue->reserve(['default'], 600, 'worker-1');

        $stats = $this->queue->stats();

        $this->assertCount(1, $stats);
        $this->assertSame('default', $stats[0]['queue']);
        $this->assertSame(1, $stats[0]['pending']);
        $this->assertSame(1, $stats[0]['delayed']);
        $this->assertSame(1, $stats[0]['reserved']);
        $this->assertSame(3, $stats[0]['total']);
    }

    public function testRetryPutsAFailedJobBackWithAFreshAttemptCount(): void
    {
        $this->queue->push('demo', ['id' => 7], 0, 'reports', 0, null, 1);
        $job = $this->queue->reserve(['reports'], 60, 'worker-1');
        $this->assertInstanceOf(Job::class, $job);
        $this->queue->fail($job, 'broken');

        $this->assertSame(1, $this->queue->retryFailed(null, 5));

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('reports', $this->text($rows[0], 'queue'));
        $this->assertSame(0, $this->number($rows[0], 'attempts'));
        $this->assertSame(5, $this->number($rows[0], 'max_attempts'));
        $this->assertSame([], $this->rows('queue_failed_jobs'));
    }

    public function testForgetDeletesByIdAndByAge(): void
    {
        foreach (['one', 'two'] as $name) {
            $this->queue->push($name, [], 0, 'default', 0, null, 1);
            $job = $this->queue->reserve(['default'], 60, 'worker-1');
            $this->assertInstanceOf(Job::class, $job);
            $this->queue->fail($job, 'broken');
        }

        $failed = $this->rows('queue_failed_jobs');
        $this->assertCount(2, $failed);

        $this->assertSame(1, $this->queue->forgetFailed($this->number($failed[0], 'id'), null));
        $this->assertCount(1, $this->rows('queue_failed_jobs'));

        $this->assertSame(1, $this->queue->forgetFailed(null, gmdate('Y-m-d H:i:s', time() + 60)));
        $this->assertSame([], $this->rows('queue_failed_jobs'));
    }

    /**
     * The headline argument for keeping jobs in the application's own database. A push is
     * an INSERT like any other, so it lives or dies with the work that caused it.
     */
    public function testAPushInsideARolledBackTransactionQueuesNothing(): void
    {
        try {
            Db::transaction(function (): void {
                $this->queue->push('demo', [], 0, 'default', 0, null, 3);

                throw new \RuntimeException('the work failed after queueing');
            }, $this->connection);

            $this->fail('the exception should have been rethrown');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame([], $this->rows(), 'the job should have gone back with the transaction');
    }

    public function testAPushInsideACommittedTransactionSurvives(): void
    {
        Db::transaction(function (): void {
            $this->queue->push('demo', [], 0, 'default', 0, null, 3);
        }, $this->connection);

        $this->assertCount(1, $this->rows());
        $this->assertSame(1, $this->queue->pending());
    }

    public function testATableNameThatIsNotAnIdentifierIsRefused(): void
    {
        $this->expectException(QueueError::class);

        new QueueDatabase($this->connection, 'queue_jobs; DROP TABLE queue_jobs', 'queue_failed_jobs');
    }

    public function testAPayloadThatCannotBeEncodedIsRefused(): void
    {
        $this->expectException(QueueError::class);

        $this->queue->push('demo', ['bad' => fopen('php://memory', 'r')], 0, 'default', 0, null, 3);
    }
}
