<?php

namespace StaticPHP\Utils\Models\Queue;

/**
 * The six things a queue backend has to do for a worker, and nothing else.
 *
 * Reporting - what is pending per queue, what failed and why, retrying a failed job - is
 * deliberately not here. Those answers are shaped by the store: a table can be grouped and
 * counted, a redis stream has to be totalled from its length and its consumer group. A
 * driver that can answer them implements QueueReports as well, and the commands ask for
 * both; keeping them apart means a backend that runs jobs without being able to list them
 * is still a usable backend rather than one forced to fake half an interface.
 */
interface QueueInterface
{
    /**
     * Queue a job.
     *
     * Must be safe to call inside a transaction on the application's own connection, and on
     * a database driver that is the point: the job appears when the work that caused it
     * commits, and never if it rolls back.
     *
     * @access public
     * @param  string               $name        Handler class, or a key of config['queue']['handlers']
     * @param  array<string, mixed> $payload
     * @param  int                  $delay       Seconds before it may run
     * @param  string               $queue
     * @param  int                  $priority    Higher runs first
     * @param  ?string              $unique      At most one pending job per key, when set
     * @param  int                  $maxAttempts
     * @return int Job id, or the id of the job already holding $unique
     */
    public function push(
        string $name,
        array $payload,
        int $delay,
        string $queue,
        int $priority,
        ?string $unique,
        int $maxAttempts
    ): int;

    /**
     * Claim the next due job, or null when there is nothing to do.
     *
     * The claim lasts $timeout seconds. A worker that dies without completing the job must
     * leave it claimable again once that runs out, because it will not be back to say so.
     *
     * @access public
     * @param  list<string> $queues  In precedence order: the first with work wins
     * @param  int          $timeout Visibility timeout in seconds
     * @param  string       $worker  Identifies the claimant, for reading the backlog by hand
     * @return ?Job
     */
    public function reserve(array $queues, int $timeout, string $worker): ?Job;

    /**
     * The job is done. Forget it.
     *
     * @access public
     * @param  Job $job
     * @return void
     */
    public function delete(Job $job): void;

    /**
     * Put the job back for another attempt.
     *
     * @access public
     * @param  Job    $job
     * @param  int    $delay Seconds before it may be picked up again
     * @param  string $error Why, or '' when the handler released it on purpose
     * @return void
     */
    public function release(Job $job, int $delay, string $error): void;

    /**
     * The job is out of attempts. Keep it somewhere a human will find it.
     *
     * @access public
     * @param  Job    $job
     * @param  string $error
     * @return void
     */
    public function fail(Job $job, string $error): void;

    /**
     * How many jobs could be picked up right now.
     *
     * Excludes delayed jobs that are not due and jobs another worker holds, so this is the
     * backlog rather than the row count.
     *
     * @access public
     * @param  ?string $queue Null counts every queue
     * @return int
     */
    public function pending(?string $queue = null): int;
}
