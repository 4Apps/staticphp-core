<?php

namespace StaticPHP\Utils\Models\Doctor;

/**
 * How one diagnostic turned out.
 */
enum Status: string
{
    /** Nothing to do. */
    case OK = 'ok';

    /**
     * Works, but somebody should look at it: a pending migration, an extension that is
     * missing only for a feature the application may not use, debug output left on.
     */
    case WARN = 'warn';

    /** Broken now, or broken the moment the affected code runs. */
    case FAIL = 'fail';
}
