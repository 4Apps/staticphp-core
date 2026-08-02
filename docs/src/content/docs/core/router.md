---
title: Router
description: How a url becomes a static method call, and the state the router leaves behind.
sidebar:
    order: 2
---

`StaticPHP\Core\Models\Router` is entirely static: static properties, static methods, no
instance. It parses the request url, resolves it to a file and a class, calls a static
method on that class, and prints whatever comes back.

There is no route table. `$config['routing']` supplies the default route and any number of
regex rewrites; everything else is url segments walked against the filesystem. Defining a
route and writing the controller for it is covered in
[your first route](/staticphp-core/getting-started/first-route/) - this page is the
reference for what the class actually does and what it leaves behind for the controller.

## Request state

Every one of these is public and static, so a controller, a view or a helper reads them
directly. The right-hand column is what each holds *by the time a controller method runs*,
which is not always what the name suggests.

| Property                | Holds                                                                                                   |
| ----------------------- | ------------------------------------------------------------------------------------------------------- |
| `$domain_url`           | Scheme and host, plus the port when it is not 80/443. Only set when `$config['base_url']` was empty.     |
| `$base_url`             | `$config['base_url']`, or `$domain_url` plus the script's directory when that was empty.                 |
| `$requested_url`        | `$config['request_uri']` exactly as it arrived - script path and query string still attached.            |
| `$parsed_url`           | The url after rewriting, with the script path and query string stripped. Url prefixes are still in it.   |
| `$query_string`         | Everything after the first `?`, trimmed of `/&?`. `null` when there was none.                            |
| `$prefixes`             | The matched entries of `$config['url_prefixes']`, as `value => value`.                                   |
| `$prefixes_url`         | Those prefixes joined with `/`.                                                                          |
| `$initial_segments`     | See below - not the original url.                                                                        |
| `$initial_segments_url` | Those segments joined with `/`.                                                                          |
| `$segments`             | The method's positional arguments. Module, controller and method segments have been spliced off.         |
| `$segments_url`         | Those segments joined with `/`.                                                                          |
| `$default_route`        | `$config['routing']['']` parsed by `urlToFile()`.                                                        |
| `$module`               | First segment, upper-cased - `Site`.                                                                     |
| `$file`                 | Controller path relative to `APP_MODULES_PATH`, no extension - `Site/Controllers/Home`.                  |
| `$file_path`            | `dirname($file)`.                                                                                        |
| `$namespace`            | `\Site\Controllers`, plus any subdirectories between it and the class.                                   |
| `$controller`           | The controller path within the module's `Controllers` directory - `Admin/Home`.                          |
| `$class`                | Class name without namespace - `Home`.                                                                   |
| `$method`               | Method name, `lcfirst`ed - `orderHistory`.                                                               |
| `$module_url`           | `$module` in url form - `my-module`.                                                                     |
| `$controller_url`       | Url path to the controller, no leading slash - `site/admin/home`.                                        |
| `$method_url`           | Url path to the method - `site/admin/home/order-history`.                                                |
| `$request_content_type` | A `RequestContentType`, negotiated at the top of `init()` - see below.                                  |

`$module_url`, `$controller_url` and `$method_url` are relative paths, not absolute urls.
`Controller::moduleUrl()` and its siblings are what run them through `siteUrl()`.

`Router::debug()` prints most of the above with `print_r()`, in that order - it covers
everything except `$default_route` and `$request_content_type`.

### What `$initial_segments` is not

It is the url as it stood *before* a rewrite rule changed it - and only when a rule
actually matched. It is filled in `splitSegments()` at the moment a rewrite fires, then cut
down twice more before a controller sees it:

- url prefixes are shifted off it, alongside the ones shifted off `$segments`;
- `findControllerInSegments()` splices the same number of leading elements off it as it
  does off `$segments`, so the module, controller and method segments go too.

When no rewrite rule matched it is empty until `findControllerInSegments()`, which then
copies the already-spliced `$segments` into it.

So at controller time it holds the *pre-rewrite arguments*, and it holds nothing at all
when the rewrite consumed every segment. If you need the url as it arrived, read
`$requested_url`.

## init()

```php
<?php

public static function init(): void;
```

Called last by the bootstrap. It runs four things:

1. `populatePostFromJson()` - outside the try/catch, so it is the one step whose failure
   goes to the global exception handler.
