<?php

namespace StaticPHP\Tests\Utils\Models\Migrations;

use PDO;
use PHPUnit\Framework\TestCase;
use StaticPHP\Utils\Models\Migrations\Commands;
use StaticPHP\Utils\Models\Migrations\Drivers\Driver;
use StaticPHP\Utils\Models\Migrations\MigrationError;
use StaticPHP\Utils\Models\Migrations\Tracker;

/**
 * The whole command surface, against a real database.
 *
 * SQLite is used because it has transactional DDL like Postgres and needs no server, so
 * the full state machine is exercised for real in a temp file with no containers. The
 * MySQL-specific path - claim before running, because DDL cannot be rolled back - is
 * covered separately by the integration script, since it cannot be reproduced here.
 */
class EngineTest extends TestCase
{
    private string $dir;
    private string $dbFile;
    private PDO $pdo;
    private Tracker $tracker;
    private Commands $commands;
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $suffix = bin2hex(random_bytes(6));
        $this->dir = sys_get_temp_dir() . "/sp_migrations_{$suffix}";
        $this->dbFile = sys_get_temp_dir() . "/sp_migrations_{$suffix}.sqlite";
        mkdir($this->dir);

        $dsn = "sqlite:{$this->dbFile}";
        $this->pdo = new PDO($dsn, '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->tracker = new Tracker($this->pdo, Driver::forPdo($this->pdo, $dsn), 'migrations');
        $this->commands = new Commands(
            $this->pdo,
            $this->tracker,
            $this->dir,
            function (string $line = ''): void {
                $this->lines[] = $line;
            }
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        foreach ([$this->dbFile, $this->dbFile . '.migrate.lock'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }

    private function outputText(): string
    {
        return implode("\n", $this->lines);
    }

    private function reset(): void
    {
        $this->lines = [];
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return list<string>
     */
    private function trackedNames(): array
    {
        $statement = $this->pdo->query('SELECT name FROM migrations ORDER BY name');
        $this->assertNotFalse($statement);

        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /*
    | apply
    */

    public function testApplyRunsPendingMigrationsAndRecordsThem(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->write('2026-08-01-110000-posts.sql', 'CREATE TABLE posts (id INTEGER PRIMARY KEY);');

        $this->assertSame(0, $this->commands->apply(false, null, 'test'));
        $this->assertTrue($this->tableExists('users'));
        $this->assertTrue($this->tableExists('posts'));
        $this->assertSame(
            ['2026-08-01-100000-users.sql', '2026-08-01-110000-posts.sql'],
            $this->trackedNames()
        );
    }

    public function testApplyIsIdempotentOnceEverythingHasRun(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->commands->apply(false, null, 'test');

        $this->reset();
        $this->assertSame(0, $this->commands->apply(false, null, 'test'));
        $this->assertStringContainsString('up to date', $this->outputText());
    }

    public function testApplyRunsMigrationsInChronologicalOrder(): void
    {
        // The second depends on the first, so a wrong order fails outright
        $this->write('2026-08-02-100000-add-column.sql', 'ALTER TABLE users ADD COLUMN email TEXT;');
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');

        $this->assertSame(0, $this->commands->apply(false, null, 'test'));
    }

    public function testDryRunChangesNothing(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');

        $this->assertSame(0, $this->commands->apply(true, null, 'test'));
        $this->assertFalse($this->tableExists('users'));
        $this->assertSame([], $this->trackedNames());
        $this->assertStringContainsString('would apply', $this->outputText());
    }

    public function testToStopsAfterTheNamedMigration(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->write('2026-08-02-100000-posts.sql', 'CREATE TABLE posts (id INTEGER PRIMARY KEY);');

        $this->assertSame(0, $this->commands->apply(false, '2026-08-01-100000', 'test'));
        $this->assertTrue($this->tableExists('users'));
        $this->assertFalse($this->tableExists('posts'));
    }

    public function testToRejectsAnUnknownPrefix(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');

        $this->assertSame(1, $this->commands->apply(false, '1999-01-01-000000', 'test'));
        $this->assertStringContainsString('no migration with prefix', $this->outputText());
    }

    /*
    | Failure
    |
    | SQLite has transactional DDL, so a failing migration must leave no trace at all -
    | neither its tables nor a tracking row.
    */

    public function testAFailingMigrationIsRolledBackAndNotRecorded(): void
    {
        $this->write(
            '2026-08-01-100000-broken.sql',
            "CREATE TABLE good (id INTEGER PRIMARY KEY);\nCREATE TABLE good (id INTEGER PRIMARY KEY);"
        );

        $this->assertSame(1, $this->commands->apply(false, null, 'test'));
        $this->assertFalse($this->tableExists('good'));
        $this->assertSame([], $this->trackedNames());
        $this->assertStringContainsString('Rolled back', $this->outputText());
    }

    public function testALaterFailureLeavesEarlierMigrationsApplied(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->write('2026-08-02-100000-broken.sql', 'THIS IS NOT SQL;');

        $this->assertSame(1, $this->commands->apply(false, null, 'test'));
        $this->assertTrue($this->tableExists('users'));
        $this->assertSame(['2026-08-01-100000-users.sql'], $this->trackedNames());
    }

    /*
    | Validation happens before any execution
    */

    public function testAMetaCommandAnywhereInTheQueueStopsEverything(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->write('2026-08-02-100000-dumped.sql', "\\restrict abc\nSELECT 1;");

        $this->assertSame(1, $this->commands->apply(false, null, 'test'));
        $this->assertStringContainsString('meta-command', $this->outputText());

        // The first file is valid, but nothing ran - the queue is validated as a whole
        $this->assertFalse($this->tableExists('users'));
        $this->assertSame([], $this->trackedNames());
    }

    /*
    | DRIFT
    */

    public function testEditingAnAppliedFileBlocksTheNextApply(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->commands->apply(false, null, 'test');

        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY, x TEXT);');
        $this->write('2026-08-02-100000-posts.sql', 'CREATE TABLE posts (id INTEGER PRIMARY KEY);');

        $this->reset();
        $this->assertSame(1, $this->commands->apply(false, null, 'test'));
        $this->assertStringContainsString('DRIFT', $this->outputText());
        $this->assertFalse($this->tableExists('posts'));
    }

    public function testRepairClearsDrift(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->commands->apply(false, null, 'test');
        $this->write('2026-08-01-100000-users.sql', '-- deliberate edit\nCREATE TABLE users (id INTEGER PRIMARY KEY);');

        $this->assertSame(0, $this->commands->repair('2026-08-01-100000-users.sql'));

        $this->reset();
        $this->assertSame(0, $this->commands->status());
        $this->assertStringNotContainsString('DRIFT', $this->outputText());
    }

    public function testRepairRefusesAPath(): void
    {
        $this->assertSame(1, $this->commands->repair('../../etc/passwd'));
        $this->assertStringContainsString('plain migration filename', $this->outputText());
    }

    public function testRepairRefusesAMigrationThatWasNeverApplied(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');

        $this->assertSame(1, $this->commands->repair('2026-08-01-100000-users.sql'));
        $this->assertStringContainsString('nothing to repair', $this->outputText());
    }

    /*
    | MISSING
    */

    public function testADeletedFileBlocksTheNextApply(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->commands->apply(false, null, 'test');
        unlink($this->dir . '/2026-08-01-100000-users.sql');

        $this->reset();
        $this->assertSame(1, $this->commands->apply(false, null, 'test'));
        $this->assertStringContainsString('MISSING', $this->outputText());
    }

    public function testForgetClearsMissing(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->commands->apply(false, null, 'test');
        unlink($this->dir . '/2026-08-01-100000-users.sql');

        $this->assertSame(0, $this->commands->forget('2026-08-01-100000-users.sql'));
        $this->assertSame([], $this->trackedNames());

        // The table it created is deliberately left alone
        $this->assertTrue($this->tableExists('users'));
    }

    public function testForgetRefusesAnUnknownMigration(): void
    {
        $this->assertSame(1, $this->commands->forget('2026-08-01-100000-nope.sql'));
        $this->assertStringContainsString('no tracking row', $this->outputText());
    }

    /*
    | baseline
    */

    public function testBaselineRecordsWithoutExecuting(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');

        $this->assertSame(0, $this->commands->baseline(null, true, 'test', fn() => 'y'));
        $this->assertSame(['2026-08-01-100000-users.sql'], $this->trackedNames());
        $this->assertFalse($this->tableExists('users'));
    }

    public function testBaselineQuitWritesNothing(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');

        $this->assertSame(1, $this->commands->baseline(null, false, 'test', fn() => 'q'));
        $this->assertSame([], $this->trackedNames());
    }

    public function testBaselineSkipsWhatTheOperatorDeclines(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->write('2026-08-02-100000-posts.sql', 'CREATE TABLE posts (id INTEGER PRIMARY KEY);');

        $answers = ['y', 'n'];
        $prompt = function () use (&$answers): string {
            return array_shift($answers) ?? 'n';
        };

        $this->assertSame(0, $this->commands->baseline(null, false, 'test', $prompt));
        $this->assertSame(['2026-08-01-100000-users.sql'], $this->trackedNames());
    }

    public function testBaselineThenApplyRunsOnlyWhatIsLeft(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->commands->baseline(null, true, 'test', fn() => 'y');

        $this->write('2026-08-02-100000-posts.sql', 'CREATE TABLE posts (id INTEGER PRIMARY KEY);');

        $this->assertSame(0, $this->commands->apply(false, null, 'test'));
        $this->assertFalse($this->tableExists('users'));
        $this->assertTrue($this->tableExists('posts'));
    }

    /*
    | status
    */

    public function testStatusCheckFailsWhileAnythingIsPending(): void
    {
        $this->write('2026-08-01-100000-users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');

        $this->assertSame(1, $this->commands->status(true));

        $this->commands->apply(false, null, 'test');
        $this->assertSame(0, $this->commands->status(true));
    }

    public function testStatusOnAnEmptyDirectorySaysSo(): void
    {
        $this->assertSame(0, $this->commands->status());
        $this->assertStringContainsString('No migrations found', $this->outputText());
    }

    /*
    | new
    */

    public function testNewWritesAFileThatDiscoveryAccepts(): void
    {
        $this->assertSame(0, $this->commands->create('add users', (int) gmmktime(14, 30, 0, 8, 1, 2026)));
        $this->assertFileExists($this->dir . '/2026-08-01-143000-add-users.sql');

        // An empty template must not upset a subsequent status
        $this->assertSame(0, $this->commands->status());
    }

    public function testNewRefusesToOverwrite(): void
    {
        $this->commands->create('add users', (int) gmmktime(14, 30, 0, 8, 1, 2026));

        $this->assertSame(1, $this->commands->create('add users', (int) gmmktime(14, 30, 0, 8, 1, 2026)));
        $this->assertStringContainsString('already exists', $this->outputText());
    }

    /*
    | Tracking table adoption
    */

    public function testAnUnrelatedTableNamedMigrationsIsRefused(): void
    {
        $this->pdo->exec('CREATE TABLE unrelated (something TEXT)');

        $tracker = new Tracker($this->pdo, Driver::forPdo($this->pdo), 'unrelated');

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessageMatches('/is not a migration tracking table/');
        $tracker->ensureTable();
    }

    public function testAnInvalidTableNameIsRefused(): void
    {
        $this->expectException(MigrationError::class);
        new Tracker($this->pdo, Driver::forPdo($this->pdo), 'migrations; DROP TABLE users');
    }

    public function testEnsureTableIsSafeToCallRepeatedly(): void
    {
        $this->tracker->ensureTable();
        $this->tracker->ensureTable();

        $this->assertSame([], $this->tracker->appliedRows());
    }
}
