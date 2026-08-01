<?php

/**
 * Bootstrap for the framework's own test suite.
 *
 * Composer resolves StaticPHP\ and StaticPHP\Tests\, so this file only has to supply the
 * path constants. It used to carry a copy of the application autoloader, which drifted
 * from the real one in src/Core/Helpers/Autoload.php.
 *
 * The framework stands in for the application here: APP_PATH points at the framework
 * itself, so a suite that exercises Load:: or the router resolves against the shipped
 * modules rather than needing a demo application to exist.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

define('SP_PATH', dirname(__DIR__) . '/src');
define('PUBLIC_PATH', SP_PATH . '/Public');
define('APP_PATH', SP_PATH);
define('APP_MODULES_PATH', SP_PATH);
define('BASE_PATH', dirname(SP_PATH));

require StaticPHP\Core\Bootstrap::AUTOLOAD;
