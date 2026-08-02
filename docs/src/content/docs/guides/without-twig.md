---
title: Running without Twig
description: Why Twig is a suggestion rather than a requirement, what an api-only application renders instead, and what keeps the framework honest about it.
sidebar:
    order: 2
---

`4apps/staticphp-core` lists `twig/twig` under `suggest`, not `require`. An application that
renders templates asks for it; an api, a worker or a cli tool leaves it out and pays nothing
for it.

That distinction only matters because of how composer loads things, so start there.

## Eager means before any of your code runs

Composer's `files` autoload is not lazy. Every entry is `require`d by `vendor/autoload.php`
itself, at the top of the front controller, before configuration is read and before the
router has looked at the url. Classes are lazy; `files` entries are not.

Twig uses them, and so do the symfony polyfills it depends on. In an application that
requires `4apps/staticphp-core` and `twig/twig` and nothing else, this is the whole list:

```text
symfony/deprecation-contracts/function.php
symfony/polyfill-ctype/bootstrap.php
symfony/polyfill-mbstring/bootstrap.php
twig/twig/src/Resources/core.php
twig/twig/src/Resources/debug.php
twig/twig/src/Resources/escaper.php
twig/twig/src/Resources/string_loader.php
```

Seven entries. What that turns into at runtime is nine files, because two of the polyfill
bootstraps include a PHP 8 variant of their own:

```text
vendor/autoload.php
vendor/composer/autoload_real.php
vendor/composer/platform_check.php
vendor/composer/ClassLoader.php
vendor/composer/autoload_static.php
vendor/symfony/deprecation-contracts/function.php
vendor/symfony/polyfill-ctype/bootstrap.php
vendor/symfony/polyfill-ctype/bootstrap80.php
vendor/symfony/polyfill-mbstring/bootstrap.php
vendor/symfony/polyfill-mbstring/bootstrap80.php
vendor/twig/twig/src/Resources/core.php
vendor/twig/twig/src/Resources/debug.php
vendor/twig/twig/src/Resources/escaper.php
vendor/twig/twig/src/Resources/string_loader.php
```

Five of those fourteen are composer's own and are there regardless. The other nine arrive
with twig, on every request, whether or not a template is ever rendered. Lazy class loading
does not help: none of this is a class being loaded.

The measurement above is twig 3.28.0. The package's own `suggest` text says eight files;
that number is out of date. The seven break down as four `files` entries in twig itself and
one each in the three packages it requires, so the figure only moves if twig repackages
itself. Where the cost is paid does not move at all.

## An api-only install

Leave twig out and there is no `files` list at all - composer does not generate the file
when nothing declares one. A controller that returns an array gets
`Content-Type:application/json; charset=utf-8` and `json_encode()` from the router, so an
api needs no view layer whatsoever:

```text
$ ls vendor/composer/autoload_files.php
ls: cannot access 'vendor/composer/autoload_files.php': No such file or directory

$ php src/Application/Public/index.php /api/status/index
{"status":"ok","twig_installed":false,"view_engine":null}
```

`view_engine` is never set, which is what every twig-aware part of the framework checks
before it does anything.

## What renders instead

Nothing breaks if such an application does want html.
[`Load::view()`](/staticphp-core/core/load/#without-twig) renders plain php templates when
`Config::viewEngine()` returns `null`. This is a first-class path rather than an error
path - for an api-only install it is the only view layer there is - and it does what a view
layer is expected to do:

- `$data` is extracted into the template with `extract($data, EXTR_SKIP)`, inside a closure
  of its own, so a data key called `files` or `path` cannot overwrite the renderer's
  variables.
- `$return = true` returns the rendered string; otherwise it is echoed.
- `$config` and `$env` are provided the way twig provides them, through the same credential
  stripping - `safeConfigForViews()` and the `$config['view_env_keys']` allowlist.

Each name is resolved under `APP_MODULES_PATH`, checked with `Router::pathIsWithin()`, and
buffered, so a view that throws discards the buffer instead of printing half a page.

The four classes that publish twig helpers - `Csrf`, `Fv`, `BitwiseFlag` and
`Presentation\Models\Menu\Menu` - each begin `registerTwig()` by reading `view_engine` and
returning when it is empty. Calling them is safe either way, so shared bootstrap code does
not need to know which kind of application it is running in.

## `disable_twig` is a different switch

```php
<?php

$config['disable_twig'] = true;
```

This skips *building* the environment when the library is installed. The bootstrap's guard
is `Config::get('disable_twig') !== true && class_exists(\Twig\Environment::class)`, so
either half of it lands on the same plain-php view layer.

What it cannot do is unload anything. `vendor/autoload.php` has already run by the time the
configuration is read, so the seven `files` entries are in memory whatever this flag says.
Removing the dependency is the only thing that removes the cost.

## What keeps it true

The claim is easy to break by accident: one reference to a Twig class outside a guard and
an api-only install fatals on a request that renders nothing. The test suite cannot catch
it, because it installs twig as a dev dependency.

So the package proves it separately. `scripts/boot_without_twig.php` builds a throwaway
application in a temp directory, serves one request through the real bootstrap, and checks
the response:

```text
$ composer install --no-dev
$ php scripts/boot_without_twig.php
ok: framework booted and served a request with twig absent
```

It refuses to run and exits 2 if twig is present, calls `registerTwig()` on all four
classes, asserts that `view_engine` was not built, and renders a plain php view with data
in it. CI runs it as a job of its own, `no-twig`, on a `composer install --no-dev` install -
separate from the test matrix precisely because the matrix has twig installed.

The script is in the repository, not in the package: `.gitattributes` marks `scripts/` as
`export-ignore`, so it is absent from the dist archive `composer require` fetches.

## If you do render templates

Require it explicitly. Nothing installs it for you:

```bash
composer require twig/twig:^3.0
```

The bootstrap then builds a `\Twig\Loader\FilesystemLoader` over `APP_MODULES_PATH`,
`APP_PATH` and `SP_PATH/Core/Views`, registers the framework's filters and functions on the
environment, and `Load::view()` renders through it. That is all in
[the twig environment](/staticphp-core/core/bootstrap/#the-twig-environment).

The same reasoning covers every other optional dependency the package declares - see
[optional dependencies](/staticphp-core/getting-started/installation/#optional-dependencies).
Twig is the only one the framework degrades around; the rest are a fatal error at the point
of use, which is the right answer for a cache backend nobody installed.
