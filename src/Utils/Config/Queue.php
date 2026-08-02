<?php

/*
|--------------------------------------------------------------------------
| Job queue
|
| Override any of this from the application by creating Application/Config/Queue.php
| and adding 'Queue' to $config['autoload_configs'].
|--------------------------------------------------------------------------
*/

$config['queue'] = [

    // Which entry of $config['db']['pdo'] jobs are written to. The same connection the work
    // that queues them runs on, because that is what lets a push join the caller's
    // transaction - queue the email inside the transaction that writes the invoice and
    // either both happen or neither does. Pointing this at a separate database gives that
    // up and gains nothing else.
    'connection' => 'default',

    // Prefixed on purpose: `jobs` is a word an application wants for its own work orders.
    'table' => 'queue_jobs',
    'failed_table' => 'queue_failed_jobs',

    // The queue a push lands on when it does not name one. Queues are just names - there is
    // nothing to declare, and `staticphp queue work --queue=high,default` decides what a
    // given worker watches and in what order.
    'queue' => 'default',

    // Attempts before a job is moved to the failed table. Counts the first run, so 3 means
    // one attempt and two retries.
    //
    // Worth thinking about per job rather than accepting this everywhere: a job that calls
    // somebody else's API wants retries, and a job that will fail identically every time
    // wants one attempt and a fast trip to the failed table. Override at the call site with
    // Queue::push(..., tries: 1).
    'tries' => 3,

    // Seconds to wait before the next attempt. A list is one delay per attempt and repeats
    // its last entry, so [10, 60, 300] backs off and then keeps trying every five minutes.
    // A plain integer is the same delay every time.
    //
    // The first delay matters most: retrying a transient failure immediately usually just
    // fails again, and having done so counts against the attempts.
    'backoff' => [10, 60, 300],

    // How long a worker's claim on a job lasts, in seconds.
    //
    // Two jobs at once: it is the deadline after which another worker may pick up a job
    // whose worker died without finishing it, so it has to be longer than the slowest job
    // or a long job gets run twice concurrently. It is also the best-effort time limit
    // applied to the job itself, which needs ext-pcntl and cannot interrupt a call that is
    // blocked inside the database driver.
    'timeout' => 300,

    // Seconds a worker waits before looking again when there is nothing to do. Each look is
    // one indexed query, so 1 is cheap; raise it if a worker with nothing to do is showing
    // up in the slow query log, and accept that much extra latency on a quiet queue.
    'sleep' => 1,

    // Job names that are not class names, or that are class names which have since moved.
    //
    //     'handlers' => [
    //         'send-invoice' => \Application\Jobs\SendInvoice::class,
    //         'nightly-import' => fn () => new \Application\Jobs\Import($someDependency),
    //     ],
    //
    // A row outlives the deploy that wrote it, so a queued job naming a class that gets
    // renamed would otherwise fail on every attempt. An entry here is how the old name
    // keeps working; a callable is how a handler with constructor arguments gets them.
    //
    // @var array<string, class-string|callable(): \StaticPHP\Utils\Models\Queue\Handler>
    'handlers' => [],
];
