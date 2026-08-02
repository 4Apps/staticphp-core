<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use PHPUnit\Framework\TestCase;
use Redis;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Queue\Queue;
use StaticPHP\Utils\Models\Queue\QueueRedis;

/**
 * A real redis server, or nothing.
 *
 * There is no in-memory redis the way there is an in-memory sqlite, and a hand written fake
 * would only ever prove the fake agrees with itself - the parts most worth testing here are
 * XAUTOCLAIM's idea of an idle entry and what a consumer group does with two readers, which
 * is exactly what a fake would get wrong. So these skip when there is no server rather than
 * pretending to have run.
 *
 * Point them somewhere with QUEUE_TEST_REDIS_HOST and QUEUE_TEST_REDIS_PORT; docker-compose
 * sets the first for the develop and testing services. Every test gets a key prefix of its
 * own and deletes it afterwards, so this is safe to aim at a server with other things on it
 * - which is also why nothing here calls FLUSHDB.
 */
abstract class QueueRedisCase extends TestCase
{
    protected QueueRedis $queue;
    protected Redis $redis;
    protected string $prefix;

    protected function setUp(): void
    {
        if (extension_loaded('redis') === false) {
            $this->markTestSkipped('ext-redis is not available');
        }

        $host = (getenv('QUEUE_TEST_REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('QUEUE_TEST_REDIS_PORT') ?: '6379');

        $redis = new Redis();

        try {
            $reached = ($redis->connect($host, $port, 1.0) && $redis->ping() !== false);
        } catch (\Throwable) {
            $reached = false;
        }

        if ($reached === false) {
            $this->markTestSkipped("no redis at {$host}:{$port}");
        }

        $this->redis = $redis;
        $this->prefix = 'qtest' . bin2hex(random_bytes(5)) . ':';
        $this->queue = new QueueRedis($redis, $this->prefix);

        Config::$items['queue'] = ['driver' => 'redis'];
        Queue::reset();
        Queue::setDriver($this->queue);
    }

    protected function tearDown(): void
    {
        Queue::setDriver(null);
        Queue::reset();
        unset(Config::$items['queue']);

        if (isset($this->redis) === true) {
            $this->forgetEverything();
            $this->redis->close();
        }
    }

    /**
     * @return void
     */
    private function forgetEverything(): void
    {
        $cursor = null;

        do {
            $keys = $this->redis->scan($cursor, $this->prefix . '*', 500);

            if (is_array($keys) === true && $keys !== []) {
                $this->redis->del($keys);
            }
        } while ($cursor > 0);
    }

    /**
     * The job hash, as redis holds it.
     *
     * @param  int $id
     * @return array<string, mixed>
     */
    protected function job(int $id): array
    {
        $hash = $this->redis->hGetAll($this->prefix . 'j:' . $id);

        return (is_array($hash) ? $hash : []);
    }

    /**
     * @param  int    $id
     * @param  string $field
     * @return string
     */
    protected function field(int $id, string $field): string
    {
        return $this->cell($this->job($id), $field);
    }

    /**
     * @param  array<string, mixed> $row
     * @param  string               $key
     * @return string
     */
    protected function cell(array $row, string $key): string
    {
        $value = ($row[$key] ?? null);

        return (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Bring a delayed job forward, rather than waiting for it.
     *
     * @param  string $queue
     * @param  int    $id
     * @param  int    $when
     * @return void
     */
    protected function makeDue(string $queue, int $id, int $when): void
    {
        $this->redis->zAdd($this->prefix . 'q:' . $queue . ':delayed', $when, (string) $id);
    }

    /**
     * @param  string $queue
     * @param  int    $priority
     * @return int
     */
    protected function streamLength(string $queue, int $priority = 0): int
    {
        $length = $this->redis->xLen($this->prefix . 'q:' . $queue . ':s:' . $priority);

        return (is_int($length) ? $length : 0);
    }

    /**
     * @param  string $key
     * @return bool
     */
    protected function exists(string $key): bool
    {
        return $this->redis->exists($this->prefix . $key) > 0;
    }
}
