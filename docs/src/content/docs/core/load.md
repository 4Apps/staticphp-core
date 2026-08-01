---
title: Load
description: Resolving config, controller, model and helper files, rendering views, and the file helpers.
sidebar:
    order: 5
---

`StaticPHP\Core\Models\Load` does two unrelated jobs that happen to share a class: it
resolves and requires application files by name, and it renders views. A handful of file
name helpers sit alongside them.

```php
<?php

namespace StaticPHP\Core\Models;

class Load
{
    public const FRAMEWORK_PATH = 'staticphp';

    public static function config(array $files, ?string $module = null, ?string $project = null, ?array &$config = null): void;
    public static function controller(array $files, ?string $module = null, ?string $project = null): void;
    public static function model(array $files, ?string $module = null, ?string $project = null): void;
    public static function helper(array $files, ?string $module = null, ?string $project = null): void;

    public static function view(array $files, array &$data = [], bool $return = false): string|bool;

    public static function uuid4(): string;
    public static function randomHash(): string;
    public static function hashedPath(string $filename, bool $randomize = false, bool $createDirectories = false, int $levelsDeep = 2, int $directoryNameLength = 2): array;
    public static function deleteHashedFile(string $filename): void;
}
```

## Loading files

The four loaders differ only in the directory they look in - `Config`, `Controllers`,
`Models` and `Helpers` respectively. Each takes a list of names without the `.php`
extension and `require`s every one of them, so a missing file is a fatal error rather than
a return value.

```php
<?php

// APP_PATH/Helpers/Format.php
Load::helper(['Format']);

// APP_MODULES_PATH/Billing/Models/Invoice.php
Load::model(['Invoice'], 'Billing');

// SP_PATH/Core/Helpers/ErrorHandlers.php - what the bootstrap itself calls
Load::helper(['ErrorHandlers'], 'Core', 'staticphp');
```

### How a name resolves to a path

`resolve()` is private, but its rules are the contract for all four:

| `$project`   | `$module` | Resolves to                                        |
| ------------ | --------- | -------------------------------------------------- |
| none         | none      | `APP_PATH/{type}/{name}.php`                       |
| none         | given     | `APP_MODULES_PATH/{module}/{type}/{name}.php`      |
| `staticphp`  | given     | `SP_PATH/{module}/{type}/{name}.php`               |
| other        | given     | `{module_paths[project]}/{module}/{type}/{name}.php` |
| given        | none      | `InvalidArgumentException`                          |

`Load::FRAMEWORK_PATH` is the constant behind that `staticphp` literal. It is backed by
`SP_PATH` directly rather than by an entry in `$config['module_paths']`, so an application
that assigns `module_paths` wholesale cannot accidentally wipe the framework's own path out
from under the bootstrap.

Any other project name must be a key of `$config['module_paths']`, which maps a name to a
directory *holding modules*. An unknown name is an `InvalidArgumentException`, and so is
naming a project without a module - a module path points at a directory of modules, so it
means nothing on its own.

### Per-file projects

Every loader walks `$files` as `$key => $name` and checks whether the key is numeric. If it
is not, the key is the file name and the value is the project:

```php
<?php

Load::config(['Db' => 'staticphp'], 'Utils');
// identical to
Load::config(['Db'], 'Utils', 'staticphp');
```

Mixed lists work, since the check is per element - `['Format', 'Db' => 'staticphp']` loads
the first from the application and the second from the framework.

### config() and the shared array

```php
<?php

public static function config(array $files, ?string $module = null, ?string $project = null, ?array &$config = null): void;
```

The fourth parameter is what makes a config file able to write `$config['key'] = ...` and
have it land in `Config::$items`. When `$config` is null it is bound by reference to
`Config::$items`; when it is given, `Config::$items` is rebound to it instead. Either way
the local `$config` visible inside the required file and the static array are the same
array. [`Config::load()`](/staticphp-core/core/config/) is the wrapper you normally call.

## Rendering views

```php
<?php

public static function view(array $files, array &$data = [], bool $return = false): string|bool;
```

Returns the rendered string when `$return` is true, otherwise echoes it and returns `true`.
`$data` is declared by reference, so a call has to pass a variable rather than an array
literal - `Controller::render()` takes its data by value and is the friendlier entry point.

`Config::$items['view_data']` is merged in first, with `$data + $view_data`, so keys the
caller passed win over the global ones. Because `$data` is a reference, that merge is
visible to the caller afterwards.

What happens next depends on whether twig is installed and enabled.

[`Menu`](/staticphp-core/presentation/menus/) is the one part of the package that renders
through `view()` rather than returning markup of its own: it resolves its item list and
hands the result to a template the application supplies.

### With twig

