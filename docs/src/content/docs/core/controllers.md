---
title: Controllers
description: The base controller, the lifecycle hooks the router calls, and __callStatic dispatch.
sidebar:
    order: 3
---

A controller is a class in a module's `Controllers` directory with public static methods on
it. Nothing is registered and nothing is instantiated - the router resolves a class name
from the url, builds a `\ReflectionClass` and invokes the method statically.

Extending `StaticPHP\Core\Controllers\Controller` is optional. It buys a `construct()` hook
that publishes the current urls to the view data, and four helper methods. A class that
extends nothing works exactly the same way as far as the router is concerned.

```php
<?php

namespace Site\Controllers;

use StaticPHP\Core\Controllers\Controller;

class Home extends Controller
{
    public static function index()
    {
        self::render(['home.php'], ['title' => 'Home']);
    }
}
```

The class name and its namespace come from the url, so the file has to sit where the
router will look for it: `APP_MODULES_PATH/Site/Controllers/Home.php` for the class above.
Which url maps to which file is covered in
[your first route](/staticphp-core/getting-started/first-route/) and in detail under
[the router](/staticphp-core/core/router/).

## What is reachable from a url

`Router::isRoutableMethod()` decides, and it requires a method to be:

- **public**, and
- **static**, and
- not named `construct`, `destruct`, `__construct`, `__destruct` or `__callStatic`.

Reflection can invoke private and protected methods without ceremony since PHP 8.1, so
without the visibility check every internal helper on the class would be an endpoint. The
lifecycle hooks are excluded because the router calls them itself.

Everything after the method segment in the url is passed to the method as positional
string arguments, so declare defaults for anything optional:

```php
<?php

public static function show(string $slug, string $tab = 'summary')
{
    return "{$slug} - {$tab}";
}
```

## The lifecycle

For every request that resolves to a controller, the router calls, in order:

| Hook          | Invoked as                                      | Return value                          |
| ------------- | ----------------------------------------------- | ------------------------------------- |
| `construct()` | `invokeArgs(null, [&$class, &$method])`         | kept, combined with the method's       |
| the method    | `invokeArgs(null, Router::$segments)`           | printed by type                        |
| `destruct()`  | `invokeArgs(null, [])`                          | discarded                              |

Both hooks are called only if the class has them. `construct()` is invoked with `$class`
and `$method` as references, and the method to dispatch is resolved *after* it returns - so
a `construct()` that declares `?string &$method` and reassigns it redirects the call to a
different method on the same class. The inherited `Controller::construct()` declares both
by value and does not do this. What it changes is the router's local copy, not
`Router::$method`, so the view data and the url properties still name the original.

The two return values are combined before anything is printed: `null` yields to the other,
two arrays are merged with `array_merge()`, and anything else is concatenated as a string.
A `construct()` returning an array against a method returning a non-array is a
`RouterException`.

`destruct()` runs after the response has been printed, so it is a place for cleanup, not
for output.

## The base controller

```php
<?php

namespace StaticPHP\Core\Controllers;

class Controller
{
    public static ?string $module_url = null;
    public static ?string $controller_url = null;
    public static ?string $method_url = null;

    public static function construct(?string $class = null, ?string $method = null);
    public static function destruct();

    public static function moduleUrl(): string;
    public static function controllerUrl(): string;
    public static function methodUrl(): string;

    public static function render(array $views, $view_data = []): void;
    public static function write(string|array $contents): void;
}
```

`destruct()` is empty. `moduleUrl()`, `controllerUrl()` and `methodUrl()` are
`Router::siteUrl()` applied to `Router::$module_url`, `Router::$controller_url` and
`Router::$method_url` respectively - so they are absolute urls including the base url and
the request's own prefixes, where the `Router` properties are bare relative paths.

### What construct() publishes

The inherited `construct()` fills the three static properties above, and then writes eleven
keys into `Config::$items['view_data']`, which `Load::view()` merges into the data of every
view:

