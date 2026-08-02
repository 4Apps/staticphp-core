<?php

namespace StaticPHP\Utils\Models\Throttle;

/**
 * What the throttle knows about one key, at the moment it was asked.
 *
 * A value object rather than a bare bool, because every caller that denies a request also
 * has to tell the client when to come back, and a bool makes that a second lookup.
 */
readonly class Attempt
{
    /**
     * @access public
     * @param bool $allowed    Whether this attempt is within the limit
     * @param int  $limit      The limit it was measured against
     * @param int  $hits       Attempts recorded in the current window, including this one
     * @param int  $remaining  Attempts left before the limit is reached, never below zero
     * @param int  $retryAfter Seconds until the window resets, 0 while allowed
     * @param int  $resetAt    Unix timestamp the window resets at
     */
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $hits,
        public int $remaining,
        public int $retryAfter,
        public int $resetAt,
    ) {
    }

    /**
     * The response headers that describe this attempt.
     *
     * Retry-After is the one browsers and well behaved clients act on, and it only appears
     * once the limit is reached - sending it on an allowed request tells a client to back
     * off when it does not need to.
     *
     * @access public
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'X-RateLimit-Limit' => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $this->remaining,
            'X-RateLimit-Reset' => (string) $this->resetAt,
        ];

        if ($this->allowed === false) {
            $headers['Retry-After'] = (string) $this->retryAfter;
        }

        return $headers;
    }
}
