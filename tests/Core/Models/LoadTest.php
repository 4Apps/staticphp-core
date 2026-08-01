<?php

namespace StaticPHP\Tests\Core\Models;

use PHPUnit\Framework\TestCase;
use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Load;

class LoadTest extends TestCase
{
    public function testUuid4Shape(): void
    {
        $test = Load::uuid4();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $test
        );
    }

    public function testUuid4DoesNotRepeat(): void
    {
        $values = [];
        for ($i = 0; $i < 200; ++$i) {
            $values[] = Load::uuid4();
        }

        $this->assertCount(200, array_unique($values));
    }

    public function testRandomHashShape(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', Load::randomHash());
    }

    public function testRandomHashDoesNotRepeat(): void
    {
        $values = [];
        for ($i = 0; $i < 200; ++$i) {
            $values[] = Load::randomHash();
        }

        $this->assertCount(200, array_unique($values));
    }

    /*
    | Template globals
    */

    public function testEnvIsNotExposedToTemplatesByDefault(): void
    {
        $_ENV['A_SECRET_VALUE'] = 'do not leak';
        Config::set('view_env_keys', []);

        $method = new \ReflectionMethod(Load::class, 'safeEnvForViews');

        $this->assertEquals([], $method->invoke(null));
    }

    public function testOnlyAllowlistedEnvKeysAreExposed(): void
    {
        $_ENV['PUBLIC_VALUE'] = 'fine';
        $_ENV['DB_PASSWORD'] = 'do not leak';
        Config::set('view_env_keys', ['PUBLIC_VALUE']);

        $method = new \ReflectionMethod(Load::class, 'safeEnvForViews');
        $test = $method->invoke(null);

        $this->assertIsArray($test);
        $this->assertEquals(['PUBLIC_VALUE' => 'fine'], $test);
        $this->assertArrayNotHasKey('DB_PASSWORD', $test);

        Config::set('view_env_keys', []);
    }

    public function testDatabaseConfigIsNotExposedToTemplates(): void
    {
        Config::set('db', ['pdo' => ['default' => ['password' => 'do not leak']]]);

        $method = new \ReflectionMethod(Load::class, 'safeConfigForViews');
        $test = $method->invoke(null);

        $this->assertIsArray($test);
        $this->assertArrayNotHasKey('db', $test);
    }

    public function testSensitiveKeysAreStrippedAtEveryDepth(): void
    {
        Config::set('some_service', [
            'endpoint' => 'https://example.test',
            'api_key' => 'do not leak',
            'nested' => ['secret' => 'do not leak either', 'public' => 'fine'],
        ]);

        $method = new \ReflectionMethod(Load::class, 'safeConfigForViews');
        $test = $method->invoke(null);

        $this->assertIsArray($test);
        $this->assertIsArray($test['some_service']);
        $this->assertIsArray($test['some_service']['nested']);

        $this->assertEquals('https://example.test', $test['some_service']['endpoint']);
        $this->assertArrayNotHasKey('api_key', $test['some_service']);
        $this->assertArrayNotHasKey('secret', $test['some_service']['nested']);
        $this->assertEquals('fine', $test['some_service']['nested']['public']);
    }

    public function testExtendedDateTimeBuildsItsFormattersLazily(): void
    {
        // The constructor used to build four IntlDateFormatters eagerly, which cost ~830us
        // per instance and ran on every request via the bootstrap. Formatting still has to
        // work; it just must not happen until asked for.
        $instance = new \StaticPHP\Utils\Models\ExtendedDateTime('2026-08-01 13:45:00');

        $formatters = new \ReflectionProperty($instance, 'formatters');

        $beforeUse = $formatters->getValue($instance);
        $this->assertSame([], $beforeUse, 'formatters built before use');

        $this->assertNotEmpty($instance->formatDate());
        $afterDate = $formatters->getValue($instance);
        $this->assertIsArray($afterDate);
        $this->assertCount(1, $afterDate, 'more than the needed formatter was built');

        $this->assertNotEmpty($instance->formatTime());
        $afterTime = $formatters->getValue($instance);
        $this->assertIsArray($afterTime);
        $this->assertCount(2, $afterTime);
    }

    public function testOrdinaryConfigStillReachesTemplates(): void
    {
        Config::set('a_plain_setting', 'value');

        $method = new \ReflectionMethod(Load::class, 'safeConfigForViews');
        $test = $method->invoke(null);

        $this->assertIsArray($test);
        $this->assertEquals('value', $test['a_plain_setting']);
    }

    /*
    | Module path resolution
    */

    public function testTheFrameworksOwnModulesResolveWithoutAnyConfiguration(): void
    {
        // Reserved rather than a $config['module_paths'] entry: an application assigning
        // module_paths used to wipe the registration, and then every framework config load
        // failed depending on which ran first
        Config::set('module_paths', []);

        $method = new \ReflectionMethod(Load::class, 'resolve');

        $this->assertSame(
            SP_PATH . '/Utils/Helpers/Helpers.php',
            $method->invoke(null, Load::FRAMEWORK_PATH, 'Utils', 'Helpers', 'Helpers')
        );
    }

    public function testARegisteredModulePathResolvesAgainstItsDirectory(): void
    {
        Config::set('module_paths', ['site2' => '/srv/app/src/site2/Modules']);

        $method = new \ReflectionMethod(Load::class, 'resolve');

        $this->assertSame(
            '/srv/app/src/site2/Modules/Pasta/Config/Config.php',
            $method->invoke(null, 'site2', 'Pasta', 'Config', 'Config')
        );
    }

    public function testAnUnregisteredModulePathIsRefused(): void
    {
        Config::set('module_paths', []);

        $method = new \ReflectionMethod(Load::class, 'resolve');

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke(null, 'nope', 'Pasta', 'Config', 'Config');
    }

    public function testAModulePathWithoutAModuleIsRefused(): void
    {
        // The entries are module roots, so naming one without a module is meaningless
        $method = new \ReflectionMethod(Load::class, 'resolve');

        $this->expectException(\InvalidArgumentException::class);
        $method->invoke(null, 'site2', null, 'Config', 'Config');
    }

    /*
    | Plain php views
    |
    | twig is a suggestion of this package, not a requirement, so this fallback is the
    | whole view layer for an application that leaves it out - not just an error path.
    */

    /**
     * Write a view under APP_MODULES_PATH and return the path Load::view() takes.
     */
    private function writeView(string $body): string
    {
        $name = 'sp_view_test_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents(APP_MODULES_PATH . '/' . $name, $body);

        return $name;
    }

    protected function tearDown(): void
    {
        foreach ((array) glob(APP_MODULES_PATH . '/sp_view_test_*.php') as $leftover) {
            unlink((string) $leftover);
        }
    }

    public function testPlainViewReceivesTheDataItWasGiven(): void
    {
        // The fallback used to require the file without extracting $data at all, so a
        // plain view could not be passed anything
        $view = $this->writeView('<?php echo "got:{$greeting}"; ?>');
        $data = ['greeting' => 'hello'];

        $this->assertSame('got:hello', Load::view([$view], $data, true));
    }

    public function testPlainViewCanStillReachConfig(): void
    {
        Config::set('a_plain_setting', 'value');
        $view = $this->writeView('<?php echo $config["a_plain_setting"]; ?>');
        $data = [];

        $this->assertSame('value', Load::view([$view], $data, true));
    }

    public function testPlainViewDataCannotOverwriteTheRenderersOwnVariables(): void
    {
        // extract() into the calling scope would let a key named "files" or "path" take
        // over the loop, so the include happens in a scope of its own
        $view = $this->writeView('<?php echo gettype($files) . "|" . $marker; ?>');
        $data = ['files' => 'hijacked', 'path' => 'hijacked', 'marker' => 'intact'];

        $this->assertSame('string|intact', Load::view([$view], $data, true));
    }

    public function testPlainViewOutsideTheModulesDirectoryIsRefused(): void
    {
        $data = [];

        $this->expectException(\RuntimeException::class);
        Load::view(['../../../etc/passwd'], $data, true);
    }

    public function testAFailingPlainViewDoesNotLeaveABufferOpen(): void
    {
        // A half closed output buffer would swallow whatever the request printed next
        $level = ob_get_level();
        $view = $this->writeView('<?php echo "before"; throw new \RuntimeException("boom"); ?>');
        $data = [];

        try {
            Load::view([$view], $data, true);
            $this->fail('the view should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($level, ob_get_level());
    }
}