| View data key         | Value                     |
| --------------------- | ------------------------- |
| `current_url`         | `Router::$parsed_url`     |
| `module_url`          | `self::moduleUrl()`       |
| `controller_url`      | `self::controllerUrl()`   |
| `method_url`          | `self::methodUrl()`       |
| `module`              | `Router::$module`         |
| `controller`          | `Router::$controller`     |
| `class`               | `Router::$class`          |
| `method`              | `Router::$method`         |
| `module_url_rel`      | `Router::$module_url`     |
| `controller_url_rel`  | `Router::$controller_url` |
| `method_url_rel`      | `Router::$method_url`     |

The `_rel` suffix marks the relative form; the unsuffixed url keys are the absolute ones.

If you write your own `construct()` in a controller that extends `Controller`, call
`parent::construct($class, $method)` or none of this happens - the router looks the method
up on the concrete class and invokes exactly one of them.

### render() and write()

```php
<?php

public static function render(array $views, $view_data = []): void;
```

`render()` prefixes every name with the current module and its `Views` directory -
`self::render(['home.php'])` becomes `Site/Views/home.php` - and hands the list to
`Load::view()`. It takes its view data by value, so an array literal is fine here;
`Load::view()` declares `$data` by reference, so a direct call to that has to pass a
variable. The prefix uses `Router::$module`, not the class's own namespace, so it follows
the request rather than the file.

`write()` echoes its argument, `json_encode()`ing it first if it is an array. It sends no
`Content-Type` header - returning an array from the method does that, `write()` does not.

## __callStatic dispatch

When the url names a method the class does not have, and the class defines `__callStatic`,
the router calls that instead of raising a 404. This is how a controller absorbs a whole
family of urls:

```php
<?php

namespace Site\Controllers;

use StaticPHP\Core\Controllers\Controller;

class Pages extends Controller
{
    public static bool $add_method_to_parameters = true;
    public static int $pad_call_static_parameters = 2;
    public static mixed $pad_call_static_default_value = null;

    public static function __callStatic(string $name, array $arguments)
    {
        [$page, $section] = $arguments;

        return "page {$page}, section " . var_export($section, true);
    }
}
```

Four static properties on the controller shape the `$arguments` array, each read with
`ReflectionClass::getStaticPropertyValue($name, $default)` so a class that declares none of
them still works:

| Property                            | Default | Effect                                                                                     |
| ----------------------------------- | ------- | ------------------------------------------------------------------------------------------ |
| `$add_method_to_parameters`         | `true`  | Prepends the method name from the url to `$arguments`.                                      |
| `$add_default_method_to_parameters` | `false` | Prepends it even when the method is the default route's own method.                         |
| `$pad_call_static_parameters`       | `0`     | Pads `$arguments` up to this length with `array_pad()`.                                     |
| `$pad_call_static_default_value`    | `null`  | The value padded with.                                                                      |

The name is prepended when `$add_method_to_parameters` is true **and** either
`$add_default_method_to_parameters` is true or the resolved method differs from
`Router::$default_route['method']`. The default combination therefore gives you the url's
method name as the first argument for `/site/pages/about`, but not for `/site/pages`, where
the method fell back to the default route's `index`.

Padding runs afterwards and only when the current count is below the target, which is what
makes destructuring a fixed number of arguments safe regardless of how many segments the
url carried.

## What a controller returns

Returning is optional; the method may print whatever it likes. When it does return, the
router prints by type: an array as `application/json; charset=utf-8`, a string or number as
is, a `StaticPHP\Core\Exceptions\ErrorMessage` through its own `outputMessage()`, and
`null` not at all.

Returning an `ErrorMessage` and throwing one are not the same thing. A returned one is
printed with `outputMessage()`'s defaults - the short html fragment, no full page - while a
thrown one is caught by `Router::init()` and rendered in the format the request asked for,
with the full status or debug page. Throw it unless you specifically want the fragment.

```php
<?php

use StaticPHP\Core\Exceptions\ErrorMessage\Forbidden;

public static function edit(string $slug)
{
    if (empty($_SESSION['user'])) {
        throw new Forbidden('Forbidden', "No session for {$slug}");
    }

    return ['slug' => $slug];
}
```

See [errors](/staticphp-core/core/errors/) for the exception hierarchy and what each one
does to the response.
