---
title: Configuration
description: How Config is populated and read, and the configuration each subsystem expects.
sidebar:
    order: 3
---

Configuration is one flat array of values, `StaticPHP\Core\Models\Config::$items`, filled
by plain php files that assign into `$config`.

## Where the values come from

The bootstrap loads two files from the application before anything else happens:

```php
<?php

Config::load(['Config', 'Routing']);
```

With no module or project named, that resolves to `APP_PATH/Config/Config.php` and
`APP_PATH/Config/Routing.php`. Both are ordinary php files. `Load::config()` binds
`Config::$items` and the local `$config` to the same array, so assigning to `$config`
inside a config file is assigning to `Config::$items`.

A minimal `Config/Config.php` that boots and serves a request:

```php
<?php

$config['base_url'] = 'http://localhost/';
$config['disable_twig'] = false;
$config['environment'] = 'prod';
$config['debug'] = false;
$config['debug_check'] = null;
$config['allowed_hosts'] = [];
$config['trust_proxy_headers'] = false;
$config['trusted_proxy_hops'] = 1;
$config['client_ip'] = null;
$config['view_env_keys'] = [];
$config['url_prefixes'] = [];
$config['module_paths'] = [];
$config['autoload_configs'] = [];
$config['autoload_helpers'] = [];
$config['before_controller'] = [];
$config['error_pages'] = ['status' => null, 'debug' => null];
$config['request_uri'] = & $_SERVER['REQUEST_URI'];
$config['query_string'] = & $_SERVER['QUERY_STRING'];
$config['script_name'] = & $_SERVER['SCRIPT_NAME'];
```

The last three are bound by reference on purpose: the cli entry points rewrite `$_SERVER`
to describe the url they were asked to serve, and the configuration has to follow.

