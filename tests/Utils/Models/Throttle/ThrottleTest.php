<?php

namespace StaticPHP\Tests\Utils\Models\Throttle;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Cache\Cache;
use StaticPHP\Utils\Models\Cache\CacheFiles;
use StaticPHP\Utils\Models\Throttle\Throttle;

/**
 * The throttle against the file cache backend, so the counter really round trips through a
 * backend rather than through a double that always agrees.
 */
class ThrottleTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/sp_throttle_' . bin2hex(random_bytes(6));

        $this->backends([]);
        Cache::register(new CacheFiles(['path' => $this->path]), 'files');

        Config::$items['throttle'] = ['prefix' => 'test_'];
    }

    protected function tearDown(): void
    {
        $this->backends([]);
        unset(Config::$items['throttle']);

        if (is_dir($this->path) === false) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if (($item instanceof \SplFileInfo) === false) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->path);
    }

    /**
     * Cache::register() refuses a name it already holds and offers no way to let one go, so
     * the registry is emptied between tests here.
     *
     * @param array<string, mixed> $backends
     */
    private function backends(array $backends): void
    {
        $property = new \ReflectionProperty(Cache::class, 'backends');
        $property->setValue(null, $backends);
    }

    private function cacheKey(string $key): string
    {
        $method = new \ReflectionMethod(Throttle::class, 'cacheKey');
        $value = $method->invoke(null, $key);

        return (is_string($value) ? $value : '');
    }

    public function testTheFirstAttemptIsAllowedAndCounted(): void
    {
        $attempt = Throttle::hit('login:someone', 5, 900);

        $this->assertTrue($attempt->allowed);
        $this->assertSame(1, $attempt->hits);
        $this->assertSame(4, $attempt->remaining);
        $this->assertSame(0, $attempt->retryAfter);
        $this->assertSame(5, $attempt->limit);
    }

    public function testAttemptsAreAllowedUpToTheLimitAndDeniedAfterIt(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $attempt = Throttle::hit('login:someone', 3, 900);

            $this->assertTrue($attempt->allowed, "attempt {$i} should be allowed");
            $this->assertSame(3 - $i, $attempt->remaining);
        }

        $denied = Throttle::hit('login:someone', 3, 900);

        $this->assertFalse($denied->allowed);
        $this->assertSame(4, $denied->hits);
        $this->assertSame(0, $denied->remaining);
        $this->assertGreaterThan(0, $denied->retryAfter);
        $this->assertLessThanOrEqual(900, $denied->retryAfter);
    }

    public function testCountersAreSeparatePerKey(): void
    {
        Throttle::hit('login:one', 2, 900);
        Throttle::hit('login:one', 2, 900);

        $other = Throttle::hit('login:two', 2, 900);

        $this->assertTrue($other->allowed);
        $this->assertSame(1, $other->hits);
    }

    /**
     * Hammering past the limit must not push the reset further out, otherwise a client that
     * keeps trying locks itself out for longer than the window it was told about.
     */
    public function testAttemptsPastTheLimitDoNotExtendTheWindow(): void
    {
        $first = Throttle::hit('login:someone', 1, 900);

        for ($i = 0; $i < 5; $i++) {
            $later = Throttle::hit('login:someone', 1, 900);
        }

        $this->assertSame($first->resetAt, $later->resetAt);
    }

    public function testAWindowThatHasPassedStartsCountingAgain(): void
    {
        Throttle::hit('login:someone', 1, 900);
        $denied = Throttle::hit('login:someone', 1, 900);
        $this->assertFalse($denied->allowed);

        // Rather than sleeping out a real window, age the stored counter
        Cache::set($this->cacheKey('login:someone'), ['hits' => 9, 'reset' => time() - 1], 900, null);

        $fresh = Throttle::hit('login:someone', 1, 900);

        $this->assertTrue($fresh->allowed);
        $this->assertSame(1, $fresh->hits);
    }

    public function testClearForgetsTheCounter(): void
    {
        Throttle::hit('login:someone', 2, 900);
        Throttle::hit('login:someone', 2, 900);
        $this->assertFalse(Throttle::hit('login:someone', 2, 900)->allowed);

        Throttle::clear('login:someone');

        $after = Throttle::hit('login:someone', 2, 900);
        $this->assertTrue($after->allowed);
        $this->assertSame(1, $after->hits);
    }

    public function testCheckReportsTheStateWithoutCountingAnAttempt(): void
    {
        Throttle::hit('login:someone', 5, 900);
        Throttle::hit('login:someone', 5, 900);

        $checked = Throttle::check('login:someone', 5);
        $this->assertSame(2, $checked->hits);
        $this->assertSame(3, $checked->remaining);

        $again = Throttle::check('login:someone', 5);
        $this->assertSame(2, $again->hits, 'check() must not have counted the first check');
    }

    public function testCheckOnAnUnknownKeyReportsTheFullAllowance(): void
    {
        $checked = Throttle::check('login:nobody', 5);

        $this->assertTrue($checked->allowed);
        $this->assertSame(0, $checked->hits);
        $this->assertSame(5, $checked->remaining);
    }

    /**
     * Callers key on emails and ip addresses, which should not be legible to anything that
     * can list the cache.
     */
    public function testTheCallersKeyIsNotStoredInTheClear(): void
    {
        $stored = $this->cacheKey('login:someone@example.com');

        $this->assertStringNotContainsString('someone@example.com', $stored);
        $this->assertStringStartsWith('test_', $stored);
    }

    public function testHeadersDescribeTheAttempt(): void
    {
        $allowed = Throttle::hit('login:someone', 2, 900);

        $this->assertSame(
            ['X-RateLimit-Limit' => '2', 'X-RateLimit-Remaining' => '1', 'X-RateLimit-Reset' => (string) $allowed->resetAt],
            $allowed->headers()
        );

        Throttle::hit('login:someone', 2, 900);
        $denied = Throttle::hit('login:someone', 2, 900);

        $this->assertArrayHasKey('Retry-After', $denied->headers());
        $this->assertSame('0', $denied->headers()['X-RateLimit-Remaining']);
    }

    public function testANonsenseLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Throttle::hit('login:someone', 0, 900);
    }

    public function testANonsenseWindowIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Throttle::hit('login:someone', 5, 0);
    }

    /**
     * With no backend registered Cache throws. The default is to let the request through
     * rather than to take the application down with the cache.
     */
    public function testAnUnreachableBackendFailsOpenByDefault(): void
    {
        $this->backends([]);

        $log = sys_get_temp_dir() . '/sp_throttle_log_' . bin2hex(random_bytes(4)) . '.txt';
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $log);

        try {
            $attempt = Throttle::hit('login:someone', 5, 900);
        } finally {
            ini_set('error_log', $previous);
        }

        $this->assertTrue($attempt->allowed);
        $this->assertSame(5, $attempt->remaining);

        $logged = (string) file_get_contents($log);
        unlink($log);

        $this->assertStringContainsString('Throttle: No cache backend is registered', $logged);
    }

    public function testAnUnreachableBackendCanFailClosedInstead(): void
    {
        $this->backends([]);
        Config::$items['throttle'] = ['fail_open' => false];

        $this->expectException(\Exception::class);

        Throttle::hit('login:someone', 5, 900);
    }

    public function testStoredRubbishStartsAFreshWindowRatherThanThrowing(): void
    {
        Cache::set($this->cacheKey('login:someone'), 'not an array', 900, null);

        $attempt = Throttle::hit('login:someone', 5, 900);

        $this->assertTrue($attempt->allowed);
        $this->assertSame(1, $attempt->hits);
    }
}
