<?php

namespace StaticPHP\Tests\Core\Models;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Controllers\Controller;
use StaticPHP\Core\Exceptions\ErrorMessage;
use StaticPHP\Core\Exceptions\ErrorMessage\BadRequest;
use StaticPHP\Core\Exceptions\ErrorMessage\Forbidden;
use StaticPHP\Core\Exceptions\ErrorMessage\NotFound;
use StaticPHP\Core\Exceptions\RouterException;
use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Router;
use StaticPHP\Utils\Models\Db;
use StaticPHP\Utils\Models\Fv;

class RouterTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('allowed_hosts', []);
    }

    /*
    | Segment validation
    |
    | Url segments are rawurldecode()d after being split on "/", so an encoded slash
    | survives inside a single segment and "%2e%2e%2f" arrives as "../".
    */

    #[DataProvider('unsafeSegmentProvider')]
    public function testUnsafeSegmentsAreRejected($segment)
    {
        $this->assertFalse(Router::isSafeSegment($segment));
    }

    public static function unsafeSegmentProvider(): array
    {
        return [
            'encoded traversal' => [rawurldecode('%2e%2e%2f')],
            'plain traversal'   => ['..'],
            'embedded slash'    => ['a/../../b'],
            'backslash'         => ['a\\b'],
            'null byte'         => ["Defaults\0"],
            'leading digit'     => ['1Module'],
            'empty'             => [''],
            'null'              => [null],
            'dot'               => ['.'],
            'absolute path'     => ['/etc/passwd'],
        ];
    }

    #[DataProvider('safeSegmentProvider')]
    public function testSafeSegmentsAreAccepted(string $segment)
    {
        $this->assertTrue(Router::isSafeSegment($segment));
    }

    public static function safeSegmentProvider(): array
    {
        return [
            'simple'      => ['Defaults'],
            'hyphenated'  => ['my-controller'],
            'underscored' => ['my_controller'],
            'with digits' => ['Module2'],
        ];
    }

    /*
    | Path containment
    */

    /**
     * A real directory to treat as the containment root. The framework's own tree stands in
     * for an application's, the same way the rest of this suite uses it.
     */
    private function root(): string
    {
        return SP_PATH . '/Core';
    }

    public function testPathInsideTheRootIsAccepted()
    {
        $this->assertTrue(
            Router::pathIsWithin($this->root() . '/Models/Router.php', $this->root())
        );
    }

    public function testTraversalOutOfTheRootIsRejected()
    {
        $this->assertFalse(
            Router::pathIsWithin($this->root() . '/../../../../etc/passwd', $this->root())
        );
    }

    public function testEncodedTraversalOutOfTheRootIsRejected()
    {
        $this->assertFalse(
            Router::pathIsWithin(
                $this->root() . '/' . rawurldecode('%2e%2e%2f') . 'Tests/autoload.php',
                $this->root()
            )
        );
    }

    public function testSiblingDirectoryIsRejected()
    {
        $this->assertFalse(Router::pathIsWithin(SP_PATH . '/Tests/autoload.php', $this->root()));
    }

    public function testMissingPathIsRejected()
    {
        $this->assertFalse(Router::pathIsWithin($this->root() . '/nope/nope.php', $this->root()));
    }

    /*
    | Dispatch visibility
    */

    public function testReflectionCanInvokePrivateMethodsOnThisPhp()
    {
        // Since PHP 8.1 setAccessible() is a no-op and reflection reaches private methods
        // by default. If this ever stops holding, the guard below becomes belt and braces
        // rather than load bearing - which is worth knowing about.
        $method = new \ReflectionMethod(Db::class, 'splitCondition');

        $this->assertEquals(['id', '='], $method->invoke(null, 'id'));
    }

    #[DataProvider('nonRoutableMethodProvider')]
    public function testNonRoutableMethods(string $class, string $name)
    {
        $method = new \ReflectionMethod($class, $name);

        $this->assertFalse(Router::isRoutableMethod($method));
    }

    public static function nonRoutableMethodProvider(): array
    {
        return [
            // Private helpers on a controller must not become endpoints
            'private static'  => [Db::class, 'wrapColumn'],
            'private static 2' => [Db::class, 'buildWhere'],
            // Instance methods cannot be invoked with a null scope
            'public instance' => [Fv::class, 'validate'],
            // Lifecycle hooks are called by loadController() itself
            'construct hook'  => [Controller::class, 'construct'],
        ];
    }

    public function testPublicStaticMethodIsRoutable()
    {
        $method = new \ReflectionMethod(Db::class, 'query');

        $this->assertTrue(Router::isRoutableMethod($method));
    }

    public function testMethodLookupIsCaseInsensitiveSoTheGuardMustBeToo()
    {
        // hasMethod() matches case insensitively, so a private method can be reached
        // through a differently cased url segment
        $ref = new \ReflectionClass(Db::class);

        $this->assertTrue($ref->hasMethod('WRAPCOLUMN'));
        $this->assertFalse(Router::isRoutableMethod($ref->getMethod('WRAPCOLUMN')));
    }

    /*
    | Host header
    */

    public function testListedHostIsAccepted()
    {
        Config::set('allowed_hosts', ['example.com', 'www.example.com']);

        $this->assertEquals('example.com', Router::validateHost('example.com'));
    }

    public function testHostComparisonIsCaseInsensitive()
    {
        Config::set('allowed_hosts', ['example.com']);

        $this->assertEquals('example.com', Router::validateHost('EXAMPLE.com'));
    }

    public function testUnlistedHostIsRejected()
    {
        Config::set('allowed_hosts', ['example.com']);

        // A bad Host header is the client's fault, so it must not land in the 500 path
        $this->expectException(BadRequest::class);
        Router::validateHost('evil.test');
    }

    public function testUnlistedHostCarriesA400()
    {
        Config::set('allowed_hosts', ['example.com']);

        try {
            Router::validateHost('evil.test');
            $this->fail('expected a BadRequest');
        } catch (BadRequest $e) {
            $this->assertEquals(400, $e->httpStatusCode);
        }
    }

    public function testMalformedHostIsRejectedWithoutAnAllowlist()
    {
        $this->expectException(BadRequest::class);
        Router::validateHost("example.com\r\nX-Injected: 1");
    }

    public function testPlainHostIsAcceptedWithoutAnAllowlist()
    {
        $this->assertEquals('localhost:8080', Router::validateHost('localhost:8080'));
    }

    /*
    | Helpers
    */

    public function testFrameworkClassesResolveThroughComposer()
    {
        // Composer is the only thing that resolves StaticPHP\ now - the application
        // autoloader probes APP_MODULES_PATH and APP_PATH and nothing else - so a framework
        // class composer cannot find would not be found at all. This used to assert the
        // classmap instead, which a plain psr-4 dump does not produce.
        $loader = require VENDOR_PATH . '/autoload.php';

        $this->assertSame(
            realpath(SP_PATH . '/Core/Models/Router.php'),
            realpath((string) $loader->findFile(Router::class))
        );
        $this->assertNotFalse($loader->findFile(ErrorMessage::class));
    }

    public function testEnsureStartsWithSlash()
    {
        $this->assertEquals('/a', Router::ensureStartsWithSlash('a'));
        $this->assertEquals('/a', Router::ensureStartsWithSlash('/a'));
        $this->assertEquals('', Router::ensureStartsWithSlash(''));
    }

    public function testUrlToNamespace()
    {
        $this->assertEquals('MyController', Router::urlToNamespace('my-controller'));
    }

    public function testNamespaceToUrl()
    {
        $this->assertEquals('my-controller', Router::namespaceToUrl('MyController'));
    }

    public function testUrlToFileNeedsThreeParts()
    {
        $this->assertFalse(Router::urlToFile('too/short'));

        $test = Router::urlToFile('Module/Class/method');
        $this->assertEquals('Module', $test['module']);
        $this->assertEquals('Class', $test['class']);
        $this->assertEquals('method', $test['method']);
    }
}
