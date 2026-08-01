---
title: Installation
description: Installing StaticPHP Core with Composer.
sidebar:
    order: 1
---

StaticPHP Core is the framework as a library. It is the package an application depends on,
and it is installed with composer:

```bash
composer require 4apps/staticphp-core
```

Everything it ships lives under the `StaticPHP\` namespace, resolved by PSR-4 from the
package's `src/` directory, so composer's autoloader finds it with no further setup.

## Requirements

`composer.json` requires:

-   PHP 8.4 or newer, and below 9 - the constraint is `>=8.4 <9`
-   `ext-intl`
-   `ext-mbstring`
-   `ext-pdo`

Composer refuses to install the package without them, so there is nothing to check by
hand.

## Optional dependencies

Everything else the package can use is declared under `suggest` rather than `require`, with
one entry per class that needs it. `suggest` is advisory: composer prints the list after an
install and `composer suggest` prints the same table, but it downloads nothing, so an
installation is only ever as heavy as the application asks for.

| Needed by                                      | Install                    |
| ---------------------------------------------- | -------------------------- |
| `Load::view()` with templates                  | `twig/twig`                |
| `Tables\Output\Excel`                          | `phpoffice/phpspreadsheet` |
| `Sessions\SessionsMongoDb`                     | `mongodb/mongodb`          |
| `Cache\CacheRedis`, `SessionsRedis`            | `ext-redis`                |
| `Cache\CacheMemcached`, `SessionsMemcached`    | `ext-memcached`            |
| `Cache\CacheApcu`, `SessionsApcu`              | `ext-apcu`                 |
| `Fv::translit()`, `Fv::setFriendly()`          | `ext-iconv`                |
| Postgres, incl. `SessionsPgsql` and migrations | `ext-pdo_pgsql`            |
| MySQL/MariaDB, incl. migrations                | `ext-pdo_mysql`            |
| SQLite, incl. migrations                       | `ext-pdo_sqlite`           |

A missing one is a fatal error at the point of use, not a silent fallback. Twig is the
exception, and the next section covers it. What each of the rest costs when it is absent is
on the pages that describe them:
[caching](/staticphp-core/utilities/cache/#the-four-backends),
[sessions](/staticphp-core/utilities/sessions/#the-five-backends),
[Excel output](/staticphp-core/presentation/output-excel/#the-dependency) and the
[migration drivers](/staticphp-core/database/migration-drivers/).

## Twig is a suggestion, not a requirement

Twig 3.0 or newer is listed under `suggest`, not `require`. Composer's `files` autoload is
eager: twig plus the symfony polyfills it pulls in load eight files on every request
whether or not a template is ever rendered. Leaving the library out of an api-only
application's dependencies is what removes that cost - lazy class loading alone would not.

If the application renders templates, require it explicitly:

```bash
composer require twig/twig:^3.0
```

Without twig installed the view engine is simply not built, `Load::view()` falls back to
plain php templates, and the `registerTwig()` helpers return quietly. If the library is
installed but a particular application should not use it, `$config['disable_twig'] = true`
skips building the environment.

## Relationship to gintsmurans/staticphp

This package is the library. The application skeleton - the demo app, the asset pipeline,
the dev container, the `staticphp` cli - lives in
[gintsmurans/staticphp](https://github.com/gintsmurans/staticphp) and is what
`composer create-project` gives you.

Starting a new project from that skeleton is the usual route; `composer require` is for
adding the framework to an application that already exists, or for building the
application tree by hand.

## Releases and contributing

In the repository, `master` is the latest released code, `develop` is where development
happens and where pull requests are based, and tags are releases. `CONTRIBUTING.md` covers
the docker workflow and what CI enforces; `CHANGELOG.md` is the release history.

## Next

Nothing runs yet. A front controller has to say where the application is before the
framework can boot - see
[the front controller](/staticphp-core/getting-started/front-controller/).
