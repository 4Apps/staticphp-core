<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use StaticPHP\Utils\Models\Queue\QueueError;

/**
 * The redis driver, against a real server.
 *
 * The cases worth reading first are the ones about a claim: that a second worker cannot have
 * a job somebody is holding, that it can once the reservation runs out, and that a reclaim
 * arriving while the previous claim is still good parks the job rather than running it
 * twice. Everything else here is bookkeeping by comparison.
 */
class QueueRedisTest extends QueueRedisCase
{
    public function testPushReturnsAnIncreasingIdPerJob(): void
    {
        $first = $this->queue->push('First', [], 0, 'default', 0, null, 3);
        $second = $this->queue->push('Second', [], 0, 'default', 0, null, 3);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first + 1, $second);
    }

    public function testAPushedJobIsReservableAndCarriesItsPayload(): void
    {
        $id = $this->queue->push('Report', ['month' => '2026-08', 'utf' => 'āžšķ'], 0, 'default', 0, null, 3);

        $job = $this->queue->reserve(['default'], 30, 'worker-1');

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
        $this->assertSame('Report', $job->name);
        $this->assertSame(['month' => '2026-08', 'utf' => 'āžšķ'], $job->payload);
        $this->assertSame('default', $job->queue);
        $this->assertSame(1, $job->attempts);
        $this->assertSame(3, $job->maxAttempts);
        $this->assertNotSame('', $job->handle, 'the stream entry id is what acknowledges the job later');
    }

    public function testReservingRecordsTheWorkerAndTheDeadline(): void
    {
        $id = $this->queue->push('Report', [], 0, 'default', 0, null, 3);
        $before = time();

        $this->queue->reserve(['default'], 45, 'box-a:1234');

        $this->assertSame('box-a:1234', $this->field($id, 'reserved_by'));
        $this->assertGreaterThanOrEqual($before + 45, (int) $this->field($id, 'reserved_until'));
    }

    public function testAnEmptyQueueReservesNothing(): void
    {
        $this->assertNull($this->queue->reserve(['default'], 30, 'worker-1'));
    }

    public function testASecondWorkerCannotTakeAHeldJob(): void
    {
        $this->queue->push('Only', [], 0, 'default', 0, null, 3);

        $this->assertNotNull($this->queue->reserve(['default'], 60, 'worker-1'));
        $this->assertNull($this->queue->reserve(['default'], 60, 'worker-2'));
    }

    public function testHigherPriorityRunsFirstAndTiesStayInOrder(): void
    {
        $low = $this->queue->push('Low', [], 0, 'default', 0, null, 3);
        $high = $this->queue->push('High', [], 0, 'default', 10, null, 3);
        $alsoHigh = $this->queue->push('AlsoHigh', [], 0, 'default', 10, null, 3);

        $order = [];
        for ($i = 0; $i < 3; $i++) {
            $job = $this->queue->reserve(['default'], 30, 'worker-1');
            $this->assertNotNull($job);
            $order[] = $job->id;
        }

        $this->assertSame([$high, $alsoHigh, $low], $order);
    }

    public function testQueuesAreTriedInTheOrderTheyAreGiven(): void
    {
        $this->queue->push('Slow', [], 0, 'bulk', 0, null, 3);
        $urgent = $this->queue->push('Fast', [], 0, 'urgent', 0, null, 3);

        $job = $this->queue->reserve(['urgent', 'bulk'], 30, 'worker-1');

        $this->assertNotNull($job);
        $this->assertSame($urgent, $job->id);
        $this->assertSame('urgent', $job->queue);
    }

    public function testADelayedJobWaitsForItsTime(): void
    {
        $id = $this->queue->push('Later', [], 3600, 'default', 0, null, 3);

        $this->assertNull($this->queue->reserve(['default'], 30, 'worker-1'));
        $this->assertSame(0, $this->streamLength('default'), 'a delayed job is not in the stream yet');

        $this->makeDue('default', $id, time() - 1);
        $job = $this->queue->reserve(['default'], 30, 'worker-1');

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
    }

    public function testDeletingAJobRemovesEverythingItOwned(): void
    {
        $id = $this->queue->push('Done', [], 0, 'default', 0, 'nightly', 3);
        $job = $this->queue->reserve(['default'], 30, 'worker-1');
        $this->assertNotNull($job);

        $this->queue->delete($job);

        $this->assertSame([], $this->job($id));
        $this->assertFalse($this->exists('u:nightly'));
        $this->assertSame(0, $this->streamLength('default'));
        $this->assertSame(0, $this->queue->pending());
    }

    public function testReleasingPutsTheJobBackWithoutLosingItsAttempts(): void
    {
        $id = $this->queue->push('Retry', [], 0, 'default', 0, null, 3);
        $job = $this->queue->reserve(['default'], 30, 'worker-1');
        $this->assertNotNull($job);

        $this->queue->release($job, 3600, 'not ready');

        $this->assertNull($this->queue->reserve(['default'], 30, 'worker-2'), 'it is delayed, not ready');
        $this->assertSame('not ready', $this->field($id, 'last_error'));
        $this->assertSame('1', $this->field($id, 'attempts'));

        $this->makeDue('default', $id, time() - 1);
        $again = $this->queue->reserve(['default'], 30, 'worker-2');

        $this->assertNotNull($again);
        $this->assertSame(2, $again->attempts, 'the second run is the second attempt');
    }

    public function testAJobIsReclaimedOnceItsReservationRunsOut(): void
    {
        $id = $this->queue->push('Abandoned', [], 0, 'default', 0, null, 3);

        $lost = $this->queue->reserve(['default'], 1, 'worker-that-dies');
        $this->assertNotNull($lost);
        $this->assertNull($this->queue->reserve(['default'], 1, 'worker-2'), 'still held');

        usleep(1_200_000);
        $rescued = $this->queue->reserve(['default'], 1, 'worker-3');

        $this->assertNotNull($rescued);
        $this->assertSame($id, $rescued->id);
        $this->assertSame(2, $rescued->attempts, 'reclaiming is another attempt');
        $this->assertSame('worker-3', $this->field($id, 'reserved_by'));
    }

    public function testAReclaimArrivingBeforeTheDeadlineParksTheJobInstead(): void
    {
        $id = $this->queue->push('LongRunner', [], 0, 'default', 0, null, 3);

        $held = $this->queue->reserve(['default'], 30, 'patient-worker');
        $this->assertNotNull($held);

        // A worker whose own timeout is one second thinks anything idle for a second is
        // abandoned. This one is not: its holder has 30 seconds and is still inside them.
        usleep(1_200_000);
        $this->assertNull($this->queue->reserve(['default'], 1, 'impatient-worker'));

        $this->assertSame(1, (int) $this->field($id, 'attempts'), 'parking is not an attempt');
        $this->assertSame(0, $this->streamLength('default'));

        $parked = $this->redis->zScore($this->prefix . 'q:default:delayed', (string) $id);
        $this->assertIsFloat($parked);
        $this->assertSame((int) $this->field($id, 'reserved_until'), (int) $parked);
    }

    public function testFailingMovesTheJobToTheFailedSet(): void
    {
        $id = $this->queue->push('Doomed', [], 0, 'default', 0, 'once-only', 3);
        $job = $this->queue->reserve(['default'], 30, 'worker-1');
        $this->assertNotNull($job);

        $this->queue->fail($job, "Boom\n at line 1");

        $this->assertSame(1, $this->queue->failedCount());
        $this->assertSame(0, $this->queue->pending());
        $this->assertSame(0, $this->streamLength('default'));
        $this->assertFalse($this->exists('u:once-only'), 'the key belonged to a job that is no longer pending');

        $rows = $this->queue->failedRows(10);
        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]['id']);
        $this->assertSame('Doomed', $rows[0]['name']);
        $this->assertSame('1', $this->cell($rows[0], 'attempts'));
        $this->assertStringContainsString('Boom', $this->cell($rows[0], 'error'));
    }

    public function testAUniqueKeyCollapsesTwoPushesIntoOne(): void
    {
        $first = $this->queue->push('Import', ['run' => 1], 0, 'default', 0, 'nightly-import', 3);
        $second = $this->queue->push('Import', ['run' => 2], 0, 'default', 0, 'nightly-import', 3);

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->queue->pending());
        $this->assertSame('{"run":1}', $this->field($first, 'payload'), 'the first push is the one that stands');
    }

    public function testAUniqueKeyIsFreeAgainOnceTheJobIsDone(): void
    {
        $first = $this->queue->push('Import', [], 0, 'default', 0, 'nightly-import', 3);
        $job = $this->queue->reserve(['default'], 30, 'worker-1');
        $this->assertNotNull($job);
        $this->queue->delete($job);

        $second = $this->queue->push('Import', [], 0, 'default', 0, 'nightly-import', 3);

        $this->assertNotSame($first, $second);
    }

    public function testAUniqueKeyPointingAtNothingDoesNotBlockThePush(): void
    {
        $first = $this->queue->push('Import', [], 0, 'default', 0, 'nightly-import', 3);
        $this->redis->del($this->prefix . 'j:' . $first);

        $second = $this->queue->push('Import', [], 0, 'default', 0, 'nightly-import', 3);

        $this->assertNotSame($first, $second);
    }

    public function testAPayloadThatWillNotDecodeGoesStraightToFailed(): void
    {
        $id = $this->queue->push('Broken', [], 0, 'default', 0, null, 3);
        $this->redis->hSet($this->prefix . 'j:' . $id, 'payload', '{ not json');

        $this->assertNull($this->queue->reserve(['default'], 30, 'worker-1'));
        $this->assertSame(1, $this->queue->failedCount());
        $this->assertSame(0, $this->streamLength('default'));
    }

    public function testPushRefusesAJobWithoutAName(): void
    {
        $this->expectException(QueueError::class);

        $this->queue->push('', [], 0, 'default', 0, null, 3);
    }

    public function testPushRefusesAPayloadThatWillNotEncode(): void
    {
        $this->expectException(QueueError::class);

        $this->queue->push('Bad', ['bytes' => "\xB1\x31"], 0, 'default', 0, null, 3);
    }

    public function testPendingCountsOnlyWhatCouldRunNow(): void
    {
        $this->queue->push('A', [], 0, 'default', 0, null, 3);
        $this->queue->push('B', [], 0, 'default', 0, null, 3);
        $this->queue->push('C', [], 3600, 'default', 0, null, 3);
        $this->queue->push('D', [], 0, 'other', 0, null, 3);

        $this->assertSame(3, $this->queue->pending());
        $this->assertSame(2, $this->queue->pending('default'));
        $this->assertSame(1, $this->queue->pending('other'));

        $this->queue->reserve(['default'], 30, 'worker-1');

        $this->assertSame(1, $this->queue->pending('default'), 'a held job is not pending');
    }

    public function testStatsSplitTheBacklogTheWayStatusPrintsIt(): void
    {
        $this->queue->push('A', [], 0, 'default', 0, null, 3);
        $this->queue->push('B', [], 0, 'default', 0, null, 3);
        $this->queue->push('C', [], 3600, 'default', 0, null, 3);
        $this->queue->reserve(['default'], 30, 'worker-1');

        $stats = $this->queue->stats();

        $this->assertSame([[
            'queue' => 'default',
            'pending' => 1,
            'delayed' => 1,
            'reserved' => 1,
            'total' => 3,
        ]], $stats);
    }

    public function testStatsSayNothingAboutAQueueThatHasDrained(): void
    {
        $this->queue->push('A', [], 0, 'default', 0, null, 3);
        $job = $this->queue->reserve(['default'], 30, 'worker-1');
        $this->assertNotNull($job);
        $this->queue->delete($job);

        $this->assertSame([], $this->queue->stats());
    }

    public function testFailedRowsComeBackNewestFirst(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $this->queue->push("Job{$i}", [], 0, 'default', 0, null, 1);
            $job = $this->queue->reserve(['default'], 30, 'worker-1');
            $this->assertNotNull($job);
            $this->queue->fail($job, "failure {$i}");
            $ids[] = $job->id;
        }

        $rows = $this->queue->failedRows(2);

        $this->assertCount(2, $rows, 'the limit is honoured');
        $this->assertSame($ids[2], $rows[0]['id']);
        $this->assertSame($ids[1], $rows[1]['id']);
    }

    public function testRetryingAFailedJobStartsItOverOnItsOwnQueue(): void
    {
        $id = $this->queue->push('Doomed', ['n' => 1], 0, 'reports', 7, 'key-a', 1);
        $job = $this->queue->reserve(['reports'], 30, 'worker-1');
        $this->assertNotNull($job);
        $this->queue->fail($job, 'boom');

        $this->assertSame(1, $this->queue->retryFailed(null, 5));
        $this->assertSame(0, $this->queue->failedCount());

        $back = $this->queue->reserve(['reports'], 30, 'worker-2');

        $this->assertNotNull($back);
        $this->assertSame($id, $back->id);
        $this->assertSame(1, $back->attempts, 'a retry starts the count over');
        $this->assertSame(5, $back->maxAttempts);
        $this->assertSame(['n' => 1], $back->payload);
        $this->assertSame('', $this->field($id, 'unique_key'), 'the key may have been reused since');
    }

    public function testRetryingAnIdThatNeverFailedChangesNothing(): void
    {
        $this->assertSame(0, $this->queue->retryFailed(4242, 3));
    }

    public function testForgettingRemovesTheFailedJobAndItsRecord(): void
    {
        $id = $this->failOne('Old');

        $this->assertSame(1, $this->queue->forgetFailed($id, null));
        $this->assertSame(0, $this->queue->failedCount());
        $this->assertSame([], $this->job($id));
    }

    public function testForgettingEverythingClearsTheFailedSet(): void
    {
        $this->failOne('One');
        $this->failOne('Two');

        $this->assertSame(2, $this->queue->forgetFailed(null, null));
        $this->assertSame(0, $this->queue->failedCount());
    }

    public function testForgettingByDateLeavesAnythingNewerAlone(): void
    {
        $old = $this->failOne('Old');
        $recent = $this->failOne('Recent');

        $this->redis->zAdd($this->prefix . 'failed', strtotime('2020-01-01 00:00:00 UTC'), (string) $old);

        $this->assertSame(1, $this->queue->forgetFailed(null, '2021-01-01'));
        $this->assertSame(1, $this->queue->failedCount());
        $this->assertSame($recent, $this->queue->failedRows(10)[0]['id']);
    }

    public function testForgettingRejectsADateItCannotRead(): void
    {
        $this->failOne('Old');

        $this->expectException(QueueError::class);

        $this->queue->forgetFailed(null, 'last tuesday');
    }

    public function testTheDriverRecoversWhenRedisHasForgottenItsScripts(): void
    {
        $this->queue->push('Before', [], 0, 'default', 0, null, 3);
        $this->redis->script('flush');

        $id = $this->queue->push('After', [], 0, 'default', 0, null, 3);
        $job = $this->queue->reserve(['default'], 30, 'worker-1');

        $this->assertNotNull($job);
        $this->assertGreaterThan(0, $id);
    }

    public function testTwoDriversOnOneServerDoNotSeeEachOther(): void
    {
        $other = new \StaticPHP\Utils\Models\Queue\QueueRedis($this->redis, $this->prefix . 'other:');

        $this->queue->push('Mine', [], 0, 'default', 0, null, 3);

        $this->assertSame(1, $this->queue->pending());
        $this->assertSame(0, $other->pending());
    }

    /**
     * Push a job, run it once and fail it.
     *
     * @param  string $name
     * @return int
     */
    private function failOne(string $name): int
    {
        $this->queue->push($name, [], 0, 'default', 0, null, 1);
        $job = $this->queue->reserve(['default'], 30, 'worker-1');
        $this->assertNotNull($job);
        $this->queue->fail($job, "{$name} broke");

        return $job->id;
    }
}
