---
title: Upgrading to 2.0
description: Every breaking change from StaticPHP 1.x to 2.0, in the order to work through them.
sidebar:
    order: 3
---

2.0 turns the framework into a composer package. Everything below is a breaking change;
there is no compatibility shim, because 1.x was never released to Packagist as anything
other than the 2019 `v1.1.0` tag and every known installation vendors its own copy of
`System/`.

Read the whole list before starting. Steps 1 and 2 are mechanical, step 3 is where a site
actually has to make decisions.

This page is the migration path. What changed and why, release by release, is in
[CHANGELOG.md](https://github.com/gintsmurans/staticphp-core/blob/master/CHANGELOG.md).

## 1. Depend on the package instead of vendoring the framework

Delete the vendored `System/` directory and require the package:

```bash
git rm -r System            # or src/System
composer require 4apps/staticphp-core:^2.0
```

The application tree is untouched by this step. See
[installation](/staticphp-core/getting-started/installation/) for what the package does and
does not pull in with it.

## 2. Rewrite the namespaces

1.x shipped the framework half migrated: `Core\` was still a bare root while
`Presentation` and `Utils` had already moved under `System\Modules\`. Both land under
`StaticPHP\` in 2.0.

| 1.x                              | 2.0                            |
| -------------------------------- | ------------------------------ |
| `Core\Models\Router`             | `StaticPHP\Core\Models\Router` |
| `System\Modules\Utils\Models\Db` | `StaticPHP\Utils\Models\Db`    |
| `System\Modules\Presentation\…`  | `StaticPHP\Presentation\…`     |

The two prefixes are disjoint, so one pass handles a tree that mixes them:

```bash
scripts/upgrade_v2_namespaces.bash Application
```

It rewrites `.php`, `.html` and `.twig` files, handles both the `Core\` and the escaped
`Core\\` spelling, and is idempotent - running it twice changes nothing. Run it on a clean
working tree and read the diff; it is a text substitution, not a parser.

The script lives in the repository rather than in the package. `.gitattributes` marks
`scripts/` as `export-ignore`, so it is not in the dist archive `composer require` fetches -
take it from
[the repository](https://github.com/gintsmurans/staticphp-core/blob/master/scripts/upgrade_v2_namespaces.bash).

Application namespaces are unchanged. A module is still its own root -
`Pasta\Controllers\Quality` stays exactly as it is, and so does `Models\AppConfig` at the
application root.

## 3. Replace the bootstrap files

### `Public/index.php`

Composer is now the entry point rather than something the framework includes at the end of
its own bootstrap.

```php
<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';   // adjust depth to your layout

define('PUBLIC_PATH', __DIR__);

require StaticPHP\Core\Bootstrap::FILE;
```

`PUBLIC_PATH` is now **required**. The framework used to walk up from its own file to find
the application; installed at `vendor/4apps/staticphp-core/src` that would find the
framework's own demo app, so it throws instead of guessing. The file above is covered line
by line in [the front controller](/staticphp-core/getting-started/front-controller/).

### `Tests/autoload.php`

Both test bootstraps carried a hand-rolled copy of the autoloader. Replace the whole file:

```php
<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';   // adjust depth to your layout

define('PUBLIC_PATH', dirname(__DIR__) . '/Public');

require StaticPHP\Core\Bootstrap::AUTOLOAD;
```

`Bootstrap::AUTOLOAD` brings up the path constants and the application autoloader without
initialising the router or building a view engine.

### Path constants

| 1.x                | 2.0                                                                |
| ------------------ | ------------------------------------------------------------------ |
| `SYS_PATH`         | `SP_PATH` - the framework's own directory, wherever composer put it |
| `SYS_MODULES_PATH` | `SP_PATH` - the framework has no `Modules/` level any more          |
| `VENDOR_PATH`      | unchanged, but now resolved from composer rather than probed        |

`SYS_PATH` and `SYS_MODULES_PATH` are gone rather than redefined, so a file still using
them fails loudly instead of silently resolving somewhere wrong. In practice both were
only ever referenced from the test bootstrap you are replacing above.

The constants that remain, and what each derives from, are listed under
[the path constants](/staticphp-core/getting-started/front-controller/#the-path-constants).

## 4. `$project` now names a registered module path

The third segment in `Load::` calls and in the autoload lists used to be a directory name
appended to `BASE_PATH`, which assumed every loadable tree sat beside the application. It
now names an entry in `$config['module_paths']`:

```php
<?php

$config['module_paths'] = [
    'site2' => BASE_PATH . '/site2/Modules',
];
```

The value is the directory that *holds* modules, not its parent. `staticphp` is a reserved
name resolving to the framework's own modules - it needs no entry and cannot be
overridden:

```php
<?php

// 1.x
$config['autoload_helpers'] = ['Functions', 'System/Utils/Helpers'];
Config::load(['Db'], 'Utils', 'System');

// 2.0
$config['autoload_helpers'] = ['Functions', 'staticphp/Utils/Helpers'];
Config::load(['Db'], 'Utils', 'staticphp');
```

An unknown name now throws `InvalidArgumentException` rather than silently building a path
that does not exist. The full resolution table is under
[how a name resolves to a path](/staticphp-core/core/load/#how-a-name-resolves-to-a-path),
and what module paths are for is
[running multiple applications](/staticphp-core/guides/multiple-applications/).

## 5. `debug_ips` is gone; the application decides who sees debug output

`$config['debug_ips']` is no longer read. It compared `client_ip` against a list, and
`client_ip` comes from the request - so behind a proxy that appends to `X-Forwarded-For`,
a client could put a listed address in the header and turn on debug output for itself.
Debug is not just the timing panel: it is `display_errors`, full exception traces, twig's
debug mode and the query log.

The framework now makes no trust decision of its own. `$config['debug']` still forces it
on, and `$config['debug_check']` - any `callable(): bool` - decides for everyone else:

```php
<?php

// Application/Config/Config.php

$config['debug_check'] = function (): bool {
    // php's variables_order is usually GPCS, so $_ENV holds what dotenv put there rather
    // than the process environment, and getenv() is the other way round. Read both
    $secret = $_ENV['DEBUG_SECRET'] ?? getenv('DEBUG_SECRET') ?: '';
    $token = $_COOKIE['sp_debug'] ?? '';
    if ($token === '' || $secret === '') {
        return false;
    }

    return hash_equals(hash_hmac('sha256', 'debug', $secret), $token);
};
```

It runs during bootstrap, **before sessions, the database and routing exist**, because
query logging has to be armed before the first query runs. It can read `$_SERVER`,
`$_COOKIE` and configuration, and nothing else - a check that reaches for `$_SESSION` will
not find it. Anything it throws is logged and treated as "no", and only a strict `true`
opens the gate.

To keep the old behaviour exactly, say so out loud:

```php
<?php

$config['debug_check'] = fn(): bool => in_array(
    StaticPHP\Core\Models\Router::clientIp(),
    ['::1', '127.0.0.1'],
    true
);
```

That is no more trustworthy than it was before - see `trust_proxy_headers` below - but it
is now a choice the application made rather than one the framework made for it. The
resolution rules in full are under
[`resolveDebug()`](/staticphp-core/core/config/#resolvedebug).

## 6. Proxy awareness is opt-in

`$config['trust_proxy_headers']` (default `false`) makes the framework read
`X-Forwarded-Proto`, `X-Forwarded-Port` and `X-Forwarded-For` instead of the connection
this process sees. Turn it on when a reverse proxy terminates tls or listens on another
port, and only when that proxy is the sole route in.

Without it, behind tls termination: `base_url` advertises the internal port, the session
cookie loses its `Secure` flag, and `client_ip` is the proxy for every request.

`$config['trusted_proxy_hops']` (default `1`) says how many proxies are in front.
`X-Forwarded-For` is appended to rather than overwritten, so entries are counted from the
right - the rightmost is the address your own proxy saw and cannot be forged. Use `2` for
a cdn in front of an ingress.

`$config['client_ip']` now defaults to `null`, meaning "work it out", the way `base_url`
does. An application that set it explicitly keeps whatever it set - including the 1.x
`$config['client_ip'] = & $_SERVER['REMOTE_ADDR'];` binding, which now pins the address to
the proxy on a proxied deployment. Drop that line, or set the key to `null`, to let the
bootstrap resolve it.

What each header is read for, and the order the three router methods answer in, is
[proxy headers](/staticphp-core/core/request/#proxy-headers).

## 7. Twig is optional

`4apps/staticphp-core` suggests `twig/twig` instead of requiring it. If your application
renders templates, require it explicitly:

```bash
composer require twig/twig:^3.0
```

If it does not - an api, a worker - leave it out and the files that twig and the symfony
polyfills load eagerly on every request go away with it. `$config['disable_twig']` still
exists and still skips building the environment when the library *is* installed.

`registerTwig()` on the shipped classes now returns quietly when there is no view engine,
so it is safe to call either way.

[Running without Twig](/staticphp-core/guides/without-twig/) measures what leaving it out
actually saves, and covers what an api-only application renders instead.

### `Load::view()` without an engine

The no-engine path is now a real view layer rather than a stub, because for an api-only
install it is the *only* view layer:

- It extracts `$data` into the template. Previously it required the file without passing
  anything, so a plain php view could only reach `$config`.
- `$return = true` returns the rendered string. Previously it returned `false`.
- `$config` and `$env` are provided the same way twig provides them, filtered through the
  same credential stripping.

The include happens in a scope of its own, so a data key called `files` or `path` cannot
overwrite the renderer's own variables. See
[Load::view() without twig](/staticphp-core/core/load/#without-twig).

## What did not change

- Application namespaces, module layout and routing.
- `Load::view()`, controllers, `Router::error()`, the config array.
- The `staticphp` cli and its `migrate` / `i18n` commands.

## Things worth knowing

- The application autoloader now probes two roots (`APP_MODULES_PATH`, `APP_PATH`) instead
  of five. The tail of the old chain let an application shadow a framework class by
  filename; nothing used it, and composer resolves `StaticPHP\` before the callback runs.
- It is registered *after* composer's, so framework and vendor classes never reach it.
- If you want the application classmap optimised in production, `composer dump-autoload -o`
  works as usual - but it cannot express one repository serving several applications, so
  leave it off if you use that layout. There is a
  [worked demonstration of what goes wrong](/staticphp-core/guides/multiple-applications/#why-the-application-tree-stays-out-of-composers-map),
  including the part `-o` is not responsible for.
