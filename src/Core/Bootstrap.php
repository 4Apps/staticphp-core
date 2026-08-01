<?php

namespace StaticPHP\Core;

/**
 * Where the framework bootstrap lives.
 *
 * A front controller cannot spell that path out any more: installed by composer the
 * framework sits at vendor/4apps/staticphp-core/src, and in a source checkout it does not.
 * Resolving this class through PSR-4 answers the question wherever it ended up.
 *
 * It is a constant rather than a run() method on purpose. Helpers/Bootstrap.php has to be
 * required at global scope - it sets $microtime, which Timers reads with `global` - so the
 * require has to happen in the caller's file, not inside a method here.
 */
final class Bootstrap
{
    /**
     * Absolute path to the framework bootstrap, for the front controller to require.
     *
     * @var string
     */
    public const FILE = __DIR__ . '/Helpers/Bootstrap.php';

    /**
     * Absolute path to just the path constants and the application autoloader.
     *
     * For callers that want the path constants without the rest - the cli tools bring up
     * enough of the framework to reach a database and no more, so they never init the
     * router or build a view engine.
     *
     * @var string
     */
    public const AUTOLOAD = __DIR__ . '/Helpers/Autoload.php';
}
