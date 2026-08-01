<?php

namespace StaticPHP\Tests\Utils\Models\Audit;

use PDO;
use PHPUnit\Framework\TestCase;
use StaticPHP\Utils\Models\Audit\Commands;

/**
 * install and prune against a real database and a real directory.
 */
class CommandsTest extends TestCase
{
    private const FILES = SP_PATH . '/Utils/Files/Audit';

    private string $dir;
    private string $dbFile;
    private PDO $pdo;
    private Commands $commands;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $suffix = bin2hex(random_bytes(6));
        $this->dir = sys_get_temp_dir() . "/sp_audit_cli_{$suffix}";
        $this->dbFile = sys_get_temp_dir() . "/sp_audit_cli_{$suffix}.sqlite";
        mkdir($this->dir);

        $this->pdo = new PDO("sqlite:{$this->dbFile}", '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->commands = new Commands($this->pdo, 'sqlite', function (string $line = ''): void {
            $this->lines[] = $line;
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);

        if (is_file($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function outputText(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * @return list<string>
     */
    private function written(): array
    {
        return array_map('basename', glob($this->dir . '/*.sql') ?: []);
    }

    /*
    | install
    */

    public function testInstallWritesATimestampedMigration(): void
    {
        $status = $this->commands->install($this->dir, self::FILES, 'audit_log', 1785715200);

        $this->assertEquals(0, $status);
        $this->assertEquals(['2026-08-03-000000-create-audit-log.sql'], $this->written());
        $this->assertStringContainsString('staticphp migrate apply', $this->outputText());
    }

    /**
     * The filename has to satisfy the migration discovery pattern, or the tool that is
     * supposed to apply it refuses to read the directory at all.
     */
    public function testTheGeneratedNameIsAValidMigrationFilename(): void
    {
        $this->commands->install($this->dir, self::FILES, 'audit_log', 1785715200);

        $this->assertMatchesRegularExpression(
            '/^(\d{4}-\d{2}-\d{2}-\d{6})-([a-z0-9-]+)\.sql$/',
            $this->written()[0]
        );
    }

    public function testTheGeneratedSchemaApplies(): void
    {
        $this->commands->install($this->dir, self::FILES, 'audit_log', 1785715200);

        $this->pdo->exec((string) file_get_contents($this->dir . '/' . $this->written()[0]));

        $columns = $this->pdo->query('PRAGMA table_info(audit_log)');
        $this->assertNotFalse($columns);

        $names = array_map(
            fn(mixed $row): string => (is_array($row) && is_scalar($row['name'] ?? null) ? (string) $row['name'] : ''),
            $columns->fetchAll()
        );

        foreach (['created_at', 'request_id', 'module', 'event', 'entity_type', 'old_values', 'new_values'] as $column) {
            $this->assertContains($column, $names);
        }
    }

    public function testARenamedTableRenamesItsIndexesToo(): void
    {
        $this->commands->install($this->dir, self::FILES, 'history_catalog', 1785715200);

        $sql = (string) file_get_contents($this->dir . '/' . $this->written()[0]);

        $this->assertStringNotContainsString('audit_log', $sql);
        $this->assertStringContainsString('CREATE TABLE history_catalog', $sql);
        $this->assertStringContainsString('idx_history_catalog_entity', $sql);

        // Two trails in one schema would otherwise collide on the index names
        $this->pdo->exec($sql);
    }

    public function testInstallRefusesToOverwrite(): void
    {
        $this->commands->install($this->dir, self::FILES, 'audit_log', 1785715200);
        $status = $this->commands->install($this->dir, self::FILES, 'audit_log', 1785715200);

        $this->assertEquals(1, $status);
        $this->assertStringContainsString('already exists', $this->outputText());
        $this->assertCount(1, $this->written());
    }

    public function testInstallReportsAMissingMigrationsDirectory(): void
    {
        $status = $this->commands->install($this->dir . '/nope', self::FILES, 'audit_log', 1785715200);

        $this->assertEquals(2, $status);
        $this->assertStringContainsString('no migrations directory', $this->outputText());
    }

    public function testInstallReportsAnUnsupportedDriver(): void
    {
        $commands = new Commands($this->pdo, 'oracle', function (string $line = ''): void {
            $this->lines[] = $line;
        });

        $this->assertEquals(2, $commands->install($this->dir, self::FILES, 'audit_log', 1785715200));
        $this->assertStringContainsString('no install template for oracle', $this->outputText());
    }

    /*
    | prune
    */

    private function seedTrail(): void
    {
        $this->commands->install($this->dir, self::FILES, 'audit_log', 1785715200);
        $this->pdo->exec((string) file_get_contents($this->dir . '/' . $this->written()[0]));

        $insert = $this->pdo->prepare(
            'INSERT INTO audit_log (created_at, event, entity_type, entity_id) VALUES (?, ?, ?, ?)'
        );

        foreach (['2024-01-01 10:00:00', '2025-06-01 10:00:00', '2026-07-01 10:00:00'] as $index => $date) {
            $insert->execute([$date, 'updated', 'people', (string) $index]);
        }
    }

    private function remaining(): int
    {
        $row = $this->pdo->query('SELECT COUNT(*) AS total FROM audit_log');
        $this->assertNotFalse($row);
        $value = $row->fetch();

        return (is_array($value) && is_numeric($value['total']) ? (int) $value['total'] : -1);
    }

    public function testPruneDeletesOnlyRowsOlderThanTheDate(): void
    {
        $this->seedTrail();

        $status = $this->commands->prune('audit_log', '2026-01-01', 10000, false);

        $this->assertEquals(0, $status);
        $this->assertEquals(1, $this->remaining());
    }

    public function testPruneBatchesUntilItIsDone(): void
    {
        $this->seedTrail();

        // One row per statement, so the loop has to run more than once to finish
        $this->commands->prune('audit_log', '2026-01-01', 1, false);

        $this->assertEquals(1, $this->remaining());
        $this->assertStringContainsString('Deleted 1/2', $this->outputText());
        $this->assertStringContainsString('Deleted 2/2', $this->outputText());
    }

    public function testDryRunCountsAndChangesNothing(): void
    {
        $this->seedTrail();

        $status = $this->commands->prune('audit_log', '2026-01-01', 10000, true);

        $this->assertEquals(0, $status);
        $this->assertEquals(3, $this->remaining());
        $this->assertStringContainsString('2 rows in audit_log older than 2026-01-01', $this->outputText());
        $this->assertStringContainsString('--dry-run', $this->outputText());
    }

    public function testPruneRejectsADateItCannotTrust(): void
    {
        $this->seedTrail();

        $this->assertEquals(2, $this->commands->prune('audit_log', 'yesterday', 10000, false));
        $this->assertEquals(3, $this->remaining());
        $this->assertStringContainsString('--before must be', $this->outputText());
    }

    public function testPruneRejectsATableNameThatIsNotAnIdentifier(): void
    {
        $this->seedTrail();

        $status = $this->commands->prune('audit_log; DROP TABLE audit_log', '2026-01-01', 10000, false);

        $this->assertEquals(2, $status);
        $this->assertEquals(3, $this->remaining());
    }

    public function testPruneRejectsAnEmptyBatch(): void
    {
        $this->seedTrail();

        $this->assertEquals(2, $this->commands->prune('audit_log', '2026-01-01', 0, false));
        $this->assertEquals(3, $this->remaining());
    }

    public function testPruneOnAnUnknownTableFailsWithoutAStackTrace(): void
    {
        $this->assertEquals(1, $this->commands->prune('audit_log', '2026-01-01', 10000, false));
        $this->assertStringContainsString('cannot read audit_log', $this->outputText());
    }
}
