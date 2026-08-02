<?php

namespace StaticPHP\Tests\Utils\Models\Sessions;

use PHPUnit\Framework\TestCase;
use StaticPHP\Utils\Models\Sessions\Commands;

class CommandsTest extends TestCase
{
    private const FILES = SP_PATH . '/Utils/Files/Sessions';

    private string $dir;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sp_sessions_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            if (is_string($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function commands(string $driver = 'pgsql'): Commands
    {
        return new Commands($driver, function (string $line = ''): void {
            $this->lines[] = $line;
        });
    }

    private function outputText(): string
    {
        return implode("\n", $this->lines);
    }

    public function testInstallWritesTheTemplateIntoTheMigrationsDirectory(): void
    {
        // 2026-08-03 00:00:00 UTC
        $code = $this->commands()->install($this->dir, self::FILES, 1785715200);

        $this->assertSame(0, $code);

        $written = $this->dir . '/2026-08-03-000000-create-sessions.sql';
        $this->assertFileExists($written);
        $this->assertStringContainsString('CREATE TABLE sessions', (string) file_get_contents($written));
        $this->assertStringContainsString('staticphp migrate apply', $this->outputText());
    }

    /**
     * Discovery globs for a fixed filename pattern, so a generated name that does not match
     * it makes `migrate` refuse to read the whole directory.
     */
    public function testTheGeneratedNameMatchesWhatMigrateGlobsFor(): void
    {
        $this->commands()->install($this->dir, self::FILES, 1785715200);

        $files = (array) glob($this->dir . '/*.sql');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}-\d{6}-[a-z0-9-]+\.sql$/',
            basename((string) $files[0])
        );
    }

    public function testADriverWithoutATemplateSaysWhatToDoInstead(): void
    {
        $code = $this->commands('mysql')->install($this->dir, self::FILES, 1785715200);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('no session table template for mysql', $this->outputText());
        $this->assertStringContainsString('cache backed handlers', $this->outputText());
        $this->assertSame([], glob($this->dir . '/*.sql'));
    }

    public function testAMissingMigrationsDirectoryIsReported(): void
    {
        $code = $this->commands()->install($this->dir . '/nope', self::FILES, 1785715200);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('no migrations directory', $this->outputText());
    }

    public function testAnExistingFileIsNotOverwritten(): void
    {
        $this->commands()->install($this->dir, self::FILES, 1785715200);
        $written = $this->dir . '/2026-08-03-000000-create-sessions.sql';
        file_put_contents($written, 'edited by hand');

        $code = $this->commands()->install($this->dir, self::FILES, 1785715200);

        $this->assertSame(1, $code);
        $this->assertSame('edited by hand', file_get_contents($written));
    }
}
