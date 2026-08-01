<?php

namespace StaticPHP\Tests\Core\Exceptions;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Exceptions\ErrorPage;
use StaticPHP\Core\Models\Config;

/**
 * The error pages are the one place where the framework prints an exception message, a
 * file path, a stack trace and the whole request, so what separates the two of them is a
 * security boundary rather than a matter of styling.
 */
class ErrorPageTest extends TestCase
{
    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    /** @var array<string, mixed> */
    private array $config = [];

    protected function setUp(): void
    {
        $this->config = Config::$items;

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Config::$items = $this->config;

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SESSION = [];
    }


    // ###################
    // ### Status page ###
    // ###################

    public function testStatusPageIsAWholeDocument(): void
    {
        $page = ErrorPage::status(404);

        $this->assertStringStartsWith('<!DOCTYPE html>', $page);
        $this->assertStringContainsString('</html>', $page);
        $this->assertStringContainsString('<title>404 Not Found</title>', $page);
    }

    public function testStatusPageIsSelfContained(): void
    {
        $page = ErrorPage::status(500);

        // No stylesheet, script, font or image may be fetched: the page has to render on
        // a site whose asset pipeline is exactly what broke
        $this->assertStringNotContainsString('<link', $page);
        $this->assertStringNotContainsString('<script', $page);
        $this->assertStringNotContainsString('src=', $page);
        $this->assertStringNotContainsString('@import', $page);
        $this->assertStringNotContainsString('url(', $page);
    }

    public function testStatusPageExplainsTheCodeWithoutBeingTold(): void
    {
        $this->assertStringContainsString('does not exist', ErrorPage::status(404));
        $this->assertStringContainsString('permission', ErrorPage::status(403));
        $this->assertStringContainsString('on our side', ErrorPage::status(500));
    }

    public function testStatusPageEscapesEverythingItIsGiven(): void
    {
        $page = ErrorPage::status(400, self::PAYLOAD, self::PAYLOAD, self::PAYLOAD);

        $this->assertStringNotContainsString('<img', $page);
        $this->assertStringContainsString('&lt;img', $page);
    }

    public function testStatusPageShowsTheReferenceOnlyWhenGivenOne(): void
    {
        $this->assertStringContainsString('Reference', ErrorPage::status(500, null, null, 'abc123'));
        $this->assertStringNotContainsString('Reference', ErrorPage::status(500));
    }

    public function testStatusPageNeverCarriesTheException(): void
    {
        $exception = new \RuntimeException('the database password is hunter2');

        $page = ErrorPage::status(500, null, null, 'abc123');

        $this->assertStringNotContainsString('hunter2', $page);
        $this->assertStringNotContainsString($exception->getFile(), $page);
        $this->assertStringNotContainsString('RuntimeException', $page);
    }


    // ##################
    // ### Debug page ###
    // ##################

    public function testDebugPageCarriesTheException(): void
    {
        $page = ErrorPage::debug(new \RuntimeException('totals do not add up'));

        $this->assertStringStartsWith('<!DOCTYPE html>', $page);
        $this->assertStringContainsString('totals do not add up', $page);
        $this->assertStringContainsString('RuntimeException', $page);
        $this->assertStringContainsString(basename(__FILE__), $page);
    }

    public function testDebugPageShowsTheSourceAroundTheThrow(): void
    {
        $page = ErrorPage::debug(new \RuntimeException('boom'));

        // The line above is what threw, so the page has to be quoting this file back
        $this->assertStringContainsString('testDebugPageShowsTheSourceAroundTheThrow', $page);
        $this->assertStringContainsString('is-error', $page);
    }

    public function testDebugPageFollowsThePreviousChain(): void
    {
        $root = new \LogicException('the root cause');
        $wrapper = new \RuntimeException('the outer failure', 0, $root);

        $page = ErrorPage::debug($wrapper);

        $this->assertStringContainsString('the outer failure', $page);
        $this->assertStringContainsString('the root cause', $page);
        $this->assertStringContainsString('Caused by', $page);
    }

    public function testDebugPageSurvivesACyclicPreviousChain(): void
    {
        // Pathological, but looping forever inside the error handler would take the
        // process with it
        $first = new \RuntimeException('first');
        $second = new \RuntimeException('second', 0, $first);

        $property = new \ReflectionProperty(\Exception::class, 'previous');
        $property->setValue($first, $second);

        $page = ErrorPage::debug($second);

        $this->assertStringContainsString('first', $page);
        $this->assertStringContainsString('second', $page);
    }

    public function testDebugPageEscapesTheExceptionMessage(): void
    {
        $page = ErrorPage::debug(new \RuntimeException(self::PAYLOAD));

        $this->assertStringNotContainsString('<img src=x', $page);
        $this->assertStringContainsString('&lt;img', $page);
    }

