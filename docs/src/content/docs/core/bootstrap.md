---
title: Bootstrap
description: The ten steps the framework bootstrap runs, and why each sits where it does.
sidebar:
    order: 1
---

`require StaticPHP\Core\Bootstrap::FILE` is the whole of starting the framework. This page
is what that one line sets in motion, in order.

`Bootstrap::FILE` and `Bootstrap::AUTOLOAD`, the path constants they resolve, and why the
entry point is a constant rather than a `run()` method are covered in
[the front controller](/staticphp-core/getting-started/front-controller/). The short version
is that `Bootstrap::AUTOLOAD` brings up the path constants and the application autoloader
and nothing else, while `Bootstrap::FILE` - the file this page describes - brings up
everything.

## The boot sequence

Requiring `Bootstrap::FILE` runs the following top to bottom. Each step is listed with the
reason it sits where it does, because the order is the interesting part - most of these
steps depend on the one before it having already run.

1. **`$microtime = microtime(true)`.** First statement in the file, at global scope. Every
    number [Timers](/staticphp-core/core/timers/) reports is measured from here.

2. **`require_once dirname(__FILE__) . '/Autoload.php'`.** Defines `SP_PATH`,
    `APP_PATH`, `APP_MODULES_PATH`, `BASE_PATH` and `VENDOR_PATH`, throws a
    `RuntimeException` if the front controller never defined `PUBLIC_PATH`, and registers
    the application autoloader. Nothing below can name an application file until this has
    run.

3. **`Request::populateFromCli()`.** Under the cli SAPI this rewrites `$_GET`, `$_POST`,
    `$_REQUEST` and several `$_SERVER` keys from `argv`; under any other SAPI it returns
    immediately. It must precede step 4 because the application's `Config.php` binds
    `$config['request_uri']` and friends to `$_SERVER` entries *by reference* - rewriting
    the superglobals afterwards would leave the configuration describing a request that was
    never made. See [Request](/staticphp-core/core/request/).

4. **`Config::load(['Config', 'Routing'])`.** Reads `APP_PATH/Config/Config.php` and
    `APP_PATH/Config/Routing.php` into `Config::$items`.

5. **Debug is decided, and `now` and `date_time` are written.**

    ```php
    <?php

    Config::$items['now'] = time();
    Config::$items['date_time'] = new ExtendedDateTime();
    Config::$items['debug'] = (
        Config::get('debug')
        || in_array(Config::get('client_ip', '127.0.0.1'), (array) Config::get('debug_ips', []))
    );
    ```

    `error_reporting` is then set to `E_ALL` when debug is on and `E_ALL & ~E_DEPRECATED`
    when it is off, and `display_errors` to `(int) Config::get('debug')` - which by this
    point is the value computed just above, not the one the config file assigned. Every
    later step reads `Config::$items['debug']`, so nothing that cares about debug mode can
    run before this.

6. **`$config['autoload_configs']` is loaded.** Each entry is split on `/` and read right
    to left: one part is a file in `APP_PATH/Config`, two parts are `module/file`, three
    are `project/module/file`.

    The guard is `if ($autoload_configs !== false)`, but `Config::get()` returns `null` for
    a key that was never set - so an application that omits `autoload_configs` entirely
    reaches `foreach (null as ...)` and PHP raises `foreach() argument must be of type
    array|object, null given`. Define the key as `[]` rather than leaving it out. The same
    applies to `autoload_helpers` in step 9, and there it is worse: by then the error
    handler is installed, so the warning becomes a thrown `SpErrorException` and the
    request ends in a 500 rather than a printed warning.

7. **The error handlers are registered.** `Load::helper(['ErrorHandlers'], 'Core',
    'staticphp')` requires `src/Core/Helpers/ErrorHandlers.php`, then:

    ```php
    <?php

    set_error_handler('sp_error_handler', (!empty(Config::$items['debug']) ? E_ALL : E_ALL & ~E_DEPRECATED));
    set_exception_handler('sp_exception_handler');
    ```

    Everything above this line runs under PHP's default handling; a failure in the
    application's `Config.php` will not render through the framework's error pages. See
    [errors](/staticphp-core/core/errors/).

8. **The twig environment is built**, unless `$config['disable_twig'] === true` or
    `\Twig\Environment` does not exist. The check is `class_exists()` rather than a probe
    for a file under `VENDOR_PATH`, so it survives twig moving its own internals around.
    Details below.

9. **`$config['autoload_helpers']` is loaded**, using the same one/two/three part naming as
    step 6, through `Load::helper()`.

10. **`Router::init()`.** Parses the url, finds a controller and calls it. Everything the
    request does happens inside this call. See [the router](/staticphp-core/core/router/).

There is no step after `Router::init()`. Control returns to the front controller, which by
convention has nothing left to do.

## What the bootstrap writes back into the configuration

| Key           | Value                                                                  |
| ------------- | ---------------------------------------------------------------------- |
| `now`         | `time()` at boot                                                       |
| `date_time`   | a `StaticPHP\Utils\Models\ExtendedDateTime` instance                   |
| `debug`       | the computed boolean from step 5                                       |
| `view_loader` | `\Twig\Loader\FilesystemLoader`, only when twig is in use              |
| `view_engine` | `\Twig\Environment`, only when twig is in use                          |

`Router::splitSegments()` later defines the `BASE_URL` constant as well.

## The twig environment

When it is built, the loader searches three directories in this order:

```php
<?php

new \Twig\Loader\FilesystemLoader([
    APP_MODULES_PATH,
    APP_PATH,
    SP_PATH . '/Core/Views',
]);
```

The environment gets `cache => APP_PATH . '/Cache/Views/'`, or `false` when debug is on,
and `debug => Config::get('debug')`.

Registered on it:

| Name           | Kind     | Signature                                                            |
| -------------- | -------- | -------------------------------------------------------------------- |
| `siteUrl`      | filter   | `($url = '', $prefix = null, $current_prefix = true)`                |
| `siteUrl`      | function | `($url = '', $prefix = null, $current_prefix = true)`                |
| `startTimer`   | function | `()`                                                                 |
| `stopTimer`    | function | `($name)`                                                            |
| `markTime`     | function | `($name)`                                                            |
| `debugOutput`  | function | `()`                                                                 |

`siteUrl` forwards to `Router::siteUrl()`; the three timer functions forward to `Timers`;
`debugOutput` returns `Logger::debugOutput()`. `\StaticPHP\Utils\Models\Csrf::registerTwig()`
is then called, which adds the `csrfToken()`, `csrfFieldName()` and `csrfField()` helpers -
registering them only makes the token reachable from a template, validating an incoming
request is still the application's job.

If twig is not installed, none of this exists and `Load::view()` renders plain php
templates instead. See [Load](/staticphp-core/core/load/).

## The application autoloader

Step 2 above also registers a second `spl_autoload_register()` callback, in
`src/Core/Helpers/Autoload.php`. It is what resolves the application tree, whose namespace
roots are module names rather than a PSR-4 prefix - see
[the front controller](/staticphp-core/getting-started/front-controller/) for what it
resolves and how, and
[running multiple applications](/staticphp-core/guides/multiple-applications/) for why it
exists at all.
