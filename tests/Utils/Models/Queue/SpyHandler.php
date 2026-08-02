<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use StaticPHP\Utils\Models\Queue\Handler;
use StaticPHP\Utils\Models\Queue\Job;

/**
 * A handler the tests can watch and steer.
 *
 * A named class rather than an anonymous one because half of what is under test is the
 * resolution of a job name into a handler, and an anonymous class has no name to resolve.
 */
class SpyHandler implements Handler
{
    /** @var list<array{payload: array<string, mixed>, attempt: int}> */
    public static array $calls = [];

    /** @var ?callable(array<string, mixed>, Job): void */
    public static $behaviour = null;

    /**
     * @return void
     */
    public static function reset(): void
    {
        self::$calls = [];
        self::$behaviour = null;
    }

    /**
     * @param  array<string, mixed> $payload
     * @param  Job                  $job
     * @return void
     */
    public function handle(array $payload, Job $job): void
    {
        self::$calls[] = ['payload' => $payload, 'attempt' => $job->attempts];

        if (self::$behaviour !== null) {
            (self::$behaviour)($payload, $job);
        }
    }
}
