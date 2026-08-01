---
title: The front controller
description: Booting StaticPHP Core and the path constants everything derives from.
sidebar:
    order: 2
---

A front controller declares where its application is; everything else derives from that.

```php
<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('PUBLIC_PATH', __DIR__);

require StaticPHP\Core\Bootstrap::FILE;
```

That is the whole file. Composer's autoloader comes first because it is what resolves
`StaticPHP\Core\Bootstrap` on the last line; `dirname(__DIR__, 3)` is only the relative
path from this particular front controller to `vendor/`, so it changes with the layout.

## Why a constant rather than a method

`StaticPHP\Core\Bootstrap` holds two constants and nothing else:

| Constant                    | Points at                       |
| --------------------------- | ------------------------------- |
| `Bootstrap::FILE`     | `src/Core/Helpers/Bootstrap.php` |
| `Bootstrap::AUTOLOAD` | `src/Core/Helpers/Autoload.php`  |

Both are built from `__DIR__`, so they resolve wherever composer put the package - at
`vendor/4apps/staticphp-core/src` in an installed application, somewhere else entirely in
a source checkout. A front controller cannot spell either path out any more, and
resolving the class through PSR-4 answers the question for it.

It is a constant rather than a `run()` method on purpose: `Helpers/Bootstrap.php` sets
`$microtime` at global scope, which `Timers` reads back with `global`, so the `require`
has to happen in the caller's file rather than inside a method.

`Bootstrap::AUTOLOAD` is the smaller entry point - just the path constants and the
application autoloader, no router, no view engine. The cli tools use it to bring up enough
of the framework to reach a database and no more.

## The path constants

`Helpers/Autoload.php` resolves the constants in this order, and each one is guarded by
its own `defined()` check, so an application can pin any single constant ahead of time and
let the rest derive:

1. **`SP_PATH`** - `dirname(__DIR__, 2)` of `Autoload.php` itself, which is the package's
   `src/` directory. Derived from the file's own location rather than from the
   application, so it stays correct in vendor and in a checkout alike.
2. **`PUBLIC_PATH`** - not derived. If it is still undefined at this point the bootstrap
   throws a `RuntimeException`: `PUBLIC_PATH is not defined. A front controller must
   define it before requiring the framework bootstrap`. The application root cannot be
   guessed once the framework is a dependency - walking up from `__FILE__` would find the
   framework's own tree inside `vendor`.
3. **`APP_PATH`** - `dirname(PUBLIC_PATH)`.
4. **`APP_MODULES_PATH`** - `APP_PATH . '/Modules'`.
5. **`BASE_PATH`** - `dirname(APP_PATH)`.
6. **`VENDOR_PATH`** - two directories up from the file that defines
   `Composer\Autoload\ClassLoader`, found by reflection. Composer's own location is the
   only reliable answer now that the framework can be installed anywhere; the old probe
   assumed `vendor/` sat beside the application.

The order matters because of the derivations: `APP_MODULES_PATH` and `BASE_PATH` are built
from `APP_PATH`, which is built from `PUBLIC_PATH`. Defining `APP_PATH` yourself therefore
also moves both of them, while defining `APP_MODULES_PATH` yourself moves only that one.
`SP_PATH` and `VENDOR_PATH` depend on nothing the application controls.

For the layout the exception message names, with the front controller at
`src/Application/Public/index.php`, the constants come out as:

| Constant           | Value                              |
| ------------------ | ---------------------------------- |
| `PUBLIC_PATH`      | `<project>/src/Application/Public`  |
| `APP_PATH`         | `<project>/src/Application`         |
| `APP_MODULES_PATH` | `<project>/src/Application/Modules` |
| `BASE_PATH`        | `<project>/src`                     |
| `VENDOR_PATH`      | `<project>/vendor`                  |

`BASE_PATH` is one level above the application, not the project root, whenever the
application sits inside a subdirectory like this. The package itself only uses it to trim
the deploy directory off file paths on the error page.

One more constant appears later: `BASE_URL`, defined by `Router::splitSegments()` once the
request url has been worked out.

Since the derivation starts at `PUBLIC_PATH`, one repository can hold several
applications side by side, each with its own front controller and its own `Modules`
directory - see
[running multiple applications](/staticphp-core/guides/multiple-applications/).

## The application autoloader

`Autoload.php` also registers a second autoloader, after composer's. Composer owns
everything under `StaticPHP\` and everything in `vendor`; this one adds what PSR-4 cannot
express - an application tree whose namespace roots are module names
(`Pasta\Controllers\Quality`), resolved against whichever application served the request.

It tries two roots in order, `APP_MODULES_PATH` then `APP_PATH`, and includes
`<root>/<class path>.php` if it exists. Class names reach it from url segments by way of
the router, so each component of the name must match `/^[a-zA-Z_][a-zA-Z0-9_]*$/`, and the
resolved file is checked with `realpath()` to confirm it really is under the root before
it is included.

## What the bootstrap does

Requiring `Bootstrap::FILE` runs ten steps: the path constants, the cli superglobals, the
application's configuration, the error handlers, the view engine, and finally
`Router::init()`, which parses the url and calls a controller. Each one is listed with the
ordering constraint that puts it where it is in
[the boot sequence](/staticphp-core/core/bootstrap/).

The rest of this section covers the two parts of it you write yourself:
[configuration](/staticphp-core/getting-started/configuration/) and
[your first route](/staticphp-core/getting-started/first-route/).
