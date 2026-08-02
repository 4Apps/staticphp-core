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

    public static function getString(string $name, string $default = ''): string;
    public static function getInt(string $name, int $default = 0): int;
    public static function getBool(string $name, bool $default = false): bool;
    public static function getArray(string $name): array;

    public static function &getViewData(string $name, mixed $default = null): mixed;
    public static function setViewData(string|array $name, mixed $value = null): void;

    public static function merge(string $name, mixed $value, bool $owerwrite = true): mixed;
    public static function load(array $files, ?string $module = null, ?string $project = null): void;

    public static function resolveDebug(): bool;
    public static function viewEngine(): ?\Twig\Environment;
}
```

`$items` is public, and the framework itself reads and writes it directly as often as it
goes through the accessors - the bootstrap writes `Config::$items['debug']` directly and
reads `Config::get('debug')` a few lines later. There is no encapsulation to preserve here;
use whichever reads better.

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

## The typed accessors

```php
<?php

public static function getString(string $name, string $default = ''): string;
public static function getInt(string $name, int $default = 0): int;
public static function getBool(string $name, bool $default = false): bool;
public static function getArray(string $name): array;
```

`Config::$items` is an untyped bag by design - applications put whatever they like in it -
and these are where a setting stops being a `mixed`. **A value of the wrong type is treated
as absent rather than coerced**, so a `$config['debug']` of `'yes'` is `false` through
`getBool()`, not `true`. Silently turning an array into `"Array"` has never been what the
caller wanted.

`getInt()` is the one exception: a numeric string is accepted and cast, because ports and
counts arrive from the environment as strings. `getArray()` takes no default and returns
`[]`.

The framework reads its own settings through these. `getString('base_url')`,
`getBool('trust_proxy_headers')` and `getInt('trusted_proxy_hops', 1)` are all in
`Router`; use them for your own keys or use `get()` and check yourself.

## resolveDebug()

```php
<?php

public static function resolveDebug(): bool;
```

The bootstrap calls this once and writes the answer to `Config::$items['debug']`. Debug is
not just the timing panel - it is `display_errors`, full exception traces, twig's debug
mode and the query log - so who may see it is a question only the application can answer,
and the framework asks rather than deciding.

1. `$config['debug']` truthy - checked with `!empty()` - returns `true` immediately.
2. Otherwise `$config['debug_check']` is consulted. Anything that is not `is_callable()` is
   ignored and the answer is `false`.
3. The callable is invoked and its result compared with `=== true`. A check that
   accidentally returns a string or a row count does not open the gate.
4. Anything it throws is caught, passed to `error_log()` as
   `debug_check failed, debug stays off: <message>`, and treated as `false`. A gate that
   fails is a gate that says no: letting the exception through would take down the request,
   and treating it as a yes would turn a bug in the application's own check into a query
   log on a production page.

```php
<?php

$config['debug_check'] = function (): bool {
    $secret = $_ENV['DEBUG_SECRET'] ?? getenv('DEBUG_SECRET') ?: '';
    $token = $_COOKIE['sp_debug'] ?? '';
    if ($token === '' || $secret === '') {
        return false;
    }

    return hash_equals(hash_hmac('sha256', 'debug', $secret), $token);
};
```

It runs during bootstrap, immediately after `Config::load(['Config', 'Routing'])` and before
the extra config files, the error handlers, the view engine and the router - so **sessions,
the database and routing do not exist yet**. Query logging has to be armed before the first
query runs, which is why it sits there. It can read `$_SERVER`, `$_COOKIE` and
configuration, and nothing else; a check reaching for `$_SESSION` will not find it. A signed
cookie is the natural fit: real authentication, no session needed, works from any network.

:::caution[`$config['debug_ips']` is gone and is no longer read]
It compared `$config['client_ip']` against a list, and `client_ip` comes from the request -
so behind a proxy that appends to `X-Forwarded-For` a client could put a listed address in
the header and turn debug on for itself. An application still carrying the key gets nothing
from it, silently. Migrating it is
[step 5 of upgrading](/staticphp-core/guides/upgrading/#5-debug_ips-is-gone-the-application-decides-who-sees-debug-output).
:::

## viewEngine()

```php
<?php

public static function viewEngine(): ?\Twig\Environment;
```

Returns `Config::$items['view_engine']` when it is a `\Twig\Environment`, and `null`
otherwise - including when twig is not installed at all. Not a generalisation of "some
engine": every consumer registers twig filters and functions against it, and an application
that leaves twig out has none. See
[running without Twig](/staticphp-core/guides/without-twig/).

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
