[![packagist](http://img.shields.io/badge/packagist-4apps%2Fstaticphp--core-brightgreen.svg)](https://packagist.org/packages/4apps/staticphp-core)

# StaticPHP Core

The StaticPHP framework, as a library.

This is the package an application depends on. The application skeleton - the demo app,
the asset pipeline, the dev container, the `staticphp` cli - lives in
[gintsmurans/staticphp](https://github.com/gintsmurans/staticphp) and is what
`composer create-project` gives you.

```bash
composer require 4apps/staticphp-core
```

## Requirements

-   PHP 8.4+
-   ext-intl, ext-mbstring, ext-pdo
-   Twig 3.0+ (optional, see below)

## Layout

Everything is under the `StaticPHP\` namespace, resolved by PSR-4:

    src/Core/            Config, Load, Request, Router, Logger, error pages, bootstrap
    src/Presentation/    Tables, menus, html helpers
    src/Utils/           Db, Cache, Sessions, i18n, migrations, Csrf, Fv, dates

Applications keep their own conventions: a module name is its own namespace root
(`Pasta\Controllers\Quality`), resolved at runtime against whichever application served
the request. That is deliberately not PSR-4 - one repository can serve several
applications, each with its own `Modules` directory, and composer's map is static and
global.

## Getting it running

A front controller declares where its application is; everything else derives from that.

```php
<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('PUBLIC_PATH', __DIR__);

require StaticPHP\Core\Bootstrap::FILE;
```

`APP_PATH`, `APP_MODULES_PATH`, `BASE_PATH` and `VENDOR_PATH` each derive from
`PUBLIC_PATH`, and each can be defined ahead of time instead. `SP_PATH` is this package's
own directory, worked out from its own location, so it is right wherever composer put it.

## Twig is a suggestion, not a requirement

Composer's `files` autoload is eager: twig plus the symfony polyfills it pulls in load
eight files on every request whether or not a template is ever rendered. Leaving the
library out of an api-only application's dependencies is what removes that cost - lazy
class loading alone would not.

If the application renders templates, require it:

```bash
composer require twig/twig:^3.0
```

Without it the view engine is simply not built, `Load::view()` falls back to plain php
templates, and the `registerTwig()` helpers return quietly.
`$config['disable_twig'] = true` skips building the environment when the library *is*
installed.

## Tests and code style

```bash
composer install
composer test
composer lint
```

The suite is self-contained: it points `APP_PATH` at this package, so the framework stands
in for an application and no demo app has to exist.

`tests/`, `scripts/` and the tooling config are `export-ignore`d, so they are in the
repository but not in the dist tarball composer installs.

## Upgrading from 1.x

See [UPGRADE.md](UPGRADE.md). The short version: `Core\` and `System\Modules\` both become
`StaticPHP\`, `PUBLIC_PATH` is now required, and `scripts/upgrade_v2_namespaces.bash` does
the mechanical part.

## License

MIT. See [LICENSE](LICENSE).
