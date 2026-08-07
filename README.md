[![packagist](http://img.shields.io/badge/packagist-4apps%2Fstaticphp--core-brightgreen.svg)](https://packagist.org/packages/4apps/staticphp-core)

# StaticPHP Core

The StaticPHP framework, as a library.

Full documentation: https://4apps.github.io/staticphp-core/

This is the package an application depends on. The application skeleton - the demo app,
the asset pipeline, the dev container, the `staticphp` cli - lives in
[4Apps/staticphp](https://github.com/4Apps/staticphp) and is what
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

## Optional dependencies

The package itself requires only php, `ext-intl`, `ext-mbstring` and `ext-pdo`. Everything
below is needed by one class each, and is declared in composer's `suggest` rather than
`require` - nothing here is downloaded unless you ask for it. Install only what the
application actually uses; `composer suggest` lists the same table.

| Needed by                        | Install                          |
| -------------------------------- | -------------------------------- |
| `Load::view()` with templates    | `twig/twig`                      |
| `Tables\Output\Excel`            | `phpoffice/phpspreadsheet`       |
| `Sessions\SessionsMongoDb`       | `mongodb/mongodb`                |
| `Cache\CacheRedis`, `SessionsRedis`         | `ext-redis`           |
| `Cache\CacheMemcached`, `SessionsMemcached` | `ext-memcached`       |
| `Cache\CacheApcu`, `SessionsApcu`           | `ext-apcu`            |
| `Fv::translit()`, `Fv::setFriendly()`       | `ext-iconv`           |
| Postgres, incl. `SessionsPgsql` and migrations | `ext-pdo_pgsql`    |
| MySQL/MariaDB, incl. migrations  | `ext-pdo_mysql`                  |
| SQLite, incl. migrations         | `ext-pdo_sqlite`                 |

A missing one is a fatal error at the point of use, not a silent fallback - the exception
is twig, which the view layer degrades around deliberately.

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

## Contributing

Pull requests are welcome. `master` is the latest released code, `develop` is where active
development happens and where pull requests are based, and tags are releases.
[CONTRIBUTING.md](CONTRIBUTING.md) covers the docker workflow, what CI enforces and what a
pull request needs; [CHANGELOG.md](CHANGELOG.md) is the release history.

## Upgrading from 1.x

See [UPGRADE.md](UPGRADE.md). The short version: `Core\` and `System\Modules\` both become
`StaticPHP\`, `PUBLIC_PATH` is now required, and `scripts/upgrade_v2_namespaces.bash` does
the mechanical part.

## License

MIT. See [LICENSE](LICENSE).
