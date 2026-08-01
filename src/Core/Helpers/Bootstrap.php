<?php

use StaticPHP\Core\Models\Load;
use StaticPHP\Core\Models\Logger;
use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Request;
use StaticPHP\Core\Models\Router;
use StaticPHP\Core\Models\Timers;
use StaticPHP\Utils\Models\ExtendedDateTime;

// Set microtime
$microtime = microtime(true);

// Autoload
require_once dirname(__FILE__) . '/Autoload.php';

// Build the request superglobals from argv when running under cli, so that
// Request::internal() actually reaches the url it was given. Must run before the
// configuration below, which binds references into $_SERVER.
Request::populateFromCli();

// Load default config file and routing
Config::load(['Config', 'Routing']);

// Resolve the client address now that the configuration governing it is loaded. Left to
// whatever the application set if it set anything, so an install with its own idea of
// where the address comes from keeps it; null means "work it out", as base_url does.
if (Config::get('client_ip', null) === null) {
    Config::$items['client_ip'] = Router::clientIp();
}

// Set debug
Config::$items['now'] = time();
Config::$items['date_time'] = new ExtendedDateTime();

// Resolved here rather than read straight from config: the application decides who may
// see debug output, through $config['debug_check']. See Config::resolveDebug()
Config::$items['debug'] = Config::resolveDebug();
ini_set(
    'error_reporting',
    // E_STRICT was folded into E_ALL long ago and the constant itself is deprecated as of
    // PHP 8.4, so referencing it here would emit a deprecation of its own
    (Config::$items['debug'] ? E_ALL : E_ALL & ~E_DEPRECATED)
);
ini_set('display_errors', (int) Config::$items['debug']);

// Autoload additional config files
$autoload_configs = Config::get('autoload_configs');
if ($autoload_configs !== false) {
    foreach ($autoload_configs as $item) {
        $tmp = explode('/', $item);
        $count = count($tmp);
        if ($count == 3) {
            Config::load([$tmp[2]], $tmp[1], $tmp[0]);
        } elseif ($count == 2) {
            Config::load([$tmp[1]], $tmp[0]);
        } else {
            Config::load([$tmp[0]]);
        }
    }
}

// Register error handlers
Load::helper(['ErrorHandlers'], 'Core', 'staticphp');
set_error_handler(
    'sp_error_handler',
    // E_STRICT was folded into E_ALL long ago and the constant itself is deprecated as of
    // PHP 8.4, so referencing it here would emit a deprecation of its own
    (!empty(Config::$items['debug']) ? E_ALL : E_ALL & ~E_DEPRECATED)
);
set_exception_handler('sp_exception_handler');

// Load twig.
//
// The library is a suggestion rather than a requirement of staticphp-core, so an api only
// deployment can leave it out and pay nothing for it - composer's "files" autoload is
// eager, and twig plus its symfony polyfills pull in eight files on every single request
// whether or not a template is ever rendered. disable_twig still skips instantiation for
// installs that do have it.
//
// class_exists rather than probing VENDOR_PATH . '/twig/twig/src/Token.php': that hardcoded
// both a vendor layout and an internal twig file that is free to move between releases.
if (Config::get('disable_twig') !== true && class_exists(\Twig\Environment::class)) {
    Config::$items['view_loader'] = new \Twig\Loader\FilesystemLoader(
        [
            APP_MODULES_PATH,
            APP_PATH,
            SP_PATH . '/Core/Views'
        ]
    );
    Config::$items['view_engine'] = new \Twig\Environment(
        Config::$items['view_loader'],
        [
            'cache' => (
                Config::get('debug') == true
                ? false
                : APP_PATH . '/Cache/Views/'
            ),
            'debug' => Config::get('debug'),
            // 'strict_variables' => Config::get('debug'),
        ]
    );

    // Register default filters and functions
    // Site url filter
    $filter = new \Twig\TwigFilter(
        'siteUrl',
        function ($url = '', $prefix = null, $current_prefix = true) {
            return Router::siteUrl($url, $prefix, $current_prefix);
        }
    );
    Config::get('view_engine')->addFilter($filter);

    // Site url function
    $function = new \Twig\TwigFunction(
        'siteUrl',
        function ($url = '', $prefix = null, $current_prefix = true) {
            return Router::siteUrl($url, $prefix, $current_prefix);
        }
    );
    Config::get('view_engine')->addFunction($function);

    // Start timer function
    $function = new \Twig\TwigFunction(
        'startTimer',
        function () {
            Timers::startTimer();
        }
    );
    Config::get('view_engine')->addFunction($function);

    // Stop timer function
    $function = new \Twig\TwigFunction(
        'stopTimer',
        function ($name) {
            Timers::stopTimer($name);
        }
    );
    Config::get('view_engine')->addFunction($function);

    // Mark time function
    $function = new \Twig\TwigFunction(
        'markTime',
        function ($name) {
            Timers::markTime($name);
        }
    );
    Config::get('view_engine')->addFunction($function);

    // Debug output function
    $function = new \Twig\TwigFunction(
        'debugOutput',
        function () {
            return Logger::debugOutput();
        }
    );
    Config::get('view_engine')->addFunction($function);

    // CSRF helpers - csrfToken(), csrfFieldName() and csrfField().
    // Registering them only makes the token available to templates; validating incoming
    // requests is the application's job, see StaticPHP\Utils\Models\Csrf.
    \StaticPHP\Utils\Models\Csrf::registerTwig();
}

// Autoload helpers
$autoload_helpers = Config::get('autoload_helpers');
if ($autoload_helpers !== false) {
    foreach ($autoload_helpers as $item) {
        $tmp = explode('/', $item);
        $count = count($tmp);
        if ($count == 3) {
            Load::helper([$tmp[2]], $tmp[1], $tmp[0]);
        } elseif ($count == 2) {
            Load::helper([$tmp[1]], $tmp[0]);
        } else {
            Load::helper([$tmp[0]]);
        }
    }
}

// Init router
Router::init();
