<?php

namespace StaticPHP\Tests\Utils\Models\Queue;

use PDO;
use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Db;
use StaticPHP\Utils\Models\Queue\Queue;
use StaticPHP\Utils\Models\Queue\QueueDatabase;

/**
 * A real sqlite database with the shipped schema in it.
 *
 * The schema is loaded from src/Utils/Files/Queue/install.sqlite.sql rather than written
 * out here, so the suite exercises the file an application actually installs. A copy would
 * drift from it, and the first sign would be a column that exists in the tests and nowhere
 * else.
 */
abstract class QueueCase extends TestCase
{
    protected string $connection;
    protected QueueDatabase $queue;

    protected function setUp(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $this->connection = 'queue_test_' . bin2hex(random_bytes(6));

        $pdo = Db::init($this->connection, [
            'string' => 'sqlite::memory:',
            'username' => '',
            'password' => '',
            'wrap_column' => '"',
        ]);

        $schema = file_get_contents(SP_PATH . '/Utils/Files/Queue/install.sqlite.sql');
        $this->assertIsString($schema, 'the shipped sqlite schema should be readable');
        $pdo->exec($schema);

        $this->queue = new QueueDatabase($this->connection, 'queue_jobs', 'queue_failed_jobs');

        Config::$items['queue'] = ['connection' => $this->connection];
        Queue::reset();
        Queue::setDriver($this->queue);
    }

    protected function tearDown(): void
    {
        Queue::setDriver(null);
        Queue::reset();
        unset(Config::$items['queue']);
        Db::close($this->connection);
    }

    /**
     * @param  string $table
     * @return list<array<string, mixed>>
     */
    protected function rows(string $table = 'queue_jobs'): array
    {
        $out = [];
        foreach (Db::fetchAll("SELECT * FROM {$table} ORDER BY id", [], $this->connection) as $row) {
            if (is_array($row) === true) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  int $id
     * @return ?array<string, mixed>
     */
    protected function row(int $id): ?array
    {
        $row = Db::fetch('SELECT * FROM queue_jobs WHERE id = ?', [$id], $this->connection);

        if (is_array($row) === false) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * Pretend the worker holding this job died some time ago.
     *
     * @param  int $id
     * @param  int $secondsAgo
     * @return void
     */
    protected function expireReservation(int $id, int $secondsAgo = 10): void
    {
        Db::query(
            'UPDATE queue_jobs SET reserved_until = ? WHERE id = ?',
            [gmdate('Y-m-d H:i:s', time() - $secondsAgo), $id],
            $this->connection
        );
    }

    /**
     * @param  ?array<string, mixed> $row
     * @param  string                $column
     * @return string
     */
    protected function text(?array $row, string $column): string
    {
        $value = ($row === null ? null : ($row[$column] ?? null));

        return (is_scalar($value) ? (string) $value : '');
    }

    /**
     * @param  ?array<string, mixed> $row
     * @param  string                $column
     * @return int
     */
    protected function number(?array $row, string $column): int
    {
        $value = ($row === null ? null : ($row[$column] ?? null));

        return (is_numeric($value) ? (int) $value : 0);
    }
}