2. `splitSegments()`
3. `findController()`
4. `loadController()`

The last three run inside a `try` with two `catch` blocks, and the split between them is
the whole error policy of the framework:

```php
<?php

} catch (ErrorMessage $e) {
    $e->outputMessage(
        ErrorMessage::outputTypeFromRequestType(self::$request_content_type ?? RequestContentType::HTML),
        true
    );
} catch (Throwable $e) {
    // wrapped in a 500 ErrorMessage, rendered, then logged and emailed
}
```

An `ErrorMessage` - so `NotFound`, `BadRequest`, `Forbidden` - is a client fault. It is
rendered and that is all: deliberately not logged, not emailed, so a crawler walking dead
urls cannot page anyone. Anything else is assumed to be the application's fault: it is
wrapped in an `ErrorMessage` carrying status 500, with `showStackTrace: true` and the
original exception as `previous`, and only in debug mode does the original message reach
the client. It is then passed to `sp_log_error()` and `sp_send_error_email()` if
`$config['logging']` allows it. See [errors](/staticphp-core/core/errors/).

Note that `outputMessage()` prints; `init()` returns normally afterwards, and neither catch
block calls `exit`.

### Content type negotiation

`populatePostFromJson()` is the first thing `init()` calls, and its first job is not the
json body its name refers to - it is deciding what format this request is in:

```php
<?php

$contentType = trim(self::server('CONTENT_TYPE'));
$acceptType = trim(self::server('HTTP_ACCEPT'));

self::$request_content_type = RequestContentType::fromString(
    empty($contentType) ? $acceptType : $contentType
);
```

The request's own `Content-Type` decides, and `Accept` is consulted only when there is no
`Content-Type` at all. A browser GET has no `Content-Type` and an `Accept` header starting
with `text/html`, so it lands on `HTML`; a client that posts json gets `JSON` from its
`Content-Type` whatever it says it accepts.

`StaticPHP\Core\Interfaces\RequestContentType` is the string-backed enum holding the
result - in the `Interfaces` namespace despite being an enum:

```php
<?php

enum RequestContentType: string
{
    case JSON = 'application/json';
    case XML = 'application/xml';
    case TEXT = 'text/plain';
    case HTML = 'text/html';
    case FORM = 'application/x-www-form-urlencoded';
    case MULTIPART = 'multipart/form-data';
    case NONE = 'none';

    public static function fromString(string $contentType): RequestContentType;
}
```

`fromString()` lower-cases its argument, cuts it at the first `;` and then at the first `,`,
and matches the remainder against the six media types exactly. Anything unrecognised -
including an empty string - is `NONE`.

Cutting at `;` is what strips `charset=utf-8` and `boundary=...`. Cutting at `,` is what
makes a full `Accept` header usable: `application/json, text/plain, */*` is reduced to its
first entry. No quality values are considered - it is first-listed-wins, not
highest-q-wins.

That is the whole negotiation, and the only thing the result is ever read for is to feed
`ErrorMessage::outputTypeFromRequestType()`, from the two catch blocks above and from
`Router::error()`. It decides the format of *error responses*, not of successful ones: a
controller returning an array always sends json, and a controller returning a string always
sends what it printed.

| `RequestContentType` | Error output type |
| -------------------- | ----------------- |
| `JSON`               | `json`            |
| `XML`                | `xml`             |
| `TEXT`               | `plain`           |
| `HTML`               | `html`            |
| `FORM`               | `html`            |
| `MULTIPART`          | `html`            |
| `NONE`               | `html`            |

### Json bodies in $_POST

The second half of `populatePostFromJson()` reads `php://input`, `json_decode()`s it and
copies each top-level key into `$_POST` - but only when the request's `Content-Type` is
exactly `application/json`, compared against `RequestContentType::JSON->value` as a raw
string. A `Content-Type` carrying a charset parameter does not match this comparison, even
though `fromString()` would have normalised it for the negotiation above.

A malformed body is ignored silently: the copy happens only if `json_last_error()` is
`JSON_ERROR_NONE` and the decoded value is an array.

The check is deliberately against `Content-Type` and never `Accept`. `application/json` is
not a CORS-safelisted content type, so a cross-origin request carrying a json body has to
pass a preflight first; `Accept` is safelisted, so honouring it here would let any site post
json into `$_POST`.

## splitSegments()

```php
<?php

public static function splitSegments(bool $force = false): void;
```

