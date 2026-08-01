<?php

namespace StaticPHP\Tests\Utils\Models\Audit;

use PDO;
use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Audit\Audit;
use StaticPHP\Utils\Models\Audit\AuditError;
use StaticPHP\Utils\Models\Audit\AuditEvent;
use StaticPHP\Utils\Models\Audit\Diff;
use StaticPHP\Utils\Models\Audit\Store;
use StaticPHP\Utils\Models\Db;

/**
 * The audit trail against a real database.
 *
 * SQLite for the same reason the migrations and translation suites use it: a real driver
 * with real transactions in a temp file, so "the audit row rolls back with the change" is
 * exercised rather than assumed. The shipped schema is loaded rather than a copy, so the
 * tests fail if the code and the .sql file drift apart.
 */
class AuditTest extends TestCase
{
    private const SCHEMA = SP_PATH . '/Utils/Files/Audit/install.sqlite.sql';

    private string $dbFile;
    private string $connection;

    protected function setUp(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $suffix = bin2hex(random_bytes(6));
        $this->dbFile = sys_get_temp_dir() . "/sp_audit_{$suffix}.sqlite";
        $this->connection = "audit_test_{$suffix}";

        $pdo = Db::init($this->connection, [
            'string' => "sqlite:{$this->dbFile}",
            'username' => '',
            'password' => '',
            'wrap_column' => '"',
        ]);

        $pdo->exec((string) file_get_contents(self::SCHEMA));
        $pdo->exec(
            'CREATE TABLE people ('
            . ' id integer PRIMARY KEY AUTOINCREMENT,'
            . ' name text NOT NULL DEFAULT "",'
            . ' city text NOT NULL DEFAULT "",'
            . ' active integer NOT NULL DEFAULT 1,'
            . ' password text NOT NULL DEFAULT ""'
            . ')'
        );

        Audit::reset();
        Config::$items['audit'] = $this->settings();
    }

