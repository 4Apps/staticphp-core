<?php

namespace StaticPHP\Utils\Models\Queue;

/**
 * What a worker runs.
 *
 * The payload arrives as the array that was pushed, decoded from JSON, so it holds scalars
 * and arrays and nothing else. That is deliberate: a job row outlives the deploy that wrote
 * it, and a handler that expects a live object would break the moment the class it was
 * serialised from changed shape. Push identifiers and load the records here.
 *
 * Returning normally completes the job and deletes the row. Throwing releases it for
 * another attempt, or fails it once the attempts run out.
 */
interface Handler
{
    /**
     * @access public
     * @param  array<string, mixed> $payload What was pushed
     * @param  Job                  $job     The attempt, for jobs that care how many are left
     * @return void
     */
    public function handle(array $payload, Job $job): void;
}
