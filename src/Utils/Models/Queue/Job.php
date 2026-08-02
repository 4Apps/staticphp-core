<?php

namespace StaticPHP\Utils\Models\Queue;

/**
 * One reserved attempt at one job.
 *
 * `attempts` counts this one, because the reservation increments it. A job on its third
 * attempt out of three is on its last, which is what isLastAttempt() answers - the
 * question a handler asks when it wants to warn somebody before the trail goes cold.
 */
class Job
{
    private ?int $releaseDelay = null;

    /**
     * @access public
     * @param int                  $id
     * @param string               $queue
     * @param string               $name        Handler class or configured alias
     * @param array<string, mixed> $payload
     * @param string               $payloadJson The row as written, kept so failing a job records what it was given
     * @param int                  $attempts    Including this attempt
     * @param int                  $maxAttempts
     */
    public function __construct(
        public readonly int $id,
        public readonly string $queue,
        public readonly string $name,
        public readonly array $payload,
        public readonly string $payloadJson,
        public readonly int $attempts,
        public readonly int $maxAttempts,
    ) {
    }

    /**
     * Put the job back instead of completing it, without raising an exception.
     *
     * For work that did not fail but is not ready - a document still being generated
     * upstream, a rate limit that says come back later. It does not count against the
     * attempts the way a throw does, so a job that releases itself forever will do exactly
     * that; give it a deadline in its own payload if that matters.
     *
     * @access public
     * @param  int $delay Seconds before it may be picked up again
     * @return void
     */
    public function release(int $delay = 0): void
    {
        $this->releaseDelay = max(0, $delay);
    }

    /**
     * @access public
     * @return bool
     */
    public function wasReleased(): bool
    {
        return $this->releaseDelay !== null;
    }

    /**
     * @access public
     * @return int
     */
    public function releaseDelay(): int
    {
        return ($this->releaseDelay ?? 0);
    }

    /**
     * @access public
     * @return bool
     */
    public function isLastAttempt(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }
}