Each name in `$files` is passed to `$config['view_engine']->render()` and the results are
concatenated, so the names are twig template names resolved by the loader against
`APP_MODULES_PATH`, `APP_PATH` and `SP_PATH/Core/Views` in that order.

Eleven globals are added to the environment, once per request, guarded by a static flag:

| Global      | Value                       |
| ----------- | --------------------------- |
| `env`       | `safeEnvForViews()`         |
| `now`       | `Config::$items['now']`     |
| `date_time` | `Config::$items['date_time']` |
| `config`    | `safeConfigForViews()`      |
| `session`   | `$_SESSION ?? []`           |
| `cookie`    | `$_COOKIE ?? []`            |
| `base_url`  | `Router::$base_url`         |
| `namespace` | `Router::$namespace`        |
| `class`     | `Router::$class`            |
| `method`    | `Router::$method`           |
| `segments`  | `Router::$segments`         |

They are captured on the first `view()` call of the request, so a later change to
`$_SESSION` or to the router's state is not reflected in them.

### Without twig

When `Config::$items['view_engine']` is empty - twig not installed, or
`$config['disable_twig'] = true` - views are plain php. This is a first-class path, not a
fallback for error cases: an api-only application that never installs twig renders
everything this way.

Each name is resolved as `APP_MODULES_PATH . "/{$file}"`, checked with
`Router::pathIsWithin()` and `require`d inside a closure with `extract($data, EXTR_SKIP)`,
with output buffering around the lot. A view outside the modules directory is a
`RuntimeException`. If any view throws, the buffer is discarded and the exception
re-thrown, so a half-rendered page is never printed.

Note that the file name here includes the extension and is a path, where the twig name is a
template name - `Controller::render(['home.php'])` produces `Site/Views/home.php`, which
works as a path under `APP_MODULES_PATH` and as a template name for the twig loader alike.

Two variables are always injected, overwriting any key of the same name in `$data`:
`$config` from `safeConfigForViews()` and `$env` from `safeEnvForViews()` - the same two
the twig globals use, provided the same way twig provides them.

### What views are not allowed to see

`safeConfigForViews()` walks `Config::$items` recursively, to a depth of 16, and drops:

- any key matching
  `/(pass|passwd|pwd|secret|token|api_?key|credential|salt|dsn|private)/i`;
- the keys `view_engine`, `view_loader` and `db`, which are objects reaching back into
  everything else.

`safeEnvForViews()` is an allowlist rather than a filter: only the keys named in
`$config['view_env_keys']` are copied out of `$_ENV`. With `symfony/dotenv` loading a `.env`
file, exposing `$_ENV` wholesale would put the database password in every template.

## File name helpers

```php
<?php

public static function uuid4(): string;
public static function randomHash(): string;
```

`uuid4()` builds an RFC 4122 version 4 uuid from `random_bytes(16)`. `randomHash()` is
`bin2hex(random_bytes(20))` - 40 hex characters. Both use `random_bytes()` rather than
`mt_rand()`, because the Mersenne Twister's state can be recovered from a modest amount of
observed output, which would make every subsequent filename predictable.

```php
<?php

public static function hashedPath(
    string $filename,
    bool $randomize = false,
    bool $createDirectories = false,
    int $levelsDeep = 2,
    int $directoryNameLength = 2
): array;
```

Spreads uploads across subdirectories so no single directory collects every file. The
subdirectory names are taken from the *end* of the file name, `$directoryNameLength`
characters at a time, one level per `$levelsDeep`.

```php
<?php

$path = Load::hashedPath('/www/upload/files/image.jpg', false, true);

// [
//     'hash_dir'  => 'ge/ma/',
//     'hash_file' => 'ge/ma/image.jpg',
//     'filename'  => 'image',
//     'ext'       => 'jpg',
//     'dir'       => '/www/upload/files/ge/ma/',
//     'file'      => '/www/upload/files/ge/ma/image.jpg',
// ]
```

With `$randomize` true the file name is replaced by `randomHash()`, which both anonymises
it and distributes the directories evenly - worth doing whenever the original name does not
matter. `$createDirectories` runs `mkdir($dir, 0770, true)` when the directory is missing.
A file name shorter than `$levelsDeep * $directoryNameLength` throws a plain `\Exception`.

`deleteHashedFile()` recomputes the path with `hashedPath()`, unlinks the file and then
walks the hashed directories upwards removing each one, stopping at the first `rmdir()`
that fails - which is how it leaves directories still holding other files alone.

It calls `hashedPath()` with the defaults, so it only finds a path built with two levels of
two characters. It also takes the *stored* file name rather than the original one: after a
randomised write, the name to keep and to pass back here is the `filename` the call
returned, not what was uploaded.
