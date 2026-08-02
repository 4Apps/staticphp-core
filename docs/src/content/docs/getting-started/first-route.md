---
title: Your first route
description: Defining a route, writing the controller that serves it, and what happens when nothing matches.
sidebar:
    order: 4
---

Routing is url segments mapped onto files and static methods. There is no route table to
register; `Config/Routing.php` only supplies the default route and any rewrites.

## The routing configuration

```php
<?php

$config['routing'] = [
    '' => 'Site/Home/index',
    '^products/([a-z-]+)$' => 'shop/catalogue/show/$1',
];
```

The empty key is the default route and is not optional: without it the router throws a
`RouterException` reading `Missing default routing configuration: $config['routing']['']`.
Its value is `module/class/method`, all three parts required - anything shorter is a
`RouterException` as well.

Every other key is a regular expression matched against the request url once the script
path and the query string have been stripped off, and before any url prefix is removed.
The key is used as the pattern with `#` delimiters, and the value as the replacement, so
backreferences work as they do in `preg_replace()`. The rules are tried in reverse order and the first one that changes the
url wins.

The url as it arrived is captured into `Router::$initial_segments` at the moment a rule
matches, but it does not stay whole: the prefix, module, controller and method segments are
stripped off it alongside the rewritten ones. By the time a controller runs it holds the
original arguments rather than the original url - and nothing at all when the rewrite
consumed every segment, which is the case for the `/products/blue-widget` rule above.

## The controller

The default route above names `Site/Home/index`, which is
`APP_MODULES_PATH/Site/Controllers/Home.php`:

```php
<?php

namespace Site\Controllers;

use StaticPHP\Core\Controllers\Controller;

class Home extends Controller
{
    public static function index()
    {
        $data = ['title' => 'Home'];

        self::render(['home.php'], $data);
    }

    public static function show(string $slug, string $tab = 'summary')
    {
        return "{$slug} - {$tab}";
    }
}
```

The module name is the namespace root, and the class sits in the module's `Controllers`
directory - that pairing is what the application autoloader resolves, and it is why the
namespace is not PSR-4.

Methods are static. Extending `StaticPHP\Core\Controllers\Controller` is optional but
gives you a `construct()` hook that publishes the current urls to the view data, plus:

```php
<?php

public static function render(array $views, array $view_data = []): void;
public static function write(string|array $contents): void;

public static function moduleUrl(): string;
public static function controllerUrl(): string;
public static function methodUrl(): string;
```

`render()` prefixes each view name with the current module and its `Views` directory, so
`self::render(['home.php'])` renders `Site/Views/home.php`, and hands the result to
`Load::view()`. Without twig installed that path is resolved under `APP_MODULES_PATH`;
with twig it is a template name resolved by the loader, which searches
`APP_MODULES_PATH`, `APP_PATH` and `SP_PATH/Core/Views`. `render()` takes its view data by
value, so an array literal is fine there; `Load::view()` itself declares `$data` by
reference, so a call straight to it has to pass a variable.

## The view

That file has to exist. `render()` names it but does not create it, and a route whose view
is missing is a 500 rather than an empty page - a `Twig\Error\LoaderError` when twig is
installed. Without twig the failure is a `RuntimeException` rather than the `require`
itself: `Load::view()` checks the resolved path with `Router::pathIsWithin()` first, and
`realpath()` of a file that does not exist returns `false`, so the check fails before
`require` ever runs. The message names the wrong cause - `View outside of the modules
directory: "Site/Views/home.php"` reads like a path escaping `APP_MODULES_PATH`, but the
file is simply missing. Write `APP_MODULES_PATH/Site/Views/home.php`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p>Served by <?= htmlspecialchars($module_url, ENT_QUOTES, 'UTF-8') ?></p>
</body>
</html>
```

`$title` is the key the controller put in `$data`. `$module_url` comes for free: extending
`Controller` runs `construct()`, which publishes `current_url`, `module_url`,
`controller_url`, `method_url`, `module`, `controller`, `class` and `method` into
`Config::$items['view_data']`, and `Load::view()` merges that into every view's data. There
is no automatic escaping on this path, which is why the two values above are escaped by
hand.

Requesting `/` now serves:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Home</title>
</head>
<body>
    <h1>Home</h1>
    <p>Served by http://localhost/site</p>
</body>
</html>
```

