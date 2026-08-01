---
title: Multiple applications
description: Why a module name is its own namespace root, and how one repository serves several applications from a single framework install.
sidebar:
    order: 1
---

Everything the package ships lives under `StaticPHP\` and is resolved by PSR-4. Application
code is not. A module name is its own namespace root - `Pasta\Controllers\Quality` - and it
means whatever the application that served the request says it means.

That is the framework's least obvious decision. This page is the reason for it, and what it
costs.

## One repository, several applications

The layout this exists for:

```text
project/
├── composer.json
├── vendor/
│   └── 4apps/staticphp-core/
└── src/
    ├── Application/                          the staff intranet
    │   ├── Config/
    │   │   ├── Config.php
    │   │   └── Routing.php
    │   ├── Modules/
    │   │   └── Pasta/
    │   │       ├── Controllers/Quality.php   Pasta\Controllers\Quality
    │   │       └── Models/Batch.php          Pasta\Models\Batch
    │   └── Public/index.php
    ├── Portal/                               the customer portal
    │   ├── Config/
    │   ├── Modules/
    │   │   └── Pasta/
    │   │       ├── Controllers/Quality.php   Pasta\Controllers\Quality again
    │   │       └── Models/Batch.php          Pasta\Models\Batch again
    │   └── Public/index.php
    └── Shared/
        └── Modules/
            └── Billing/Models/Invoice.php    Billing\Models\Invoice
```

Two applications, one composer install, one `vendor/`. Both have a `Pasta` module, because
both are about pasta: the intranet books quality checks against a batch, the portal shows a
customer what their batch tested at. Different code, different data, same obvious name for
it.

The two front controllers are the same three lines and differ only in where they sit:

```php
<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('PUBLIC_PATH', __DIR__);

require StaticPHP\Core\Bootstrap::FILE;
```

Every path constant derives from `PUBLIC_PATH`, so that one `define()` is the whole of
choosing an application:

| Constant           | Served from `src/Application/Public` | Served from `src/Portal/Public` |
| ------------------ | ------------------------------------ | ------------------------------- |
| `PUBLIC_PATH`      | `project/src/Application/Public`     | `project/src/Portal/Public`     |
| `APP_PATH`         | `project/src/Application`            | `project/src/Portal`            |
| `APP_MODULES_PATH` | `project/src/Application/Modules`    | `project/src/Portal/Modules`    |
| `BASE_PATH`        | `project/src`                        | `project/src`                   |

`SP_PATH` and `VENDOR_PATH` come out the same for both - they describe the install, not the
application. The full derivation is in
[the front controller](/staticphp-core/getting-started/front-controller/#the-path-constants).

## What composer cannot say

PSR-4 is a map from a namespace prefix to a directory, written into `composer.json` and
dumped once at install time. It is static, and it is global to the process.

The layout above needs `Pasta\` to mean `src/Application/Modules/Pasta` in one request and
`src/Portal/Modules/Pasta` in the next, in the same install, with no third party knowing
which. There is nowhere to write that down: a prefix gets one directory list, and the
loader has no idea which front controller is running.

So the framework registers a second autoloader of its own, in
`src/Core/Helpers/Autoload.php`, and it is a *callback* rather than a map - it answers the
question at the moment the class is asked for, when `APP_MODULES_PATH` is already known.

## The same class name, two files

Both applications above define `Pasta\Models\Batch`. Each front controller was asked for
the same url, and each got its own:

```text
$ php src/Application/Public/index.php /pasta/quality/index
APP_MODULES_PATH    src/Application/Modules
Pasta\Models\Batch  src/Application/Modules/Pasta/Models/Batch.php
Batch::label()      staff intranet

$ php src/Portal/Public/index.php /pasta/quality/index
APP_MODULES_PATH    src/Portal/Modules
Pasta\Models\Batch  src/Portal/Modules/Pasta/Models/Batch.php
Batch::label()      customer portal
```

Nothing distinguishes the two runs except which `Public/index.php` was executed. The class
name is identical, and it resolves to a different file because `APP_MODULES_PATH` is
different.

## How the callback resolves a name

`Autoload.php` registers one `spl_autoload_register()` callback, and it does four things:

1. **Rejects anything that is not an identifier.** The class name is split on `\`, and
   every component must match `/^[a-zA-Z_][a-zA-Z0-9_]*$/`. Class names reach this point
   from url segments by way of the router, so a name containing `..` would otherwise become
   an include of an arbitrary file. A component that fails the test makes the callback
   return without touching the filesystem.
2. **Tries two roots, in order:** `APP_MODULES_PATH`, then `APP_PATH`. The candidate is
   `<root>/<class path>.php`, so `Pasta\Models\Batch` is
   `APP_MODULES_PATH/Pasta/Models/Batch.php` and a class at the application root, like
   `Models\AppConfig`, is `APP_PATH/Models/AppConfig.php`.
3. **Confirms the resolved file is really under the root.** `realpath()` on both, then
   `str_starts_with()`, so a symlink inside the tree that points somewhere else does not
   get included.
4. **Includes it and returns.** No exception, no error - a name it cannot resolve is left
   for whoever comes next, which is what an autoloader is supposed to do.

It is registered without `$prepend`, so it runs *after* composer's. Framework and vendor
classes are resolved by composer long before the callback is reached, and `StaticPHP\`
never touches it.

The two roots used to be five. The chain ended with the framework's own directories and
`BASE_PATH` so that an application could shadow a framework class by filename; nothing used
it, and every extra root cost a failed `is_file()` on every miss. A failed stat is roughly
50 times the cost of a successful one, because PHP caches resolved paths and never caches
failures.

## Sharing a module between applications

The autoloader only ever looks inside the current application. Code that two applications
share is reached deliberately, through
[`Load`](/staticphp-core/core/load/#how-a-name-resolves-to-a-path), by naming a registered
module path:

```php
<?php