`client_ip` used to be bound the same way. Leave it `null` instead - the bootstrap fills it
from `Router::clientIp()`, which is the only thing that knows about proxies. Setting it
explicitly still works and still wins, but on a proxied deployment a binding to
`REMOTE_ADDR` pins every request to the proxy. See
[proxy headers](/staticphp-core/core/request/#proxy-headers).

`Routing.php` is covered in [your first route](/staticphp-core/getting-started/first-route/).

## Reading and writing

Everything on `Config` is static, and `Config::$items` is public. `get()`, `set()`,
`getViewData()`, `setViewData()`, `merge()` and `load()` are the core of it, alongside
`resolveDebug()`, `viewEngine()` and the four typed accessors `getString()`, `getInt()`,
`getBool()` and `getArray()`. The behaviour that catches people out - `get()` deciding with
`isset()` and returning by reference, `merge()` branching on the stored value's type, the
`$owerwrite` spelling, and the absence of any dot-path support - is on
[the Config API](/staticphp-core/core/config/).

## Loading more config files

`Config::load()` takes file names without the `.php` extension, and optionally a module and
a project:

```php
<?php

// APP_PATH/Config/Mail.php
Config::load(['Mail']);

// APP_MODULES_PATH/Billing/Config/Rates.php
Config::load(['Rates'], 'Billing');

// SP_PATH/Utils/Config/Db.php - "staticphp" is the reserved name for the package itself
Config::load(['Db'], 'Utils', 'staticphp');
```

`Config::load()` is a thin wrapper over `Load::config()`, so the rules for resolving a
module and a project to a directory - including what any name other than `staticphp` has to
be, and the per-file `['Db' => 'staticphp']` form - are
[`Load`'s](/staticphp-core/core/load/#how-a-name-resolves-to-a-path).

Instead of calling `load()` by hand, list the files in `$config['autoload_configs']` and
the bootstrap loads them for you, after `debug` has been worked out and before the error
handlers are registered. Entries are slash separated and read right to left:

| Entry                  | Loads                                     |
| ---------------------- | ----------------------------------------- |
| `Mail`                 | `APP_PATH/Config/Mail.php`                |
| `Billing/Rates`        | `APP_MODULES_PATH/Billing/Config/Rates.php` |
| `staticphp/Utils/Db`   | `SP_PATH/Utils/Config/Db.php`             |

## The configuration each subsystem expects

Six subsystems come with a default configuration file in `src/Utils/Config/`. They are
ordinary config files, not classes: each one assigns the keys its subsystem reads, with
values that make sense as a starting point. Load one with
`$config['autoload_configs'][] = 'staticphp/Utils/<name>'`, or copy it into the
application's own `Config/` directory and edit it there.

### Db

Populates `$config['db']['pdo']`, keyed by connection name, with `default` as the shipped
entry. Each entry holds `string` - the PDO connection string - along with `username`,
`password`, `charset`,
`persistent`, `wrap_column`, `fetch_mode_objects`, `emulate_prepares` and `debug`. The
shipped file sets `debug` from `$config['debug']`, which is why it has to be loaded after
the application's own `Config.php` - the bootstrap's ordering already guarantees that.
See [the database layer](/staticphp-core/database/db/).

### Cache

Populates `$config['cache']` with one section per backend: `redis`, `memcached`, `apcu` and
`files`. Every section takes a `prefix`; the rest is backend specific - hostname, port,
database and timeout for redis, a server list for memcached, and a path, extension and
directory nesting for the file backend. See [caching](/staticphp-core/utilities/cache/).

### i18n

Populates `$config['i18n']`: the countries and languages the site serves, the url format
that identifies them, and the switches for redirecting, negotiating, auto-registering
missing keys, falling back, caching warmed language tables and naming the database tables.
It also derives the language prefixes from the country list and prepends them to
`$config['url_prefixes']`, so the router recognises `/lv-en/...` as a prefix rather than as
the first controller segment. See [internationalisation](/staticphp-core/i18n/overview/).

### Migrations

Populates `$config['migrations']` with three keys: `dir`, the directory the `.sql` files
live in, kept outside `Public/` deliberately; `table`, the tracking table; and
`connection`, naming which entry of `$config['db']['pdo']` to migrate.

### Audit

Populates `$config['audit']`: `connection` and `table`, saying which entry of
`$config['db']['pdo']` the trail is written to and where the rows land; `strict` and
`max_rows`, the two safety switches; `id_key`, the column read from the affected row;
`actor` and `context`, the callables an application supplies to name who did it and where
from; and `exclude`, the columns whose values must never reach the trail. The same list is
also a constant inside `Audit`, so the trail works before this file is loaded at all - load
it when you want to change something. See
[the audit trail](/staticphp-core/audit/overview/).

### Queue

Populates `$config['queue']`: `driver`, `database` or `redis`; `connection`, which entry of
`$config['db']['pdo']` jobs are written to on the database driver; `table` and
`failed_table`; the `redis` section, only read on the redis driver; `queue`, the name a push
lands on when it does not name one; `tries` and `backoff`, how many attempts a job gets and
how long it waits between them; `timeout`, how long a worker's claim on a job lasts; `sleep`,
how long a worker waits before looking again when there is nothing to do; and `handlers`,
for job names that are not class names or that have since moved. See
[the queue overview](/staticphp-core/queue/overview/).

## Keys the framework reads

Beyond the subsystem sections above, these are the keys the package itself looks at:

| Key                                          | Read by                                                     |
| -------------------------------------------- | ----------------------------------------------------------- |
| `base_url`, `request_uri`, `script_name`      | `Router::splitSegments()`, to work out the url and segments |
| `routing`                                     | `Router`, for the rewrite rules and the default route       |
| `url_prefixes`                                | `Router::splitSegments()`, stripped off before routing      |
| `allowed_hosts`                               | `Router::validateHost()`; empty means syntax check only     |
| `debug`, `debug_check`                        | the bootstrap, to decide whether debug is on                |
| `client_ip`                                   | the bootstrap fills it when `null`; read by the application |
| `trust_proxy_headers`, `trusted_proxy_hops`   | `Router`, before it reads any `X-Forwarded-*` header        |
| `autoload_configs`, `autoload_helpers`        | the bootstrap                                               |
| `before_controller`                           | `Router::loadController()`, called before the controller    |
| `module_paths`                                | `Load`, to resolve a project name to a directory            |
| `disable_twig`                                | the bootstrap, to skip building the view engine             |
| `error_pages`                                 | `ErrorPage`, to override the status and debug templates     |
| `view_env_keys`                               | `Load`, the only `$_ENV` keys exposed to templates          |
| `view_data`                                   | `Load::view()`, merged into every view's data               |
| `logging.display_level`, `logging.log_level`, `logging.report_level` | the error handlers, to decide what is shown, logged and emailed |
| `environment`                                 | the debug error page, shown in the runtime summary          |

The bootstrap writes `client_ip`, `now`, `date_time`, `debug`, and - when twig is in use -
`view_engine` and `view_loader` back into the same array.

:::caution[`$config['debug_ips']` is no longer read]
It is gone rather than deprecated, and an application still setting it gets nothing from it,
silently. `$config['debug_check']` - any `callable(): bool` - replaces it. Why, and what to
write instead, is
[step 5 of upgrading](/staticphp-core/guides/upgrading/#5-debug_ips-is-gone-the-application-decides-who-sees-debug-output);
the resolution rules are under
[`resolveDebug()`](/staticphp-core/core/config/#resolvedebug).
:::

`query_string` in the sample above is bound for the application's convenience; the router
derives `Router::$query_string` from the request uri itself rather than reading it.