    public function testDebugPageEscapesRequestData(): void
    {
        $_GET = ['q' => self::PAYLOAD];
        $_POST = [self::PAYLOAD => 'value'];
        $_COOKIE = ['theme' => self::PAYLOAD];

        $page = ErrorPage::debug(new \RuntimeException('boom'));

        $this->assertStringNotContainsString('<img src=x', $page);
    }

    public function testDebugPageIsSelfContained(): void
    {
        $page = ErrorPage::debug(new \RuntimeException('boom'));

        // Every needle is assembled rather than written out. The page quotes the source
        // around the throw, which is this very method, so a literal here would be found
        // in the quotation and the assertion would be testing itself.
        $this->assertStringNotContainsString('<' . 'link', $page);
        $this->assertStringNotContainsString('<' . 'script src', $page);
        $this->assertStringNotContainsString('@' . 'import', $page);
        $this->assertStringNotContainsString('url' . '(', $page);
    }

    public function testDebugPageRedactsCredentials(): void
    {
        // Assembled for the same reason - see testDebugPageIsSelfContained()
        $secret = 'hunt' . 'er2';
        $token = 'sk-live-' . '4242';
        $nested = 'also' . ' gone';

        $_POST = ['user' => 'gints', 'password' => $secret];
        $_SESSION = ['api_token' => $token, 'nested' => ['secret' => $nested]];

        $page = ErrorPage::debug(new \RuntimeException('boom'));

        $this->assertStringNotContainsString($secret, $page);
        $this->assertStringNotContainsString($token, $page);
        $this->assertStringNotContainsString($nested, $page);
        $this->assertStringContainsString('gints', $page);
    }

    public function testDebugPageTruncatesAbsurdValues(): void
    {
        $_POST = ['blob' => str_repeat('x', ErrorPage::MAX_VALUE_LENGTH * 2)];

        $page = ErrorPage::debug(new \RuntimeException('boom'));

        // Printed, but not all of it: one huge post field must not bury the exception
        $this->assertStringContainsString(str_repeat('x', 512), $page);
        $this->assertStringNotContainsString(str_repeat('x', ErrorPage::MAX_VALUE_LENGTH + 1), $page);
    }


    // ##################
    // ### Plain text ###
    // ##################

    public function testReportIsPlainText(): void
    {
        $report = ErrorPage::report(new \RuntimeException('boom', 0, new \LogicException('why')));

        $this->assertStringNotContainsString('<', $report);
        $this->assertStringContainsString('RuntimeException', $report);
        $this->assertStringContainsString('Caused by: LogicException', $report);
        $this->assertStringContainsString('Reference: ' . ErrorPage::requestId(), $report);
    }


    // ##################
    // ### Redaction  ###
    // ##################

    public function testRedactionChecksTheKeyWhateverTheValueIs(): void
    {
        $redacted = ErrorPage::redact([
            'password' => ['first' => 'a', 'second' => 'b'],
            'api_key' => 42,
            'plain' => 'kept',
        ]);

        $this->assertIsArray($redacted);
        $this->assertSame('***', $redacted['password']);
        $this->assertSame('***', $redacted['api_key']);
        $this->assertSame('kept', $redacted['plain']);
    }


    // ##################
    // ### Request id ###
    // ##################

    public function testRequestIdIsStableWithinTheRequest(): void
    {
        $this->assertSame(ErrorPage::requestId(), ErrorPage::requestId());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{4,64}$/', ErrorPage::requestId());
    }


    // ###################
    // ### Overriding  ###
    // ###################

    public function testAnApplicationCanReplaceTheStatusTemplate(): void
    {
        $template = tempnam(sys_get_temp_dir(), 'sp_status_') . '.php';
        file_put_contents($template, '<p>custom <?= $esc($code) ?></p>');

        Config::$items['error_pages'] = ['status' => $template];

        try {
            $this->assertSame('<p>custom 404</p>', ErrorPage::status(404));
        } finally {
            unlink($template);
        }
    }

    public function testARaisingTemplateFallsBackRatherThanTakingTheProcessWithIt(): void
    {
        $template = tempnam(sys_get_temp_dir(), 'sp_status_') . '.php';
        file_put_contents($template, '<?php throw new \RuntimeException("template is broken");');

        Config::$items['error_pages'] = ['status' => $template];

        $level = ob_get_level();

        try {
            $page = ErrorPage::status(503);

            $this->assertStringContainsString('503', $page);
            $this->assertStringNotContainsString('template is broken', $page);
            // A half closed output buffer would swallow whatever the request printed next
            $this->assertSame($level, ob_get_level());
        } finally {
            unlink($template);
        }
    }
}
