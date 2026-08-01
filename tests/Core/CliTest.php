<?php

namespace StaticPHP\Tests\Core;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Cli;

/**
 * The skeleton dispatches straight into these classes, so a name that resolves to nothing -
 * or to a class without the entry point - is a fatal on the command line rather than a
 * caught error.
 */
class CliTest extends TestCase
{
    public function testEveryCommandResolvesToARunnableClass(): void
    {
        $commands = Cli::commands();

        $this->assertNotEmpty($commands);

        foreach ($commands as $name => $class) {
            $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*$/', $name);
            $this->assertTrue(class_exists($class), "{$class} does not exist");
            $this->assertTrue(method_exists($class, 'run'), "{$class} has no run()");
        }
    }

    /**
     * `exit($class::run(array_slice($argv, 2), __DIR__));` is the whole dispatch, so the
     * signature is a contract with the skeleton rather than a convention.
     */
    public function testEveryCommandTakesArgumentsAndABasePathAndReturnsAnExitCode(): void
    {
        foreach (Cli::commands() as $class) {
            $method = new \ReflectionMethod($class, 'run');

            $this->assertTrue($method->isStatic(), "{$class}::run() must be static");
            $this->assertTrue($method->isPublic(), "{$class}::run() must be public");
            $this->assertEquals(2, $method->getNumberOfParameters());
            $this->assertEquals('int', (string) $method->getReturnType());
        }
    }

    public function testTheDocumentedCommandsAreThere(): void
    {
        $this->assertEquals(['migrate', 'i18n', 'audit'], array_keys(Cli::commands()));
    }
}
