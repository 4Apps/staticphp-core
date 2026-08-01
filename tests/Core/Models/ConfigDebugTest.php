<?php

namespace StaticPHP\Tests\Core\Models;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Config;

/**
 * Who may see the query log and the timing panel is the application's decision, so the
 * gate has to fail closed on everything it does not clearly understand.
 */
class ConfigDebugTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(Config::$items['debug'], Config::$items['debug_check']);
    }

    public function testDebugIsOffWithNoConfigAtAll(): void
    {
        $this->assertFalse(Config::resolveDebug());
    }

    public function testExplicitDebugWins(): void
    {
        Config::set('debug', true);
        Config::set('debug_check', fn(): bool => false);

        $this->assertTrue(Config::resolveDebug());
    }

    public function testCallbackOpensTheGate(): void
    {
        Config::set('debug', false);
        Config::set('debug_check', fn(): bool => true);

        $this->assertTrue(Config::resolveDebug());
    }

    public function testCallbackClosesTheGate(): void
    {
        Config::set('debug', false);
        Config::set('debug_check', fn(): bool => false);

        $this->assertFalse(Config::resolveDebug());
    }

    public function testCallbackCanReadCookies(): void
    {
        Config::set('debug', false);
        Config::set('debug_check', fn(): bool => ($_COOKIE['sp_debug'] ?? '') === 'secret');

        $_COOKIE['sp_debug'] = 'secret';
        $this->assertTrue(Config::resolveDebug());

        $_COOKIE['sp_debug'] = 'wrong';
        $this->assertFalse(Config::resolveDebug());

        unset($_COOKIE['sp_debug']);
    }

    /**
     * A check returning something truthy but not a bool - a row count, a username - is a
     * check that was not thought through. Opening the panel on it would be worse than
     * ignoring it.
     */
    public function testTruthyNonBooleanDoesNotOpenTheGate(): void
    {
        Config::set('debug', false);
        Config::set('debug_check', fn(): mixed => 'yes');

        $this->assertFalse(Config::resolveDebug());
    }

    public function testNonCallableCheckIsIgnored(): void
    {
        Config::set('debug', false);
        Config::set('debug_check', ['127.0.0.1']);

        $this->assertFalse(Config::resolveDebug());
    }

    /**
     * A throwing check must not take the request down, and must not be read as consent.
     */
    public function testThrowingCheckFailsClosedRatherThanPropagating(): void
    {
        Config::set('debug', false);
        Config::set('debug_check', function (): bool {
            throw new \RuntimeException('the session the check wanted does not exist yet');
        });

        $this->assertFalse(Config::resolveDebug());
    }

    public function testTheOldIpListIsNoLongerConsulted(): void
    {
        Config::set('debug', false);
        Config::set('debug_ips', ['127.0.0.1']);
        Config::set('client_ip', '127.0.0.1');

        $this->assertFalse(Config::resolveDebug());

        unset(Config::$items['debug_ips'], Config::$items['client_ip']);
    }
}