    protected function tearDown(): void
    {
        Audit::reset();
        unset(Config::$items['audit']);
        Db::close($this->connection);

        if (is_file($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'connection' => $this->connection,
            'table' => 'audit_log',
            'strict' => true,
            'max_rows' => 1000,
            'id_key' => 'id',
            'actor' => fn(): array => ['type' => 'user', 'id' => '42', 'name' => 'Anna Berzina'],
            'context' => fn(): array => [
                'url' => '/people/edit',
                'ip_address' => '10.0.0.9',
                'user_agent' => 'phpunit',
            ],
            'exclude' => ['people' => ['password']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function trail(): array
    {
        $rows = [];
        foreach (Db::fetchAll('SELECT * FROM audit_log ORDER BY id', [], $this->connection) as $row) {
            $this->assertIsArray($row);

            $columns = [];
            foreach ($row as $key => $value) {
                $columns[(string) $key] = $value;
            }

            $rows[] = $columns;
        }

        return $rows;
    }

    /**
     * A stored json column as the text that is actually in the table.
     */
    private function raw(mixed $value): string
    {
        return (is_scalar($value) ? (string) $value : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        $decoded = json_decode((is_string($json) ? $json : ''), true);
        $this->assertIsArray($decoded);

        $values = [];
        foreach ($decoded as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }

    private function seed(string $name = 'Anna', string $city = 'Dobele'): int
    {
        Db::insert('people', ['name' => $name, 'city' => $city], $this->connection);

        return (int) Db::lastInsertId('', false, $this->connection);
    }

    /*
    | Writing
    */

    public function testInsertRecordsTheWholeRowAsNew(): void
    {
        Audit::insert('people', ['name' => 'Anna', 'city' => 'Dobele'], module: 'catalog');

        $trail = $this->trail();
        $this->assertCount(1, $trail);

        $row = $trail[0];
        $this->assertEquals(AuditEvent::CREATED, $row['event']);
        $this->assertEquals('people', $row['entity_type']);
        $this->assertEquals('catalog', $row['module']);
        $this->assertNull($row['old_values']);
        $this->assertEquals(['name' => 'Anna', 'city' => 'Dobele'], $this->decode($row['new_values']));
    }

    public function testInsertRecordsTheGeneratedKey(): void
    {
        Audit::insert('people', ['name' => 'Anna']);
        Audit::insert('people', ['name' => 'Girts']);

        $trail = $this->trail();

        $this->assertEquals('1', $trail[0]['entity_id']);
        $this->assertEquals('2', $trail[1]['entity_id']);
    }

    public function testUpdateRecordsOnlyTheColumnsThatChanged(): void
    {
        $id = $this->seed();

        Audit::update('people', ['name' => 'Anna Berzina', 'city' => 'Dobele'], ['id' => $id]);

        $trail = $this->trail();
        $this->assertCount(1, $trail);

        $this->assertEquals(AuditEvent::UPDATED, $trail[0]['event']);
        $this->assertEquals((string) $id, $trail[0]['entity_id']);
        $this->assertEquals(['name' => 'Anna'], $this->decode($trail[0]['old_values']));
        $this->assertEquals(['name' => 'Anna Berzina'], $this->decode($trail[0]['new_values']));
    }

    public function testAnUpdateThatChangesNothingRecordsNothing(): void
    {
        $id = $this->seed();

        Audit::update('people', ['name' => 'Anna'], ['id' => $id]);

        $this->assertCount(0, $this->trail());
    }

    /**
     * ddz's helper takes a single before-row and writes a single entry, so a condition that
     * matched five rows recorded one change and lost the other four.
     */
    public function testUpdateRecordsOneEntryPerAffectedRow(): void
    {
        $this->seed('Anna', 'Dobele');
        $this->seed('Girts', 'Dobele');
        $this->seed('Ilze', 'Jelgava');

        Audit::update('people', ['city' => 'Riga'], ['city' => 'Dobele']);

        $trail = $this->trail();
        $this->assertCount(2, $trail);
        $this->assertEquals('1', $trail[0]['entity_id']);
        $this->assertEquals('2', $trail[1]['entity_id']);
    }

    public function testDeleteRecordsTheWholeRow(): void
    {
        $id = $this->seed();

        Audit::delete('people', ['id' => $id]);

        $trail = $this->trail();
        $this->assertCount(1, $trail);
        $this->assertEquals(AuditEvent::DELETED, $trail[0]['event']);
        $this->assertNull($trail[0]['new_values']);

        $old = $this->decode($trail[0]['old_values']);
        $this->assertEquals('Anna', $old['name']);
        $this->assertEquals('Dobele', $old['city']);

        $this->assertCount(0, Db::fetchAll('SELECT * FROM people', [], $this->connection));
    }

    public function testExcludedColumnsNeverReachTheTrail(): void
    {
        Audit::insert('people', ['name' => 'Anna', 'password' => 'hunter2']);

        $trail = $this->trail();
        $new = $this->decode($trail[0]['new_values']);

        $this->assertEquals(Diff::REDACTED, $new['password']);
        $this->assertStringNotContainsString('hunter2', $this->raw($trail[0]['new_values']));
    }

    public function testExcludedColumnsAreRedactedOnDeleteToo(): void
    {
        Db::insert('people', ['name' => 'Anna', 'password' => 'hunter2'], $this->connection);

        Audit::delete('people', ['name' => 'Anna']);

        $this->assertStringNotContainsString('hunter2', $this->raw($this->trail()[0]['old_values']));
    }

    /*
    | Actor, context and grouping
    */

    public function testTheActorAndRequestContextAreRecorded(): void
    {
        Audit::insert('people', ['name' => 'Anna']);

        $row = $this->trail()[0];

        $this->assertEquals('user', $row['actor_type']);
        $this->assertEquals('42', $row['actor_id']);
        $this->assertEquals('Anna Berzina', $row['actor_name']);
        $this->assertEquals('/people/edit', $row['url']);
        $this->assertEquals('10.0.0.9', $row['ip_address']);
        $this->assertEquals('phpunit', $row['user_agent']);
    }

    /**
     * A caller that names the actor itself - an import recording who asked for it rather
     * than who happens to be logged in - keeps what it passed.
     */
    public function testAnExplicitActorIsNotOverwritten(): void
    {
        Audit::record(new AuditEvent(
            event: 'imported',
            entityType: 'people',
            actorType: 'cron',
            actorId: 'nightly-sync',
            actorName: 'Nightly sync'
        ));

        $row = $this->trail()[0];

        $this->assertEquals('cron', $row['actor_type']);
        $this->assertEquals('nightly-sync', $row['actor_id']);
    }

    public function testEveryChangeInOneRunSharesARequestId(): void
    {
        $id = $this->seed();
        Audit::update('people', ['name' => 'Changed'], ['id' => $id]);
        Audit::insert('people', ['name' => 'Another']);

        $trail = $this->trail();

        $this->assertNotEquals('', $trail[0]['request_id']);
        $this->assertEquals($trail[0]['request_id'], $trail[1]['request_id']);
        $this->assertEquals(Audit::requestId(), $trail[0]['request_id']);
    }

    public function testTagsAndContextAreStored(): void
    {
        Audit::insert('people', ['name' => 'Anna'], tags: ['gdpr', 'import'], context: ['batch' => 7]);

        $row = $this->trail()[0];

        $this->assertEquals(['gdpr', 'import'], json_decode($this->raw($row['tags']), true));
        $this->assertEquals(['batch' => 7], $this->decode($row['context']));
    }

    /*
    | Transactions and failure
    */

    /**
     * The audit row is written on the caller's connection inside the caller's transaction,
     * so a rolled back change cannot leave a record claiming it happened.
     */
    public function testARolledBackChangeTakesItsAuditRowWithIt(): void
    {
        Db::beginTransaction($this->connection);
        Audit::insert('people', ['name' => 'Anna']);
        Db::rollBack($this->connection);

        $this->assertCount(0, $this->trail());
        $this->assertCount(0, Db::fetchAll('SELECT * FROM people', [], $this->connection));
    }

    /**
     * Writing the values a row already holds is legitimate, so a condition matching nothing
     * cannot be an error - but it is also what a mistyped condition looks like, and an
     * audit trail that records neither is the wrong place to find that out silently.
     */
    public function testAConditionThatMatchesNothingSaysSoWithDebugOn(): void
    {
        $log = sys_get_temp_dir() . '/sp_audit_notice_' . bin2hex(random_bytes(4)) . '.txt';
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $log);
        Config::$items['debug'] = true;

        try {
            Audit::update('people', ['name' => 'Anna'], ['id' => 999]);
            Audit::delete('people', ['id' => 999]);
        } finally {
            ini_set('error_log', $previous);
            unset(Config::$items['debug']);
        }

        $logged = (string) file_get_contents($log);
        unlink($log);

        $this->assertStringContainsString('update on "people" matched no rows', $logged);
        $this->assertStringContainsString('delete on "people" matched no rows', $logged);
        $this->assertCount(0, $this->trail());
    }

    public function testTheSameNoticeStaysQuietWithDebugOff(): void
    {
        $log = sys_get_temp_dir() . '/sp_audit_notice_' . bin2hex(random_bytes(4)) . '.txt';
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $log);

        try {
            Audit::update('people', ['name' => 'Anna'], ['id' => 999]);
        } finally {
            ini_set('error_log', $previous);
        }

        // A no-op update is ordinary on a busy application; only debug wants to hear it
        $this->assertFileDoesNotExist($log);
    }

    public function testAMassUpdateIsRefusedRatherThanAudited(): void
    {
        $this->seed('Anna');
        $this->seed('Girts');
        $this->seed('Ilze');

        Config::$items['audit'] = ['max_rows' => 2] + $this->settings();
        Audit::reset();

        $this->expectException(AuditError::class);
        $this->expectExceptionMessage('Refusing to audit 3 rows');

        Audit::update('people', ['city' => 'Riga'], ['city' => 'Dobele']);
    }

    /**
     * The guard runs before the write, so a refused call has changed nothing.
     */
    public function testARefusedMassUpdateDoesNotWriteEither(): void
    {
        $this->seed('Anna');
        $this->seed('Girts');

        Config::$items['audit'] = ['max_rows' => 1] + $this->settings();
        Audit::reset();

        try {
            Audit::update('people', ['city' => 'Riga'], ['city' => 'Dobele']);
        } catch (AuditError) {
            // expected
        }

        $rows = Db::fetchAll('SELECT city FROM people', [], $this->connection);
        $this->assertIsArray($rows[0]);
        $this->assertEquals('Dobele', $rows[0]['city']);
    }

    public function testWithoutStrictAFailedAuditLetsTheChangeThroughButSaysSo(): void
    {
        Config::$items['audit'] = ['strict' => false, 'table' => 'no_such_audit_table'] + $this->settings();
        Audit::reset();

        $log = sys_get_temp_dir() . '/sp_audit_log_' . bin2hex(random_bytes(4)) . '.txt';
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $log);

        try {
            Audit::insert('people', ['name' => 'Anna']);
        } finally {
            ini_set('error_log', $previous);
        }

        $this->assertCount(1, Db::fetchAll('SELECT * FROM people', [], $this->connection));

        // Availability over completeness is a choice, not a silence
        $this->assertStringContainsString('Audit trail:', (string) file_get_contents($log));
        unlink($log);
    }

    public function testStrictSurfacesAFailedAuditWrite(): void
    {
        Config::$items['audit'] = ['table' => 'no_such_audit_table'] + $this->settings();
        Audit::reset();

        $this->expectException(AuditError::class);

        Audit::insert('people', ['name' => 'Anna']);
    }

    /*
    | Table resolution
    */

    public function testACallableTableSplitsTheTrailByModule(): void
    {
        Db::init($this->connection)->exec(
            (string) preg_replace(
                '/audit_log/',
                'audit_catalog',
                (string) file_get_contents(self::SCHEMA)
            )
        );

        Config::$items['audit'] = [
            'table' => fn(AuditEvent $event): string => 'audit_' . $event->module,
        ] + $this->settings();
        Audit::reset();

        Audit::insert('people', ['name' => 'Anna'], module: 'catalog');

        $this->assertCount(0, $this->trail());
        $this->assertCount(1, Db::fetchAll('SELECT * FROM audit_catalog', [], $this->connection));
    }

    public function testATableNameThatIsNotAnIdentifierIsRefused(): void
    {
        $store = new Store($this->connection, fn(): string => 'audit_log; DROP TABLE people');

        $this->expectException(AuditError::class);
        $this->expectExceptionMessage('not a plain table name');

        $store->tableFor(new AuditEvent(event: 'x', entityType: 'people'));
    }
}
