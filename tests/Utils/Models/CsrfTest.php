<?php

namespace StaticPHP\Tests\Utils\Models;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use StaticPHP\Utils\Models\Csrf;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        // The token lives in the session, and phpunit has already written output by the
        // time these run, so start one explicitly rather than through session_start()
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }

        Csrf::reset();
    }

    /**
     * Csrf::token() requires an active session; fake one so the class under test sees
     * PHP_SESSION_ACTIVE without phpunit's own output getting in the way.
     */
    private function withSession(callable $body): mixed
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            @session_start();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('no session available in this environment');
        }

        return $body();
    }

    public function testTokenIsLongAndHex(): void
    {
        $this->withSession(function () {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Csrf::token());
        });
    }

    public function testTokenIsStableWithinASession(): void
    {
        $this->withSession(function () {
            $this->assertEquals(Csrf::token(), Csrf::token());
        });
    }

    public function testCorrectTokenValidates(): void
    {
        $this->withSession(function () {
            $this->assertTrue(Csrf::validate(Csrf::token()));
        });
    }

    public function testWrongTokenOfEqualLengthIsRejected(): void
    {
        $this->withSession(function () {
            Csrf::token();
            $this->assertFalse(Csrf::validate(str_repeat('0', 64)));
        });
    }

    public function testTokenPrefixIsRejected(): void
    {
        $this->withSession(function () {
            $token = Csrf::token();
            $this->assertFalse(Csrf::validate(substr($token, 0, 32)));
        });
    }

    public function testEmptyAndNullTokensAreRejected(): void
    {
        $this->withSession(function () {
            Csrf::token();
            $this->assertFalse(Csrf::validate(''));
            $this->assertFalse(Csrf::validate(null));
        });
    }

    public function testRequestValidationReadsPost(): void
    {
        $this->withSession(function () {
            $_POST[Csrf::FIELD_NAME] = Csrf::token();

            $this->assertTrue(Csrf::validateRequest());

            unset($_POST[Csrf::FIELD_NAME]);
        });
    }

    public function testRequestValidationReadsTheHeader(): void
    {
        $this->withSession(function () {
            $_SERVER[Csrf::HEADER_NAME] = Csrf::token();

            $this->assertTrue(Csrf::validateRequest());

            unset($_SERVER[Csrf::HEADER_NAME]);
        });
    }

    public function testRequestWithoutATokenIsRejected(): void
    {
        $this->withSession(function () {
            Csrf::token();
            unset($_POST[Csrf::FIELD_NAME], $_SERVER[Csrf::HEADER_NAME]);

            $this->assertFalse(Csrf::validateRequest());
        });
    }

    public function testArrayTokenDoesNotBypassValidation(): void
    {
        $this->withSession(function () {
            Csrf::token();
            $_POST[Csrf::FIELD_NAME] = ['array', 'payload'];

            $this->assertFalse(Csrf::validateRequest());

            unset($_POST[Csrf::FIELD_NAME]);
        });
    }

    public function testFieldRendersAHiddenInputCarryingTheToken(): void
    {
        $this->withSession(function () {
            $token = Csrf::token();
            $field = Csrf::field();

            $this->assertStringContainsString('type="hidden"', $field);
            $this->assertStringContainsString('name="' . Csrf::FIELD_NAME . '"', $field);
            $this->assertStringContainsString($token, $field);
        });
    }

    public function testResetIssuesANewToken(): void
    {
        $this->withSession(function () {
            $token = Csrf::token();
            Csrf::reset();

            $this->assertNotEquals($token, Csrf::token());
        });
    }
}
