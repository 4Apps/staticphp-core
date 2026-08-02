<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Queue\Handler;
use StaticPHP\Utils\Models\Queue\Job;
use StaticPHP\Utils\Models\Queue\Queue;
use StaticPHP\Utils\Models\Queue\QueueError;

class QueueTest extends QueueCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SpyHandler::reset();
    }

    /**
     * @param  array<string, mixed> $extra
     * @return void
     */
    private function configure(array $extra): void
    {
        Config::$items['queue'] = array_merge(['connection' => $this->connection], $extra);
        Queue::reset();
        Queue::setDriver($this->queue);
    }

    public function testPushWritesThroughTheConfiguredDriver(): void
    {
        $id = Queue::push(SpyHandler::class, ['invoice' => 9]);

        $this->assertGreaterThan(0, $id);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(SpyHandler::class, $this->text($rows[0], 'name'));
        $this->assertSame('{"invoice":9}', $this->text($rows[0], 'payload'));
    }

    /**
     * A name that resolves to nothing fails on every attempt and then sits in the failed
     * table, hours later and without a stack trace. Better to refuse it at the call site.
     */
    public function testPushRefusesAJobNameNothingCanRun(): void
    {
        $this->expectException(QueueError::class);
        $this->expectExceptionMessage('no such class');

        Queue::push('Application\\Jobs\\Renamed');
    }

    public function testPushRefusesAClassThatIsNotAHandler(): void
    {
        $this->expectException(QueueError::class);
        $this->expectExceptionMessage('does not implement');

        Queue::push(\stdClass::class);
    }

    public function testAConfiguredAliasIsAcceptedAsAJobName(): void
    {
        $this->configure(['handlers' => ['send-invoice' => SpyHandler::class]]);

        Queue::push('send-invoice', ['id' => 1]);

        $rows = $this->rows();
        $this->assertSame('send-invoice', $this->text($rows[0], 'name'));
    }

    public function testTheDefaultQueueAndAttemptCountComeFromConfig(): void
    {
        $this->configure(['queue' => 'mail', 'tries' => 7]);

        Queue::push(SpyHandler::class);

        $rows = $this->rows();
        $this->assertSame('mail', $this->text($rows[0], 'queue'));
        $this->assertSame(7, $this->number($rows[0], 'max_attempts'));
    }

    public function testTheCallSiteBeatsTheConfiguredDefaults(): void
    {
        $this->configure(['queue' => 'mail', 'tries' => 7]);

        Queue::push(SpyHandler::class, [], queue: 'urgent', tries: 1);

        $rows = $this->rows();
        $this->assertSame('urgent', $this->text($rows[0], 'queue'));
        $this->assertSame(1, $this->number($rows[0], 'max_attempts'));
    }

    public function testBackoffWalksTheListThenRepeatsItsLastEntry(): void
    {
        $this->configure(['backoff' => [10, 60, 300]]);

        $this->assertSame(10, Queue::backoff(1));
        $this->assertSame(60, Queue::backoff(2));
        $this->assertSame(300, Queue::backoff(3));
        $this->assertSame(300, Queue::backoff(9), 'past the end it keeps the last delay');
    }

    public function testBackoffAcceptsOneDelayForEveryAttempt(): void
    {
        $this->configure(['backoff' => 30]);

        $this->assertSame(30, Queue::backoff(1));
        $this->assertSame(30, Queue::backoff(5));
    }

    public function testHandlerResolvesAClassName(): void
    {
        $this->assertInstanceOf(SpyHandler::class, Queue::handler(SpyHandler::class));
    }

    public function testHandlerResolvesAnAliasToItsClass(): void
    {
        $this->configure(['handlers' => ['send-invoice' => SpyHandler::class]]);

        $this->assertInstanceOf(SpyHandler::class, Queue::handler('send-invoice'));
    }

    /**
     * The reason a callable is allowed: a handler with constructor dependencies has no way
     * to get them if the queue is the thing calling new.
     */
    public function testHandlerLetsAConfiguredCallableBuildIt(): void
    {
        $built = new class implements Handler {
            public bool $ran = false;

            /**
             * @param  array<string, mixed> $payload
             * @param  Job                  $job
             * @return void
             */
            public function handle(array $payload, Job $job): void
            {
                $this->ran = true;
            }
        };

        $this->configure(['handlers' => ['built' => fn(): Handler => $built]]);

        $this->assertSame($built, Queue::handler('built'));
    }

    public function testHandlerRejectsACallableThatReturnsSomethingElse(): void
    {
        $this->configure(['handlers' => ['wrong' => fn(): \stdClass => new \stdClass()]]);

        $this->expectException(QueueError::class);
        $this->expectExceptionMessage('did not return');

        Queue::handler('wrong');
    }

    public function testHandlerSaysWhichNameItCouldNotResolve(): void
    {
        $this->expectException(QueueError::class);
        $this->expectExceptionMessage('No handler "nothing"');

        Queue::handler('nothing');
    }

    public function testPendingIsAnsweredByTheDriver(): void
    {
        Queue::push(SpyHandler::class);
        Queue::push(SpyHandler::class, [], delay: 3600);

        $this->assertSame(1, Queue::pending());
    }
}
