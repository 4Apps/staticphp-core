<?php

/**
 * Path constants and the application class autoloader.
 *
 * Composer's autoloader owns everything under StaticPHP\ and everything in vendor. This
 * file only adds what composer cannot express: the application tree, whose namespace roots
 * are module names (Pasta\Controllers\Quality) resolved against whichever application the
 * request landed in. A site may host several applications side by side - src/site1,
 * src/site2 - each with its own Modules directory and its own front controller, and each
 * knowing nothing about the others. PSR-4's map is static and global, so it cannot express
 * "Pasta\ means whatever the running application says it means"; this callback can.
 *
 * It is registered after composer's, because vendor/autoload.php is what found this file.
 */

// Where the framework itself lives, derived from this file rather than from the
// application, so it stays correct at vendor/4apps/staticphp-core/src as well as in a
// source checkout. Deliberately not named SYS_PATH: that meant "the System directory
// beside Application", which no longer exists, and a consumer still referring to it should
// get a clear undefined-constant error rather than a subtly wrong path.
if (defined('SP_PATH') === false) {
    define('SP_PATH', dirname(__DIR__, 2));
}

// The application root cannot be guessed once the framework is a dependency - the old walk
// up from __FILE__ would find the framework's own demo app inside vendor. The front
// controller knows where it is; it has to say so.
if (defined('PUBLIC_PATH') === false) {
    throw new RuntimeException(
        'PUBLIC_PATH is not defined. A front controller must define it before requiring the'
            . ' framework bootstrap - see src/Application/Public/index.php for the pattern.'
    );
}

// Each of these is conditional on its own, so an application can pin any single one and
// let the rest derive. They used to be one block behind a single check, which meant
// declaring PUBLIC_PATH early silently opted out of all of them.
if (defined('APP_PATH') === false) {
    define('APP_PATH', dirname(PUBLIC_PATH));
}

if (defined('APP_MODULES_PATH') === false) {
    define('APP_MODULES_PATH', APP_PATH . '/Modules');
}

if (defined('BASE_PATH') === false) {
    define('BASE_PATH', dirname(APP_PATH));
}

if (defined('VENDOR_PATH') === false) {
    // Composer's own location is the only reliable answer now that the framework can be
    // installed anywhere. The old probe tried BASE_PATH/vendor then ../vendor, which
    // assumed vendor sat beside the application.
    define(
        'VENDOR_PATH',
        dirname((string) (new ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2)
    );
}

spl_autoload_register(
    function ($classname) {
        $classname = ltrim(str_replace('\\', '/', $classname), '/');

        // Class names reach this point from url segments via the router, so a name
        // containing ".." would otherwise turn into an include of an arbitrary file.
        // Every component has to be a plain identifier.
        foreach (explode('/', $classname) as $part) {
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $part) !== 1) {
                return;
            }
        }

        // Two roots, not five. The chain used to end with SYS_MODULES_PATH, SYS_PATH and
        // BASE_PATH so that an application could shadow a framework class by filename. No
        // project used it, and composer now resolves the framework long before this
        // callback runs, so those probes could only ever cost a failed stat - and a failed
        // is_file() is roughly 50x a successful one, because PHP caches resolved paths but
        // never failures.
        foreach ([APP_MODULES_PATH, APP_PATH] as $root) {
            $file = "{$root}/{$classname}.php";
            if (is_file($file) === false) {
                continue;
            }

            // Defence in depth: confirm the resolved path really is under the root,
            // in case a symlink inside the tree points somewhere else.
            $realFile = realpath($file);
            $realRoot = realpath($root);
            if ($realFile === false || $realRoot === false) {
                continue;
            }

            if (str_starts_with($realFile, rtrim($realRoot, '/') . '/') === false) {
                continue;
            }

            include $realFile;

            return;
        }
    },
    true
);
