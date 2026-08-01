---
title: Config
description: The Config API - reading, writing, merging and loading the one configuration array.
sidebar:
    order: 6
---

`StaticPHP\Core\Models\Config` is a static wrapper around one public array. Where the
values come from, which files are loaded when, and which keys each subsystem reads are
covered in [configuration](/staticphp-core/getting-started/configuration/). This page is
the API.

```php
<?php

namespace StaticPHP\Core\Models;

class Config
{
    public static array $items = [];

    public static function &get(string $name, mixed $default = null): mixed;
    public static function set(string $name, mixed $value): void;

    public static function &getViewData(string $name, mixed $default = null): mixed;
    public static function setViewData(string|array $name, mixed $value = null): void;

    public static function merge(string $name, mixed $value, bool $owerwrite = true): mixed;
    public static function load(array $files, ?string $module = null, ?string $project = null): void;
}
```

`$items` is public, and the framework itself reads and writes it directly as often as it
goes through the accessors - `Config::$items['debug']` and `Config::get('debug')` appear
side by side in the bootstrap. There is no encapsulation to preserve here; use whichever
reads better.

## get() and set()

```php
<?php

Config::set('mail.from', 'noreply@example.com');

$from = Config::get('mail.from');
$retries = Config::get('mail.retries', 3);
```

Two things about `get()` are worth knowing before you build on it.

**It decides with `isset()`.** A key explicitly set to `null` is indistinguishable from a
key that was never set: both return `$default`. If you need to record "configured, and the
answer is nothing", use `false` or an empty array.

**It returns by reference.** The `&` in the signature means the caller receives the stored
value itself, not a copy:

```php
<?php

$routing = &Config::get('routing');
$routing['^legacy/(.*)$'] = 'site/redirects/legacy/$1';
// Config::$items['routing'] now has the new rule
```

Assigning without the `&` copies as usual, so this only bites when you go looking for it -
but it is also what lets `Load::config()` bind a config file's `$config` to the same array.

There is no dot-path support. `$config['db']['pdo']['default']` is a plain nested array,
reached as `Config::get('db')['pdo']['default']`. The dots in the example above are just
characters in a flat key.

## View data

```php
<?php

Config::setViewData('page_title', 'Invoices');
Config::setViewData(['page_title' => 'Invoices', 'nav' => 'billing']);

$title = Config::getViewData('page_title', '');
```

Both read and write `Config::$items['view_data']`, the array `Load::view()` merges into
every rendered view's data. `setViewData()` accepts either a key and a value, or an array of
key/value pairs - in the array form the second parameter is ignored. `getViewData()` has
the same `isset()` and by-reference behaviour as `get()`.

`Config::$items['view_data']` is also where `Controller::construct()` publishes the current
urls, so a controller's `construct()` is the natural place to add anything else every view
of that controller needs. See [controllers](/staticphp-core/core/controllers/).

## merge()

```php
<?php

public static function merge(string $name, mixed $value, bool $owerwrite = true): mixed;
```

Combines `$value` with what is already stored, choosing how by the type of the existing
value, and returns the result. If the key does not exist yet, the value is simply assigned.

| Existing type   | `$owerwrite = true`                   | `$owerwrite = false`                       |
| --------------- | ------------------------------------- | ------------------------------------------ |
| array           | `array_merge($existing, $value)`      | `$existing += $value`                      |
| object          | cast both to array, `array_merge`, cast back | cast both to array, `+`, cast back   |
| int or float    | `$existing += $value`                 | `$existing += $value`                      |
| anything else   | `$existing .= $value`                 | `$existing .= $value`                      |

The array row is the one that matters in practice, and the two branches differ in more than
overwriting: `array_merge()` renumbers integer keys and appends, while `+` keeps the
left-hand side's keys and only adds ones it does not already have.

```php
<?php

Config::set('url_prefixes', ['admin']);
Config::merge('url_prefixes', ['lv-en']);
// ['admin', 'lv-en']
```

The parameter really is spelled `$owerwrite`. That matters if you pass it by name.

## load()

```php
<?php

public static function load(array $files, ?string $module = null, ?string $project = null): void;
```

A thin wrapper over `Load::config()` that passes `self::$items` as the target array, which
is what makes a config file's `$config` and `Config::$items` the same array. File names
carry no `.php` extension, and the module and project resolution rules are
[`Load`'s](/staticphp-core/core/load/):

```php
<?php

Config::load(['Mail']);                      // APP_PATH/Config/Mail.php
Config::load(['Rates'], 'Billing');          // APP_MODULES_PATH/Billing/Config/Rates.php
Config::load(['Db'], 'Utils', 'staticphp');  // SP_PATH/Utils/Config/Db.php
Config::load(['Db' => 'staticphp'], 'Utils'); // the same call
```

Files are `require`d, so loading the same file twice runs it twice, and a missing file is a
fatal error rather than a return value. In normal use you do not call this at all - the
bootstrap loads `Config` and `Routing`, and `$config['autoload_configs']` covers the rest.
