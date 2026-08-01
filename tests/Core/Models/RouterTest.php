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
        Config::set('trust_proxy_headers', false);
        Config::set('trusted_proxy_hops', 1);

        // SERVER_PORT among them: requestIsSecure() falls back to it, so a test that leaves
        // 443 behind makes every later request look encrypted
        unset(
            $_SERVER['HTTPS'],
            $_SERVER['HTTP_X_FORWARDED_PROTO'],
            $_SERVER['HTTP_X_FORWARDED_PORT'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['SERVER_PORT'],
            $_SERVER['REMOTE_ADDR']
        );

        Router::$domain_url = null;
        Router::$base_url = null;
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
    | Forwarded headers
    |
    | base_url is built from the connection this process sees, which behind a proxy is the
    | internal hop rather than the request the client made.
    */

    public function testForwardedHeadersAreIgnoredByDefault()
    {
        $this->assertNull(Router::forwardedHeader('HTTP_X_FORWARDED_PROTO'));
    }

    public function testForwardedHeaderIsReadWhenTrusted()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertEquals('https', Router::forwardedHeader('HTTP_X_FORWARDED_PROTO'));
    }

    public function testChainedProxiesLeaveTheOriginalRequestLeftmost()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';

        $this->assertEquals('https', Router::forwardedHeader('HTTP_X_FORWARDED_PROTO'));
    }

    public function testAbsentHeaderIsNullRatherThanEmpty()
    {
        Config::set('trust_proxy_headers', true);

        $this->assertNull(Router::forwardedHeader('HTTP_X_FORWARDED_PORT'));
    }

    public function testDirectTlsIsSecure()
    {
        $_SERVER['HTTPS'] = 'on';

        $this->assertTrue(Router::requestIsSecure());
    }

    public function testProxiedTlsIsNotSecureWithoutTrust()
    {
        // The connection this process sees is the proxy's plain http hop
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertFalse(Router::requestIsSecure());
    }

    public function testProxiedTlsIsSecureWhenTrusted()
    {
        Config::set('trust_proxy_headers', true);
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $this->assertTrue(Router::requestIsSecure());
    }

    public function testTrustedPlainProxyHopOverridesAStaleHttpsFlag()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';

        $this->assertFalse(Router::requestIsSecure());
    }

    public function testInternalPortIsNotAdvertisedWhenTrusted()
    {
        Config::set('trust_proxy_headers', true);

        // The container listens on plain http 8080, the client connected to tls 443
        $this->assertEquals('https://example.com', $this->buildDomainUrl([
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '8080',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ]));
    }

    public function testInternalPortLeaksWithoutTrust()
    {
        $this->assertEquals('http://example.com:8080', $this->buildDomainUrl([
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '8080',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ]));
    }

    public function testNonStandardForwardedPortIsKept()
    {
        Config::set('trust_proxy_headers', true);

        $this->assertEquals('http://example.com:8000', $this->buildDomainUrl([
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '8080',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_PORT' => '8000',
        ]));
    }

    public function testGarbageForwardedPortFallsBackToTheRealOne()
    {
        Config::set('trust_proxy_headers', true);

        // Never reachable through a proxy that rewrites the header, but a config item is
        // easier to get wrong than to get right
        $this->assertEquals('http://example.com:8080', $this->buildDomainUrl([
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '8080',
            'HTTP_X_FORWARDED_PORT' => '443; rm -rf /',
        ]));
    }

    /**
     * The setup the whole feature exists for. nginx and traefik set X-Forwarded-Proto by
     * default and X-Forwarded-Port only when told to, so the port has to come from the
     * forwarded scheme rather than from the port this process happens to listen on.
     */
    public function testProtoWithoutForwardedPortDoesNotAdvertiseTheInternalPort()
    {
        Config::set('trust_proxy_headers', true);

        $this->assertEquals('https://example.com', $this->buildDomainUrl([
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '8080',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]));
    }

    public function testPlainProtoWithoutForwardedPortDoesNotAdvertiseTheInternalPort()
    {
        Config::set('trust_proxy_headers', true);

        $this->assertEquals('http://example.com', $this->buildDomainUrl([
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '8080',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ]));
    }

    /**
     * php documents HTTPS as "a non-empty value", not as "on". iis sets the literal "off"
     * for a plain request, which is why emptiness alone cannot be the test either.
     */
    public function testNonOnHttpsValuesAreStillSecure()
    {
        $_SERVER['HTTPS'] = '1';

        $this->assertTrue(Router::requestIsSecure());
    }

    public function testIisStyleOffIsNotSecure()
    {
        $_SERVER['HTTPS'] = 'off';

        $this->assertFalse(Router::requestIsSecure());
    }

    /**
     * A server terminating tls without setting HTTPS. The session cookie's Secure flag and
     * the error page's absolute urls both read this, so they cannot be allowed to disagree.
     */
    public function testPort443WithoutTheHttpsVariableIsSecure()
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = '443';

        $this->assertTrue(Router::requestIsSecure());
    }

    public function testTrustedPlainProxyHopBeatsThePort443Fallback()
    {
        Config::set('trust_proxy_headers', true);
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';

        $this->assertFalse(Router::requestIsSecure());
    }

    public function testDirectTlsStillWorksWhenTrustIsOnButNothingForwards()
    {
        Config::set('trust_proxy_headers', true);

        $this->assertEquals('https://example.com', $this->buildDomainUrl([
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '443',
            'HTTPS' => 'on',
        ]));
    }

    /*
    | Client address
    |
    | REMOTE_ADDR is the proxy when one sits in front. X-Forwarded-For carries the client,
    | but is appended to rather than overwritten, so which end is trustworthy matters.
    */

    public function testClientIpIsRemoteAddrByDefault()
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

        $this->assertEquals('10.0.0.9', Router::clientIp());
    }

    public function testClientIpComesFromForwardedForWhenTrusted()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

        $this->assertEquals('203.0.113.7', Router::clientIp());
    }

    /**
     * The reason entries are counted from the right. nginx appends the peer it saw with
     * $proxy_add_x_forwarded_for, so a client that sends its own X-Forwarded-For puts a
     * value of its choosing leftmost - and applications gate on this value.
     */
    public function testSpoofedLeftmostEntryIsNotTrusted()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1, 203.0.113.7';

        $this->assertEquals('203.0.113.7', Router::clientIp());
    }

    public function testExtraHopIsCountedFromTheRight()
    {
        Config::set('trust_proxy_headers', true);
        Config::set('trusted_proxy_hops', 2);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';

        // client, cdn, ingress - two proxies in front, so the client is second from right
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1, 203.0.113.7, 198.51.100.4';

        $this->assertEquals('203.0.113.7', Router::clientIp());
    }

    public function testShorterChainThanConfiguredDoesNotUnderflow()
    {
        Config::set('trust_proxy_headers', true);
        Config::set('trusted_proxy_hops', 5);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';

        $this->assertEquals('203.0.113.7', Router::clientIp());
    }

    public function testGarbageForwardedForFallsBackToRemoteAddr()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-address';

        $this->assertEquals('10.0.0.9', Router::clientIp());
    }

    public function testForwardedForPortIsStripped()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7:41234';

        $this->assertEquals('203.0.113.7', Router::clientIp());
    }

    public function testForwardedForAcceptsIpv6()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::1';

        $this->assertEquals('2001:db8::1', Router::clientIp());
    }

    public function testForwardedForAcceptsBracketedIpv6WithPort()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '[2001:db8::1]:41234';

        $this->assertEquals('2001:db8::1', Router::clientIp());
    }

    public function testEmptyForwardedForFallsBackToRemoteAddr()
    {
        Config::set('trust_proxy_headers', true);
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '';

        $this->assertEquals('10.0.0.9', Router::clientIp());
    }

    public function testNoAddressAtAllIsNullRatherThanEmptyString()
    {
        unset($_SERVER['REMOTE_ADDR']);

        $this->assertNull(Router::clientIp());
    }

    /**
     * Run splitSegments() against a synthetic request and return the domain it derived.
     */
    private function buildDomainUrl(array $server): string
    {
        $backup = $_SERVER;
        $_SERVER = array_merge($_SERVER, $server);

        Config::set('base_url', null);
        Config::set('request_uri', '/');
        Config::set('script_name', '/index.php');
        Config::set('routing', []);
        Config::set('url_prefixes', []);

        Router::$domain_url = null;
        Router::$base_url = null;

        try {
            Router::splitSegments(true);

            return (string) Router::$domain_url;
        } finally {
            $_SERVER = $backup;
        }
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