:::caution[The file extension does not decide the renderer]
`Load::view()` looks at `Config::viewEngine()`, not at the file name. With twig installed
and `disable_twig` unset, `home.php` is handed to twig, which treats `<?= ... ?>` as literal
text and prints it. Write the file as a twig template in that case - `{{ title }}` rather
than `<?= $title ?>` - or set `$config['disable_twig'] = true`. The plain php path above is
what a stock `composer require 4apps/staticphp-core` gives you, since
[twig is a suggestion rather than a requirement](/staticphp-core/getting-started/installation/#twig-is-a-suggestion-not-a-requirement).
:::

## What the url maps to

The first segment is the module, the following segments locate the controller file, and
the segment after it is the method. Segments are converted to class and method names by
splitting on `-` and upper-casing each part, so `/site/home/order-history` calls
`Site\Controllers\Home::orderHistory()`.

| Request                              | Calls                                | Arguments               |
| ------------------------------------ | ------------------------------------ | ----------------------- |
| `/site/home/show/blue-widget`        | `Home::show()`                       | `'blue-widget'`         |
| `/site/home/show/blue-widget/reviews` | `Home::show()`                      | `'blue-widget', 'reviews'` |
| `/site/home`                         | `Home::index()`                      | none                    |
| `/site`                              | `Home::index()`                      | none                    |
| `/products/blue-widget`              | `Shop\Controllers\Catalogue::show()` | `'blue-widget'`         |

Whatever is left after the method segment is passed to the method as positional string
arguments. When the url stops at the controller, the method falls back to the default
route's method; when it stops at the module, the class falls back to the default route's
class as well - so `/site` above lands on `Home::index()` because the default route names
`Home/index`.

Two limits are worth knowing before designing urls:

-   **Every segment must look like an identifier.** After the dash conversion each one has
    to match `/^[a-zA-Z][a-zA-Z0-9_-]*$/`, arguments included, because the same segments
    end up in a file path and a class name. A purely numeric segment does not match, so
    `/site/home/show/42` finds no controller at all and returns a 404 rather than calling
    `show('42')`. Slugs work; numeric ids have to travel in the query string or the body.
-   **Only public static methods are reachable.** Reflection can invoke private and
    protected methods without ceremony, so the router checks visibility explicitly;
    `construct`, `destruct`, `__construct`, `__destruct` and `__callStatic` are excluded
    too, since the router calls those itself. Anything else on the class would otherwise be
    an endpoint.

## What a controller returns

Returning is optional - the method may print or render whatever it likes. If it does
return something, the router acts on the type:

-   an array is sent as `application/json; charset=utf-8` and `json_encode`d,
-   a string or a number is echoed as is,
-   an `ErrorMessage` is rendered through its own `outputMessage()`,
-   `null` produces no output.

When the class also has a `construct()` hook, its return value is combined with the
method's: two arrays are merged, anything else is concatenated as a string. A `construct()`
that returned an array and a method that returned something else is a `RouterException`.
The reverse is not checked and falls into the concatenation.

## When nothing matches

Failures split into the client's fault and ours, and the two are treated differently.

A url that does not resolve raises `StaticPHP\Core\Exceptions\ErrorMessage\NotFound`, a
404. That covers both cases: no controller file for the path, and a method the class does
not have with no `__callStatic` to absorb it. Its siblings `BadRequest` (400, raised for an
untrusted or malformed `Host` header) and `Forbidden` (403) work the same way.
`Router::init()` renders these and stops there - deliberately not logged and not emailed,
so a crawler walking dead urls cannot page anyone.

`RouterException` is the other half, and means the application is misconfigured: no
default route, a malformed one, a default controller that does not exist, or a controller
file that loaded without declaring the class the url named. It is a plain `\Exception`, so
it lands in the generic branch of `Router::init()`, which wraps it in a 500, renders it,
and then logs and emails it according to `$config['logging']`.

Either way the response format follows the request: json in, json out, and likewise for
xml, plain text and html. For html there are two pages, both in `src/Core/Views/Errors/`:

-   `debug.php`, shown only when debug is on, with the exception chain, source excerpts,
    the stack trace and the request state, with anything that looks like a credential
    redacted.
-   `status.php`, shown to everyone else: the status code, a plain sentence explaining it,
    and a reference id for 5xx so the page can be correlated with the log. No message, no
    path, no trace.

Both are self-contained single documents with inline css and no external requests, because
the page that has to render when something broke is the worst possible place to depend on
the template engine or the network. Either can be replaced per application:

```php
<?php

$config['error_pages'] = [
    'status' => APP_PATH . '/Views/Errors/status.php',
    'debug'  => null,
];
```

## Next

That is a working application: a front controller, a configuration, a route, a controller
and a view. Everything after this is reference, and the Core section is where it starts:

-   [The boot sequence](/staticphp-core/core/bootstrap/) - the eleven steps `Bootstrap::FILE`
    runs, and the ordering constraint behind each.
-   [The router](/staticphp-core/core/router/) - prefixes, url helpers, content type
    negotiation and the `before_controller` hook.
-   [Controllers](/staticphp-core/core/controllers/) - the `construct()` and `destruct()`
    hooks, and `__callStatic` dispatch for urls no method matches.
-   [Load](/staticphp-core/core/load/) - how views, configs and helpers resolve to files,
    and what a view is and is not allowed to see.
-   [Errors](/staticphp-core/core/errors/) - the exception hierarchy behind the 404 above,
    and what gets logged, emailed or shown.

Beyond Core: [the database layer](/staticphp-core/database/db/),
[internationalisation](/staticphp-core/i18n/overview/),
[the audit trail](/staticphp-core/audit/overview/),
[the utilities](/staticphp-core/utilities/cache/) and
[the table subsystem](/staticphp-core/presentation/tables/).
