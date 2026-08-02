<?php

namespace StaticPHP\Tests\Utils\Models;

use PDO;
use PHPUnit\Framework\TestCase;
use StaticPHP\Utils\Models\Db;

/**
 * Db::transaction() against a real driver.
 *
 * SQLite in a temp file, because the whole point of the method is what the database does
 * when the closure throws, and a mock cannot answer that.
 */
class DbTransactionTest extends TestCase
{
    private string $dbFile;
    private string $connection;

    protected function setUp(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $suffix = bin2hex(random_bytes(6));
        $this->dbFile = sys_get_temp_dir() . "/sp_tx_{$suffix}.sqlite";
        $this->connection = "tx_test_{$suffix}";

        $pdo = Db::init($this->connection, [
            'string' => "sqlite:{$this->dbFile}",
            'username' => '',
            'password' => '',
            'wrap_column' => '"',
        ]);

        $pdo->exec('CREATE TABLE notes (id integer PRIMARY KEY AUTOINCREMENT, body text NOT NULL)');
    }

    protected function tearDown(): void
    {
        Db::close($this->connection);

        if (is_file($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    private function add(string $body): void
    {
        Db::query('INSERT INTO notes (body) VALUES (?)', [$body], $this->connection);
    }

    /**
     * @return list<string>
     */
    private function bodies(): array
    {
        $rows = Db::fetchAll('SELECT body FROM notes ORDER BY id', [], $this->connection);

        $out = [];
        foreach ($rows as $row) {
            $body = (is_array($row) ? ($row['body'] ?? null) : null);
            if (is_string($body)) {
                $out[] = $body;
            }
        }

        return $out;
    }

    private function depth(): int
    {
        $property = new \ReflectionProperty(Db::class, 'savepoints');
        $value = $property->getValue();

        $depths = (is_array($value) ? $value : []);
        $depth = $depths[$this->connection] ?? 0;

        return (is_int($depth) ? $depth : 0);
    }

    public function testWorkIsCommittedAndItsValueReturned(): void
    {
        $result = Db::transaction(function (): string {
            $this->add('kept');

            return 'returned';
        }, $this->connection);

        $this->assertSame('returned', $result);
        $this->assertSame(['kept'], $this->bodies());
    }

    public function testTheClosureIsHandedThePdoInstance(): void
    {
        $seen = Db::transaction(function (PDO $pdo): mixed {
            return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        }, $this->connection);

        $this->assertSame('sqlite', $seen);
    }

    public function testAnExceptionRollsTheWorkBackAndIsRethrown(): void
    {
        try {
            Db::transaction(function (): void {
                $this->add('doomed');

                throw new \RuntimeException('no');
            }, $this->connection);

            $this->fail('the exception should have been rethrown');
        } catch (\RuntimeException $exception) {
            $this->assertSame('no', $exception->getMessage());
        }

        $this->assertSame([], $this->bodies());
    }

    /**
     * Hand written transaction handling usually catches Exception, which leaves a TypeError
     * or a failed assertion to end the request with the transaction still open.
     */
    public function testAnErrorRollsTheWorkBackToo(): void
    {
        try {
            Db::transaction(function (): void {
                $this->add('doomed');

                throw new \TypeError('wrong type');
            }, $this->connection);

            $this->fail('the error should have been rethrown');
        } catch (\TypeError $error) {
            $this->assertSame('wrong type', $error->getMessage());
        }

        $this->assertSame([], $this->bodies());
        $this->assertFalse(Db::inTransaction($this->connection));
    }

    public function testNestedCallsBothCommitWithTheOuterOne(): void
    {
        Db::transaction(function (): void {
            $this->add('outer');

            Db::transaction(function (): void {
                $this->add('inner');
            }, $this->connection);
        }, $this->connection);

        $this->assertSame(['outer', 'inner'], $this->bodies());
    }

    /**
     * The reason nesting takes a savepoint. A plain commit in the inner call would end the
     * outer transaction early, and a plain rollback would discard the outer work with it.
     */
    public function testAFailedInnerCallDiscardsOnlyItsOwnWork(): void
    {
        Db::transaction(function (): void {
            $this->add('outer');

            try {
                Db::transaction(function (): void {
                    $this->add('inner');

                    throw new \RuntimeException('inner failed');
                }, $this->connection);
            } catch (\RuntimeException) {
                // handled by the caller, which carries on
            }

            $this->add('after');
        }, $this->connection);

        $this->assertSame(['outer', 'after'], $this->bodies());
    }

    public function testTheOuterCallStillRollsEverythingBack(): void
    {
        try {
            Db::transaction(function (): void {
                $this->add('outer');

                Db::transaction(function (): void {
                    $this->add('inner');
                }, $this->connection);

                throw new \RuntimeException('outer failed');
            }, $this->connection);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame([], $this->bodies());
        $this->assertFalse(Db::inTransaction($this->connection));
    }

    public function testNestingDepthUnwindsSoLaterCallsStartClean(): void
    {
        $this->assertSame(0, $this->depth());

        Db::transaction(function (): void {
            Db::transaction(function (): void {
                $this->assertSame(1, $this->depth());

                Db::transaction(function (): void {
                    $this->assertSame(2, $this->depth());
                }, $this->connection);

                $this->assertSame(1, $this->depth());
            }, $this->connection);
        }, $this->connection);

        $this->assertSame(0, $this->depth());

        Db::transaction(function (): void {
            $this->add('later');
        }, $this->connection);

        $this->assertSame(['later'], $this->bodies());
    }

    /**
     * Committing inside the closure is not how this is meant to be used, but it happens in
     * code being migrated onto it, and it should not turn into "there is no active
     * transaction" thrown from the framework.
     */
    public function testAClosureThatCommitsItselfDoesNotFailOnTheWayOut(): void
    {
        $result = Db::transaction(function (): string {
            $this->add('kept');
            Db::commit($this->connection);

            return 'done';
        }, $this->connection);

        $this->assertSame('done', $result);
        $this->assertSame(['kept'], $this->bodies());
    }

    public function testAClosureThatRollsItselfBackDoesNotFailOnTheWayOut(): void
    {
        $result = Db::transaction(function (): string {
            $this->add('dropped');
            Db::rollBack($this->connection);

            return 'done';
        }, $this->connection);

        $this->assertSame('done', $result);
        $this->assertSame([], $this->bodies());
    }
}
