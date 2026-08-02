<?php

namespace StaticPHP\Tests\Utils\Models\Crypto;

use PDO;
use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Crypto\Commands;
use StaticPHP\Utils\Models\Crypto\Crypto;

class CommandsTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    /** @var array<string, string> */
    private array $vault = [];

    protected function setUp(): void
    {
        if (extension_loaded('sodium') === false) {
            $this->markTestSkipped('ext-sodium is not available');
        }

        $this->lines = [];
        $this->vault = [
            'K1' => base64_encode(str_repeat("\x11", 32)),
            'K0' => base64_encode(str_repeat("\x00", 32)),
        ];

        $this->configure('k1');
    }

    protected function tearDown(): void
    {
        Crypto::reset();
        unset(Config::$items['crypto']);
    }

    private function configure(string $current): void
    {
        Crypto::reset();

        Config::$items['crypto'] = [
            'key' => $current,
            'keys' => ['k1' => 'K1', 'k0' => 'K0'],
            'resolver' => fn(string $name): ?string => $this->vault[$name] ?? null,
        ];
    }

    private function commands(?PDO $pdo = null): Commands
    {
        return new Commands($pdo, function (string $line = ''): void {
            $this->lines[] = $line;
        });
    }

    private function outputText(): string
    {
        return implode("\n", $this->lines);
    }

    private function database(): PDO
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE people (id integer PRIMARY KEY AUTOINCREMENT, code text)');

        return $pdo;
    }

    /**
     * @return list<string>
     */
    private function codes(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT code FROM people ORDER BY id');
        $this->assertNotFalse($rows);

        $out = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (is_array($row) ? ($row['code'] ?? null) : null);
            $out[] = (is_string($code) ? $code : '');
        }

        return $out;
    }

    public function testKeyPrintsUsableMaterialAndSaysWhereToPutIt(): void
    {
        $code = $this->commands()->key();

        $this->assertSame(0, $code);

        $raw = base64_decode($this->lines[0], true);
        $this->assertIsString($raw);
        $this->assertSame(32, strlen($raw));
        $this->assertStringContainsString('not in a config file', $this->outputText());
    }

    public function testRotateRewritesValuesFromTheOldKeyToTheCurrentOne(): void
    {
        $pdo = $this->database();

        $this->configure('k0');
        $insert = $pdo->prepare('INSERT INTO people (code) VALUES (?)');
        $insert->execute([Crypto::encrypt('11111111111')]);
        $insert->execute([Crypto::encrypt('22222222222')]);

        $this->configure('k1');

        $code = $this->commands($pdo)->rotate('people', 'code', 'id', 500, false);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('2 re-encrypted', $this->outputText());

        foreach ($this->codes($pdo) as $stored) {
            $this->assertSame('k1', Crypto::keyIdOf($stored));
        }

        $this->assertSame('11111111111', Crypto::decrypt($this->codes($pdo)[0]));
        $this->assertSame('22222222222', Crypto::decrypt($this->codes($pdo)[1]));
    }

    public function testADryRunReportsTheWorkWithoutDoingIt(): void
    {
        $pdo = $this->database();

        $this->configure('k0');
        $pdo->prepare('INSERT INTO people (code) VALUES (?)')->execute([Crypto::encrypt('11111111111')]);
        $before = $this->codes($pdo);

        $this->configure('k1');

        $code = $this->commands($pdo)->rotate('people', 'code', 'id', 500, true);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 would be re-encrypted', $this->outputText());
        $this->assertSame($before, $this->codes($pdo));
    }

    public function testRowsAlreadyOnTheCurrentKeyAreLeftAlone(): void
    {
        $pdo = $this->database();
        $pdo->prepare('INSERT INTO people (code) VALUES (?)')->execute([Crypto::encrypt('11111111111')]);
        $before = $this->codes($pdo);

        $code = $this->commands($pdo)->rotate('people', 'code', 'id', 500, false);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('0 re-encrypted', $this->outputText());
        $this->assertSame($before, $this->codes($pdo), 'an unchanged row should not be rewritten');
    }

    /**
     * rotate moves values between keys. Encrypting a column for the first time is a
     * different job, with a blind index to populate as well, and doing it silently here
     * would be a surprise on a table that was never meant to be encrypted.
     */
    public function testPlaintextIsCountedAndLeftAlone(): void
    {
        $pdo = $this->database();
        $pdo->exec("INSERT INTO people (code) VALUES ('11111111111')");
        $pdo->exec('INSERT INTO people (code) VALUES (NULL)');

        $code = $this->commands($pdo)->rotate('people', 'code', 'id', 500, false);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('1 were not encrypted', $this->outputText());
        $this->assertSame(['11111111111', ''], $this->codes($pdo));
    }

    public function testPagingCoversEveryRow(): void
    {
        $pdo = $this->database();

        $this->configure('k0');
        $insert = $pdo->prepare('INSERT INTO people (code) VALUES (?)');
        for ($i = 0; $i < 25; $i++) {
            $insert->execute([Crypto::encrypt("value {$i}")]);
        }

        $this->configure('k1');

        // A batch far smaller than the table, so the cursor has to work
        $code = $this->commands($pdo)->rotate('people', 'code', 'id', 4, false);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Read 25 rows', $this->outputText());
        $this->assertStringContainsString('25 re-encrypted', $this->outputText());

        $codes = $this->codes($pdo);
        $this->assertCount(25, $codes);
        $this->assertSame('value 24', Crypto::decrypt($codes[24]));
    }

    public function testARowThatCannotBeDecryptedStopsTheRotation(): void
    {
        $pdo = $this->database();

        // Long enough to get past the length check, so this exercises the missing key
        $orphan = 'sp1:k9:' . base64_encode(str_repeat('a', 60));
        $pdo->prepare('INSERT INTO people (code) VALUES (?)')->execute([$orphan]);

        $code = $this->commands($pdo)->rotate('people', 'code', 'id', 500, false);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No key "k9"', $this->outputText());
    }

    public function testTableAndColumnNamesAreNotInterpolatedBlindly(): void
    {
        $pdo = $this->database();

        $this->assertSame(2, $this->commands($pdo)->rotate('people; DROP TABLE people', 'code', 'id', 500, false));
        $this->assertSame(2, $this->commands($pdo)->rotate('people', 'code = 1', 'id', 500, false));
        $this->assertSame(2, $this->commands($pdo)->rotate('people', 'code', 'id)', 500, false));
        $this->assertStringContainsString('not a plain table name', $this->outputText());
        $this->assertStringContainsString('not a plain column name', $this->outputText());

        // Still there
        $this->assertSame([], $this->codes($pdo));
    }

    public function testABatchOfNothingIsRejected(): void
    {
        $this->assertSame(2, $this->commands($this->database())->rotate('people', 'code', 'id', 0, false));
        $this->assertStringContainsString('--batch must be at least 1', $this->outputText());
    }

    public function testRotateWithoutAConnectionSaysSo(): void
    {
        $this->assertSame(2, $this->commands()->rotate('people', 'code', 'id', 500, false));
        $this->assertStringContainsString('needs a database connection', $this->outputText());
    }

    public function testRotateWithoutACurrentKeySaysSo(): void
    {
        $pdo = $this->database();
        Config::$items['crypto'] = ['keys' => []];
        Crypto::reset();

        $this->assertSame(2, $this->commands($pdo)->rotate('people', 'code', 'id', 500, false));
        $this->assertStringContainsString("config['crypto']['key']", $this->outputText());
    }
}
