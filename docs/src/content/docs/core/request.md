---
title: Request
description: Internal sub-requests, the cli superglobals, and how the request content type is negotiated.
sidebar:
    order: 4
---

`StaticPHP\Core\Models\Request` is not an object wrapping the current http request. There
is no such object in this framework - the superglobals are read directly, and everything
derived from the url lives on [the router](/staticphp-core/core/router/) as static
properties.

What this class does is the other direction: it runs the front controller as a child
process to serve a url internally, and it builds the superglobals back up from `argv` at
the far end so that the child sees a request rather than a bare cli invocation.

```php
<?php

namespace StaticPHP\Core\Models;

class Request
{
    public static function internal(string $url, ?array $post = null, ?array $query = null, bool $https = false): string;
    public static function httpErrorInData(string $data): bool;
    public static function populateFromCli(?array $argv = null): void;
}
```

## internal()

```php
<?php

$html = Request::internal('site/reports/monthly', ['month' => '2026-07']);

if (Request::httpErrorInData($html)) {
    Logger::error('monthly report render failed');
}
```

Builds a command line, runs it with `exec()`, and returns the child's stdout joined with
`"\n"`. The command is:

```text
LC_ALL=lv_LV.utf8 'php' '<PUBLIC_PATH>/index.php' ['--post' '<urlencoded>'] ['--query' '<urlencoded>'] ['--https'] '<url>'
```

Worth knowing before you rely on it:

- The php binary is invoked as plain `php`, so it comes from `PATH` and is whatever the web
  server user resolves - not necessarily the SAPI serving the parent request.
- The front controller is assumed to be `PUBLIC_PATH . '/index.php'`.
- Every element is passed through `escapeshellarg()`, and the `LC_ALL=lv_LV.utf8` prefix is
  prepended afterwards. That locale is hardcoded in the source.
- `$post` and `$query` are serialised with `http_build_query()`.
- The child's exit code is captured into a local and then ignored. A failure is only
  visible in the returned body, which is what `httpErrorInData()` is for.

`httpErrorInData()` is a substring test, not a status code check: it returns true if the
body contains `403 Forbidden`, `404 Not Found`, `500 Internal Server Error` or
`syntax error`, case insensitively. That means a page legitimately containing one of those
strings reads as an error.

## populateFromCli()

```php
<?php

public static function populateFromCli(?array $argv = null): void;
```

Called by the bootstrap, third, before the configuration is loaded. Under any SAPI other
than `cli` it returns immediately.

It drops the script name from `$argv` and then walks the rest, recognising `--post`,
`--query` and `--https`; anything else is taken as the url, so a later bare argument
overwrites an earlier one. It then writes:

| Superglobal key            | Set to                                                      |
| -------------------------- | ----------------------------------------------------------- |
| `$_GET`                    | the parsed `--query` data                                    |
| `$_POST`                   | the parsed `--post` data                                     |
| `$_REQUEST`                | `$query + $post`                                             |
| `$_SERVER['REQUEST_URI']`  | `/` + the url, plus `?` and the rebuilt query string          |
| `$_SERVER['QUERY_STRING']` | the rebuilt query string                                     |
| `$_SERVER['REQUEST_METHOD']` | `POST` when there was post data, `GET` otherwise           |
| `$_SERVER['SCRIPT_NAME']`  | left as is, or `''`                                          |
| `$_SERVER['REMOTE_ADDR']`  | left as is, or `127.0.0.1`                                   |
| `$_SERVER['HTTP_HOST']`    | left as is, or `localhost`                                   |
| `$_SERVER['SERVER_PORT']`  | left as is, or 443 with `--https`, or 80                     |
| `$_SERVER['HTTPS']`        | `on`, only with `--https`                                    |
| `$_SERVER['CONTENT_TYPE']` | `application/x-www-form-urlencoded`, only when there is post data |

The four entries that fall back with `??` keep any value the environment already provided,
so an existing `REMOTE_ADDR` is not overwritten.

The ordering constraint matters: the application's `Config.php` binds
`$config['request_uri']`, `$config['script_name']` and `$config['client_ip']` to `$_SERVER`
entries **by reference**. Rewriting the superglobals after the configuration was read would
leave the configuration describing the wrong request - which is exactly why this call sits
where it does in the boot sequence. See [bootstrap](/staticphp-core/core/bootstrap/).

`$_SERVER['CONTENT_TYPE']` being set only for post data is what makes the content type
negotiation below behave sensibly under cli: a GET has no content type, so the negotiation
falls through to `NONE` and the response comes out as html.

## RequestContentType

```php
<?php

namespace StaticPHP\Core\Interfaces;

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

A string-backed enum, in the `Interfaces` namespace despite being an enum. `fromString()`
lower-cases its argument, cuts it at the first `;` and then at the first `,`, and matches
the remainder against the six media types exactly. Anything unrecognised - including an
empty string - is `NONE`.

Cutting at `;` is what strips `charset=utf-8` and `boundary=...`. Cutting at `,` is what
makes a full `Accept` header usable: `application/json, text/plain, */*` is reduced to its
first entry, and no quality values are considered. It is first-listed-wins, not
highest-q-wins.

## How the content type is chosen

Negotiation happens once, in `Router::populatePostFromJson()`, the first statement of
`Router::init()`:

```php
<?php

$contentType = (isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '');
$acceptType = (isset($_SERVER["HTTP_ACCEPT"]) ? trim($_SERVER["HTTP_ACCEPT"]) : '');

self::$request_content_type = RequestContentType::fromString(
    empty($contentType) ? $acceptType : $contentType
);
```

So the request's own `Content-Type` decides, and `Accept` is consulted only when there is
no `Content-Type` at all. A browser GET has no `Content-Type` and an `Accept` header
starting with `text/html`, and lands on `HTML`; a client that posts json gets `JSON` from
its `Content-Type` whatever it says it accepts.

That is the whole negotiation. The result is stored on `Router::$request_content_type`, and
the only thing it is ever read for is to feed
`ErrorMessage::outputTypeFromRequestType()` - from the two catch blocks in `init()` and
from `Router::error()`. It decides the format of *error responses*, not of successful ones.
A controller returning an array always
sends json; a controller returning a string always sends what it printed.

| `RequestContentType` | Error output type |
| -------------------- | ----------------- |
| `JSON`               | `json`            |
| `XML`                | `xml`             |
| `TEXT`               | `plain`           |
| `HTML`               | `html`            |
| `FORM`               | `html`            |
| `MULTIPART`          | `html`            |
| `NONE`               | `html`            |

## Json bodies in $_POST

The second half of `populatePostFromJson()` reads `php://input`, `json_decode()`s it and
copies each top-level key into `$_POST` - but only when the request's `Content-Type` is
exactly `application/json`, compared against `RequestContentType::JSON->value` as a raw
string. A `Content-Type` carrying a charset parameter does not match this comparison, even
though `fromString()` would have normalised it for the negotiation above.

A malformed body is ignored silently: the copy happens only if `json_last_error()` is
`JSON_ERROR_NONE` and the decoded value is an array.

The check is deliberately against `Content-Type` and never `Accept`. `application/json` is
not a CORS-safelisted content type, so a cross-origin request carrying a json body has to
pass a preflight first; `Accept` is safelisted, so honouring it here would let any site
post json into `$_POST`.
