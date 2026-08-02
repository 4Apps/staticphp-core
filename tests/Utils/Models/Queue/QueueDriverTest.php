<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Queue\Queue;
use StaticPHP\Utils\Models\Queue\QueueDatabase;
use StaticPHP\Utils\Models\Queue\QueueError;

/**
 * Which backend the facade builds, and what it does about one it does not recognise.
 *
 * Neither driver is connected to here - QueueDatabase does not open its connection until
 * something asks it for a row - so this needs no server of any kind.
 */
class QueueDriverTest extends TestCase
{
    protected function setUp(): void
    {
        Queue::setDriver(null);
        Queue::reset();
    }

    protected function tearDown(): void
    {
        Queue::setDriver(null);
        Queue::reset();
        unset(Config::$items['queue']);
    }

    public function testTheDatabaseDriverIsTheDefault(): void
    {
        $this->assertInstanceOf(QueueDatabase::class, Queue::driver());
    }

    public function testTheConfiguredTableNamesReachTheDriver(): void
    {
        Config::$items['queue'] = [
            'driver' => 'database',
            'connection' => 'reporting',
            'table' => 'bg_jobs',
            'failed_table' => 'bg_dead_jobs',
        ];
        Queue::reset();

        $driver = Queue::driver();

        $this->assertInstanceOf(QueueDatabase::class, $driver);
        $this->assertSame('reporting', $driver->connection());
        $this->assertSame('bg_jobs', $driver->table());
        $this->assertSame('bg_dead_jobs', $driver->failedTable());
    }

    public function testADriverNameNobodyImplementsIsRefused(): void
    {
        Config::$items['queue'] = ['driver' => 'rabbitmq'];
        Queue::reset();

        $this->expectException(QueueError::class);
        $this->expectExceptionMessage('"database" or "redis"');

        Queue::driver();
    }

    public function testSettingArrayIgnoresAnEntryThatIsNotABlockOfSettings(): void
    {
        Config::$items['queue'] = ['redis' => 'localhost:6379'];
        Queue::reset();

        $this->assertSame([], Queue::settingArray('redis'));
    }

    public function testSettingArrayKeepsWhatTheApplicationConfigured(): void
    {
        Config::$items['queue'] = ['redis' => ['hostname' => 'cache-1', 'port' => 6380]];
        Queue::reset();

        $this->assertSame(['hostname' => 'cache-1', 'port' => 6380], Queue::settingArray('redis'));
    }
}
