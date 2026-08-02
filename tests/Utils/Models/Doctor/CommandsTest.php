<?php

namespace StaticPHP\Tests\Utils\Models\Doctor;

use PDO;
use PHPUnit\Framework\TestCase;
use StaticPHP\Utils\Models\Db;
use StaticPHP\Utils\Models\Doctor\Commands;
use StaticPHP\Utils\Models\Doctor\Result;
use StaticPHP\Utils\Models\Doctor\Status;

class CommandsTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> */
    private array $connections = [];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->lines = [];
        $this->connections = [];
        $this->files = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $name) {
            Db::close($name);
        }

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function commands(array $config, bool $offline = false): Commands
    {
        return new Commands($config, function (string $line = ''): void {
            $this->lines[] = $line;
        }, $offline);
    }

    /**
     * @param list<Result> $results
     * @return list<Result>
     */
    private function named(array $results, string $check): array
    {
        return array_values(array_filter($results, fn(Result $result) => $result->check === $check));
    }

    private function only(Commands $commands, string $check): Result
    {
        $found = $this->named($commands->checks(), $check);
        $this->assertCount(1, $found, "expected exactly one \"{$check}\" result");

        return $found[0];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function sqlite(): array
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $suffix = bin2hex(random_bytes(6));
        $file = sys_get_temp_dir() . "/sp_doctor_{$suffix}.sqlite";
        $name = "doctor_{$suffix}";

        $this->files[] = $file;
        $this->connections[] = $name;

        return [$name, ['string' => "sqlite:{$file}", 'username' => '', 'password' => '', 'wrap_column' => '"']];
    }

    public function testPhpAndExtensionsPassOnASupportedBuild(): void
    {
        $commands = $this->commands([]);

        $this->assertSame(Status::OK, $this->only($commands, 'php')->status);
        $this->assertSame(Status::OK, $this->only($commands, 'extensions')->status);
    }

    public function testNoConfiguredDatabaseIsAWarningRatherThanAFailure(): void
    {
        $result = $this->only($this->commands([]), 'database');

        $this->assertSame(Status::WARN, $result->status);
        $this->assertStringContainsString('no connections', $result->detail);
    }

    /**
     * A build without the pdo driver and a database that refuses the connection are
     * different problems for different people, and doctor should not conflate them.
     */
    public function testAMissingPdoDriverNamesTheExtensionToInstall(): void
    {
        $commands = $this->commands([
            'db' => ['pdo' => ['default' => ['string' => 'nonesuch:host=db;dbname=app']]],
        ]);

        $result = $this->only($commands, 'db:default');

        $this->assertSame(Status::FAIL, $result->status);
        $this->assertStringContainsString('nonesuch pdo driver', $result->detail);
        $this->assertStringContainsString('ext-pdo_nonesuch', $result->fix);
    }

    public function testAConnectionWithoutADsnFails(): void
    {
        $commands = $this->commands(['db' => ['pdo' => ['default' => ['username' => 'app']]]]);

        $this->assertSame(Status::FAIL, $this->only($commands, 'db:default')->status);
    }

    public function testAReachableConnectionReportsTheServerVersion(): void
    {
        [$name, $config] = $this->sqlite();

        $result = $this->only($this->commands(['db' => ['pdo' => [$name => $config]]]), "db:{$name}");

        $this->assertSame(Status::OK, $result->status);
        $this->assertStringContainsString('sqlite', $result->detail);
    }

    public function testAnUnreachableConnectionFails(): void
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $commands = $this->commands([
            'db' => ['pdo' => ['broken' => ['string' => 'sqlite:/nonesuch/nope/db.sqlite']]],
        ]);

        $result = $this->only($commands, 'db:broken');

        $this->assertSame(Status::FAIL, $result->status);
        $this->assertStringContainsString('cannot connect', $result->detail);
    }

    public function testOfflineChecksTheDriverButDoesNotConnect(): void
    {
        $commands = $this->commands([
            'db' => ['pdo' => ['broken' => ['string' => 'sqlite:/nonesuch/nope/db.sqlite']]],
        ], true);

        $result = $this->only($commands, 'db:broken');

        $this->assertSame(Status::OK, $result->status);
        $this->assertStringContainsString('--offline', $result->detail);
    }

    public function testAMissingMigrationsDirectoryFails(): void
    {
        $commands = $this->commands(['migrations' => ['dir' => '/nonesuch/migrations']]);

        $result = $this->only($commands, 'migrations');

        $this->assertSame(Status::FAIL, $result->status);
        $this->assertStringContainsString('no directory at', $result->detail);
    }

    public function testPendingMigrationsAreAWarning(): void
    {
        [$name, $config] = $this->sqlite();

        $dir = sys_get_temp_dir() . '/sp_doctor_mig_' . bin2hex(random_bytes(6));
        mkdir($dir);
        $file = $dir . '/2026-01-01-000000-create-thing.sql';
        file_put_contents($file, "CREATE TABLE thing (id integer PRIMARY KEY);\n");

        try {
            $pdo = Db::init($name, $config);
            (new \StaticPHP\Utils\Models\Migrations\Tracker(
                $pdo,
                \StaticPHP\Utils\Models\Migrations\Drivers\Driver::forPdo(
                    $pdo,
                    (is_string($config['string']) ? $config['string'] : null)
                ),
                'migrations'
            ))->ensureTable();

            $commands = $this->commands([
                'db' => ['pdo' => [$name => $config]],
                'migrations' => ['dir' => $dir, 'table' => 'migrations', 'connection' => $name],
            ]);

            $result = $this->only($commands, 'migrations');

            $this->assertSame(Status::WARN, $result->status);
            $this->assertStringContainsString('1 pending', $result->detail);
            $this->assertStringContainsString('migrate apply', $result->fix);
        } finally {
            unlink($file);
            rmdir($dir);
        }
    }

    public function testDebugLeftOnForEverybodyIsAWarning(): void
    {
        $result = $this->only($this->commands(['debug' => true]), 'debug');

        $this->assertSame(Status::WARN, $result->status);
    }

    public function testDebugDecidedPerRequestIsFine(): void
    {
        $result = $this->only($this->commands(['debug' => fn() => false]), 'debug');

        $this->assertSame(Status::OK, $result->status);
    }

    public function testAWorldAccessibleCacheDirectoryIsAWarning(): void
    {
        $path = sys_get_temp_dir() . '/sp_doctor_cache_' . bin2hex(random_bytes(6));
        mkdir($path, 0777);
        chmod($path, 0777);

        try {
            $result = $this->only($this->commands(['cache' => ['files' => ['path' => $path]]]), 'cache');

            $this->assertSame(Status::WARN, $result->status);
            $this->assertStringContainsString('world accessible', $result->detail);
        } finally {
            rmdir($path);
        }
    }

    public function testAnAuditTableThatIsNotThereFails(): void
    {
        [$name, $config] = $this->sqlite();
        Db::init($name, $config);

        $commands = $this->commands([
            'db' => ['pdo' => [$name => $config]],
            'audit' => ['table' => 'audit_log', 'connection' => $name],
        ]);

        $result = $this->only($commands, 'audit');

        $this->assertSame(Status::FAIL, $result->status);
        $this->assertStringContainsString('audit install', $result->fix);
    }

    public function testAnAuditTableThatExistsPasses(): void
    {
        [$name, $config] = $this->sqlite();
        $pdo = Db::init($name, $config);
        $pdo->exec((string) file_get_contents(SP_PATH . '/Utils/Files/Audit/install.sqlite.sql'));

        $commands = $this->commands([
            'db' => ['pdo' => [$name => $config]],
            'audit' => ['table' => 'audit_log', 'connection' => $name],
        ]);

        $this->assertSame(Status::OK, $this->only($commands, 'audit')->status);
    }

    /**
     * A resolver names a different table per event, so there is nothing to look up.
     */
    public function testAnAuditTableResolverIsNotChecked(): void
    {
        $commands = $this->commands(['audit' => ['table' => fn() => 'audit_log']]);

        $this->assertSame([], $this->named($commands->checks(), 'audit'));
    }

    public function testRunReportsCleanWithZero(): void
    {
        $code = $this->commands([])->run();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('0 failures', implode("\n", $this->lines));
    }

    public function testRunReportsAFailureWithOne(): void
    {
        $code = $this->commands([
            'db' => ['pdo' => ['default' => ['string' => 'nonesuch:host=db']]],
        ])->run();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('1 failure', implode("\n", $this->lines));
    }

    public function testStrictTurnsWarningsIntoAFailingExitCode(): void
    {
        $this->assertSame(0, $this->commands([])->run());

        $this->lines = [];
        $this->assertSame(1, $this->commands([])->run(true));
    }

    public function testTheFixLineIsPrintedUnderTheCheck(): void
    {
        $this->commands(['db' => ['pdo' => ['default' => ['string' => 'nonesuch:host=db']]]])->run();

        $this->assertStringContainsString('-> install ext-pdo_nonesuch', implode("\n", $this->lines));
    }
}