Returns immediately if `$domain_url` is already set and `$force` is false, so calling it
twice is harmless. In order:

1. Reads `$config['request_uri']`, `$config['script_name']` and `$config['base_url']`,
   all three through `Config::getString()`, so a non-string value counts as absent.
2. If `base_url` was empty and `$_SERVER['HTTP_HOST']` is set, builds `$domain_url` from
   the scheme - `requestIsSecure()` - the host - through `validateHost()` - and the port
   when it is neither 80 on http nor 443 on https. `$base_url` becomes that plus the
   script's directory. Where the port comes from behind a proxy is
   [proxy headers](/staticphp-core/core/request/#the-port-in-base_url).
3. Strips `script_name` out of the uri, splits off the query string, trims slashes.
4. Applies the rewrite rules.
5. Explodes the result on `/` and `rawurldecode()`s each segment.
6. Shifts off any leading segment listed in `$config['url_prefixes']`, recording it in
   `$prefixes`.
7. `define('BASE_URL', self::$base_url)`, guarded by `defined('BASE_URL') === false`.
   `$force` makes this method re-runnable and a second `define()` would be a warning, so
   the first run through wins - `Router::$base_url` follows a later run, the constant does
   not.

### The rewrite rules

```php
<?php

$config['routing'] = [
    '' => 'Site/Home/index',
    '^products/([a-z-]+)$' => 'shop/catalogue/show/$1',
];
```

The array is iterated with `array_reverse(..., true)`, each key used as a `preg_replace()`
pattern delimited with `#` (any `#` inside the key is escaped first) and each value as the
replacement. The first rule that *changes* the url wins and the loop breaks - so the last
matching rule in file order is the effective one, and the loop stops there rather than
running a `preg_replace()` per configured route on every request. Empty keys and empty
values are skipped, which is how the default route's `''` key escapes being treated as a
pattern.

Matching happens after the script path and query string are removed and **before** url
prefixes are stripped, so a rule that has to cope with a prefixed url must match the prefix
itself.

Segments are `rawurldecode()`d after the split on `/`, which means an encoded slash
survives inside one segment - `%2e%2e%2f` arrives as `../`. This is why
`findControllerInSegments()` re-validates every segment rather than trusting the split.

## findController() and findControllerInSegments()

```php
<?php

public static function findController(): void;
public static function findControllerInSegments(): void;
```

`findController()` first parses `$config['routing']['']` with `urlToFile()` and assigns
every result to the static properties, so the default route's module, class and method are
in place as fallbacks before any probing starts. A missing or unparseable default route is
a `RouterException`.

With no segments at all, the default route's `file` is used and the work is done.
Otherwise `findControllerInSegments()` runs up to three times:

1. As is.
2. If no file was found, the last segment is appended to itself
   (`$segments[] = end($segments)`), which finds `Blog/Blog.php` for `/site/blog`.
3. If still nothing, the last segment is replaced with the default route's class, which
   finds `Blog/Home.php` for `/site/blog` - the "this segment is a folder, load its default
   controller" case.

`findControllerInSegments()` itself:

- maps every segment through `urlToNamespace()`, so `order-history` becomes `OrderHistory`;
- shifts the first segment off as the module;
- returns immediately unless the module and every remaining segment satisfy
  `isSafeSegment()`;
- probes `APP_MODULES_PATH/{module}/Controllers/{segments joined}.php`, dropping one
  trailing segment per attempt until a file exists. Probed paths are memoised in a static
  array for the request, because the three passes above re-probe many of the same paths and
  PHP never caches a failed `stat`;
- on a hit, sets `$namespace`, `$module`, `$controller`, `$class`, `$file` and `$file_path`,
  and sets `$method` from the next segment if there is one - otherwise `$method` keeps the
  default route's value;
- splices `matched segment count + 2` elements off the front of `$segments` and
  `$initial_segments`, leaving the method arguments.

Finally `findController()` derives `$method_url` from the module, file and method through
`namespaceToUrl()`, `$controller_url` as its parent directory, and `$module_url` by
hyphenating `$module`.

## loadController()

```php
<?php

public static function loadController(
    ?string $file = null,
    ?string $module = null,
    ?string $namespace = null,
    ?string $class = null,
    ?string &$method = null
): void;
```

Every parameter defaults to the corresponding static property; `$method` is taken by
reference. Called with no arguments by `init()`, it does the following.

**1. The `before_controller` hook.** Each callable in `$config['before_controller']` is
invoked as `call_user_func_array($tmp, [&$file, &$module, &$class, &$method])` - all four
by reference, so a hook can redirect the request to a different controller entirely. At
this point `$file` is an absolute path with the `.php` extension and `$class` has not yet
had the namespace prepended.

```php
<?php

$config['before_controller'][] = function (&$file, &$module, &$class, &$method) {
    if ($module === 'Admin' && empty($_SESSION['user'])) {
        Router::redirect('site/account/login');
    }
};
```

**2. Module and directory bootstraps.** `APP_MODULES_PATH/{module}/Helpers/Bootstrap.php`
is included when it exists. Then the walk starts at `APP_MODULES_PATH . '/' . $file_path`
and climbs towards `APP_MODULES_PATH`, including a `_bootstrap.php` at every level it finds
one - so the deepest one runs first. Both are gated by `pathIsWithin()`.

**3. Reflection.** If `$file` exists and is inside `APP_MODULES_PATH`, the namespace is
prepended to the class name and a `\ReflectionClass` is built. If the file loaded but did
not declare that class, it is a `RouterException`.

**4. `construct()`.** Called with `invokeArgs(null, [&$class, &$method])` when the class has
it. Its return value is kept.

**5. Dispatch.** The named method is called when it exists *and* passes
`isRoutableMethod()`. Failing that, `__callStatic` is used if the class has one. Failing
both, a `NotFound` is thrown naming the method and class. See
[controllers](/staticphp-core/core/controllers/) for the `__callStatic` parameter rules.

**6. Output.** The `construct()` return value and the method return value are combined:
`null` yields to the other, two arrays are merged, anything else is concatenated as a
string, and a `construct()` returning an array against a method returning a non-array is a
`RouterException`. The combined value is then printed by type - an array as
`application/json`, a string or number as is, an `ErrorMessage` through its own
`outputMessage()` (called with the default arguments, so it prints the short html fragment
rather than a full error page), and `null` not at all.

**7. `destruct()`.** Called with no arguments if the class has it. Its return value is
discarded.

If no controller file was resolved, the failure is split by whether anything was requested
at all: an empty `$requested_url` means the *default* controller is missing, which is a
`RouterException` and a 500; anything else is a `NotFound`.

## url helpers

```php
<?php

public static function baseUrl(string $url = ''): string;
public static function siteUrl(string $url = '', ?string $prefix = null, bool $current_prefix = true): string;
public static function redirect(string $url = '', bool $site_uri = true, bool $e301 = false, string $type = 'http'): void;
public static function hasPrefix(string $prefix): bool;
public static function segment(int $index): ?string;
```

`baseUrl()` is `$base_url` plus the argument. `siteUrl()` is `$base_url`, then `$prefix` if
given, then the request's own `$prefixes_url` unless `$current_prefix` is false, then the
argument - each part run through `ensureStartsWithSlash()`. That ordering is what keeps a
link inside an `/lv-en/` prefixed request prefixed without the caller thinking about it.

```php
<?php

// $base_url is "https://example.com", the request carried the "lv-en" prefix
Router::siteUrl('site/home/index');                  // https://example.com/lv-en/site/home/index
Router::siteUrl('site/home/index', null, false);     // https://example.com/site/home/index
Router::siteUrl('site/home/index', 'admin', false);  // https://example.com/admin/site/home/index
```

`redirect()` sends `Location` and `Connection: close` headers, prefixed with a
`301 Moved Permanently` status line when `$e301` is true, and always ends in `exit(0)`.
With `$type` set to `js` it prints a `<script>` assigning `window.location.href` instead,
with the url passed through `json_encode()` so a quote in it cannot break out of the string
literal. `$site_uri` decides whether the url goes through `siteUrl()` first.

`segment()` is the error-proof form of `Router::$segments[$index]`, returning `null` for a
missing *or empty* index.

Two things build on these. [`Menu`](/staticphp-core/presentation/menus/) rewrites the
`%base_url`, `%module_url`, `%module` and `%controller` placeholders in a menu item's url
from `Router::$base_url`, `Router::$module`, `Router::$controller` and
`Controller::moduleUrl()` - which is itself `siteUrl(Router::$module_url)` - so a menu built
before the router has dispatched comes out with those parts empty.
[`Url`](/staticphp-core/utilities/url/) is the other direction: three string helpers for
joining path fragments, with no knowledge of the request at all.

## String helpers

```php
<?php

public static function urlToFile(string $url): mixed;
public static function urlToNamespace(string $url): string;
public static function namespaceToUrl(string $namespace): string;
public static function makePathString(string $path): string;
public static function ensureStartsWithSlash(string $string): string;
```

`urlToNamespace()` splits on `-` and `ucfirst`s each part: `order-history` becomes
`OrderHistory`. `namespaceToUrl()` reverses it by inserting a `-` before every capital that
is neither at the start of the string nor immediately after a `/`, then lower-casing:
`Site/Admin/Home/orderHistory` becomes `site/admin/home/order-history`.

`urlToFile()` parses a `module/class/method` string and returns `false` if it has fewer
than three parts:

```php
<?php

Router::urlToFile('Site/Admin/Home/index');
// [
//     'module'     => 'Site',
//     'method'     => 'index',
//     'class'      => 'Home',
//     'controller' => 'Admin/Home',
//     'file'       => 'Site/Controllers/Admin/Home',
//     'namespace'  => 'Site\Controllers',
// ]
```

`namespace` is always `{module}\Controllers` however many segments the input had, so a
default route naming a controller in a subdirectory produces a file path that includes the
subdirectory and a namespace that does not. Keep `$config['routing']['']` to three parts.

## Safety helpers

```php
<?php

public static function validateHost(string $host): string;
public static function isRoutableMethod(\ReflectionMethod $method): bool;
public static function isSafeSegment(?string $segment): bool;
public static function pathIsWithin(string $path, string $root): bool;
```

`validateHost()` lower-cases and trims the `Host` header. If `$config['allowed_hosts']` is
non-empty the header must appear in it exactly, otherwise a `BadRequest` is thrown. If the
list is empty the header is only checked against `/^[a-z0-9.\-]+(:[0-9]{1,5})?$/` - which
keeps existing installs working, but does not stop a client poisoning the `base_url` that
ends up in redirects, emails and cached pages. Set the list.

`isRoutableMethod()` requires public *and* static, and excludes `construct`, `destruct`,
`__construct`, `__destruct` and `__callStatic` - the comparison is `strtolower()`ed on both
sides, so the source spells the list in lower case and any casing of the method name is
caught. Reflection has been able
to invoke private and protected methods without `setAccessible()` since PHP 8.1, so without
this check every internal helper on a controller would be an endpoint.

`isSafeSegment()` is `/^[a-zA-Z][a-zA-Z0-9_-]*$/` - which is why a purely numeric segment
never resolves to a controller or to a method argument.

`pathIsWithin()` compares `realpath()` of both sides, so it survives symlinks and `..`. It
gates every include built from request data, and `Load::view()` uses it too.

## Reading the request behind a proxy

```php
<?php

public static function forwardedHeader(string $name): ?string;
public static function clientIp(): ?string;
public static function requestIsSecure(): bool;
```

These are the only places the framework looks past the connection this process sees.
`forwardedHeader()` returns nothing unless `$config['trust_proxy_headers']` is on; the
other two fall back to the connection this process sees, so `clientIp()` still answers with
the validated `REMOTE_ADDR` and `requestIsSecure()` still runs its `HTTPS` and
`SERVER_PORT` tests. `requestIsSecure()` decides the scheme in `splitSegments()`, the
session cookie's `Secure` flag and the url on the error page; the bootstrap calls
`clientIp()` to fill `$config['client_ip']` when the application left it `null`. They are
covered in full under [proxy headers](/staticphp-core/core/request/#proxy-headers).

## error()

```php
<?php

public static function error($http_error_code, $error_string = '', $description = ''): void;
```

Builds an `ErrorMessage` with the given code, falling back to
`ErrorMessage::httpStatusCodeToMessage()` for the text when `$error_string` is empty, marks
the description `publicDescription: true` - the caller passed it in order to have it shown -
renders it in the format matching `$request_content_type`, and calls `exit(10)`. A code of
`0` is turned into 500.

It reads `Router::$request_content_type`, which is set in `populatePostFromJson()` - the
first thing `init()` does - and falls back to `RequestContentType::HTML` when that has not
run yet. So it is safe to call before the router has started, and an error raised that early
comes out as html. The two catch blocks in `init()` use the same fallback.
