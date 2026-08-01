<?php

/**
 * Prove the framework boots and serves a request with twig absent.
 *
 * The point of listing twig under "suggest" rather than "require" is that an api-only
 * application pays nothing for it - composer's "files" autoload is eager, so twig and the
 * symfony polyfills it pulls in load eight files on every request whether or not a
 * template is rendered. That only holds while nothing in the framework references a Twig
 * class outside a guard, and the normal suite cannot catch a regression there because it
 * installs twig as a dev dependency.
 *
 * Run after `composer install --no-dev`:
 *
 *     php scripts/boot_without_twig.php
 *
 * It builds a throwaway application in a temp directory, serves one request through the
 * real bootstrap, and checks the output.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

if (class_exists(\Twig\Environment::class)) {
    fwrite(STDERR, "error: twig is installed - run composer install --no-dev first\n");

    exit(2);
}

/**
 * Write a file, creating its directory.
 */
function put(string $path, string $contents): void
{
    if (is_dir(dirname($path)) === false) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, $contents);
}

$app = sys_get_temp_dir() . '/staticphp-no-twig-' . bin2hex(random_bytes(4));

put("{$app}/Public/.keep", '');

put("{$app}/Config/Config.php", <<<'PHP'
<?php

$config['base_url'] = 'http://localhost/';
$config['disable_twig'] = false;
$config['environment'] = 'prod';
$config['debug'] = false;
$config['debug_ips'] = [];
$config['allowed_hosts'] = [];
$config['view_env_keys'] = [];
$config['url_prefixes'] = [];
$config['module_paths'] = [];
$config['autoload_configs'] = [];
$config['autoload_helpers'] = [];
$config['before_controller'] = [];
$config['error_pages'] = ['status' => null, 'debug' => null];
$config['request_uri'] = & $_SERVER['REQUEST_URI'];
$config['query_string'] = & $_SERVER['QUERY_STRING'];
$config['script_name'] = & $_SERVER['SCRIPT_NAME'];
$config['client_ip'] = & $_SERVER['REMOTE_ADDR'];
PHP);

put("{$app}/Config/Routing.php", <<<'PHP'
<?php

$config['routing'] = ['' => 'Probe/Ping/index'];
PHP);

// A plain php view, the fallback Load::view() uses when there is no engine
put("{$app}/Modules/Probe/Views/pong.php", <<<'PHP'
<?php echo 'pong:' . $marker; ?>
PHP);

put("{$app}/Modules/Probe/Controllers/Ping.php", <<<'PHP'
<?php

namespace Probe\Controllers;

use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Load;
use StaticPHP\Utils\Models\Csrf;
use StaticPHP\Utils\Models\Fv;
use StaticPHP\Utils\Models\BitwiseFlag;
use StaticPHP\Presentation\Models\Menu\Menu;

class Ping
{
    public static function index()
    {
        // Every shipped registerTwig() has to be a quiet no-op rather than a fatal
        Csrf::registerTwig();
        Fv::registerTwig();
        BitwiseFlag::registerTwig();
        Menu::registerTwig();

        $engine = Config::get('view_engine');
        if (empty($engine) === false) {
            throw new \RuntimeException('a view engine was built without twig installed');
        }

        $data = ['marker' => 'ok'];
        Load::view(['Probe/Views/pong.php'], $data);
    }
}
PHP);

$_SERVER['REQUEST_URI'] = '/';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REQUEST_METHOD'] = 'GET';

define('PUBLIC_PATH', "{$app}/Public");

ob_start();
require StaticPHP\Core\Bootstrap::FILE;
$body = (string) ob_get_clean();

/**
 * Remove the throwaway application.
 */
function scrub(string $dir): void
{
    foreach ((array) scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = "{$dir}/{$entry}";
        is_dir($path) ? scrub($path) : unlink($path);
    }

    rmdir($dir);
}

scrub($app);

if (str_contains($body, 'pong:ok') === false) {
    fwrite(STDERR, "error: unexpected response without twig:\n{$body}\n");

    exit(1);
}

echo "ok: framework booted and served a request with twig absent\n";