$config['module_paths'] = [
    'shared' => BASE_PATH . '/Shared/Modules',
];
```

The value is the directory that *holds* modules, not its parent, which is why the entry
above ends in `/Modules`. `BASE_PATH` is the directory holding the applications - the same
value whichever one is serving - so it is the natural place to hang a shared tree.

`staticphp` is reserved, resolves to the framework's own modules, and cannot be
overridden or registered. Everything else is a key of `$config['module_paths']`:

```php
<?php

Load::model(['Invoice'], 'Billing', 'shared');
Load::config(['Db'], 'Utils', 'staticphp');
```

The three ways that can go, in one request:

```text
$ php src/Portal/Public/index.php /pasta/quality/shared
autoloaded          Error: Class "Billing\Models\Invoice" not found
Load::model()       src/Shared/Modules/Billing/Models/Invoice.php
unregistered name   InvalidArgumentException: Unknown module path "archive". Add it to $config['module_paths'].
```

Which is the summary of the whole design: the shared tree is invisible to the autoloader,
`Load` reaches it because the configuration named it, and a name that was never registered
is an `InvalidArgumentException` rather than a path that quietly does not exist.

Keep module names distinct across the trees you share. The loaders use `require` rather
than `require_once`, and two modules called `Billing` in two registered paths are two
different files with the same class name in them.

## The cli picks an application the same way

`staticphp migrate` and `staticphp i18n` take a `--project=NAME` option, defaulting to
`Application`. Both resolve it as `<repository root>/src/<NAME>/Public`, check that the
directory exists, and define `PUBLIC_PATH` as that before requiring `Bootstrap::AUTOLOAD` -
the same first move a front controller makes, so the rest of the derivation follows:

```bash
staticphp migrate status --project=Portal
```

A name with no `Public` directory exits 2 with `error: project "Portal" not found at ...`.
That is also why the applications sit in `src/<Name>/` with a `Public/` directory: the
front controller can live anywhere, but the cli looks there. See
[where the settings come from](/staticphp-core/database/migrations-cli/#where-the-settings-come-from).

Each application has its own `Config/` directory, so each has its own database connections,
its own routing and its own migrations directory.

## Why not just optimise composer's classmap

Because the map is global, and a global map has one answer per class name.

Adding the application tree to `composer.json` and running `composer dump-autoload -o` on
the layout above produces exactly one entry for `Pasta\Models\Batch`. One of the two files
wins, the dump says nothing about the other, and composer's loader runs before the
framework's:

```text
$ composer dump-autoload -o
Generating optimized autoload files
Generated optimized autoload files containing 363 classes

$ php src/Application/Public/index.php /pasta/quality/index
APP_MODULES_PATH    src/Application/Modules
Pasta\Models\Batch  src/Portal/Modules/Pasta/Models/Batch.php
Batch::label()      customer portal
```

The staff intranet, serving its own url, out of its own `Modules` directory, was handed the
customer portal's model. Nothing failed and nothing warned.

`composer dump-autoload -o` over `vendor/` and the package's own `StaticPHP\` prefix is
fine. It is the application tree that has to stay out of composer's map when one repository
serves several applications.

## What it costs

- **No classmap optimisation for application classes.** Every application class costs a
  stat, and a class under `APP_PATH` rather than `APP_MODULES_PATH` costs two.
- **No editor or static analyser knows the mapping** from `composer.json` alone. Tools that
  read PSR-4 will not find `Pasta\Controllers\Quality`.
- **Class names are only unique within an application.** `Pasta\Models\Batch` is one class
  in this request and a different one in the next. Anything that has to hold both at once -
  a global classmap, an index over the whole repository - has to pick one.

If you only ever serve one application from a repository, none of this is visible: the
single application is `src/Application`, its modules are under
`src/Application/Modules`, and the callback finds them there. The design costs that
installation nothing and buys the other one the ability to exist at all.
