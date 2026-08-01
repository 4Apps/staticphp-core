---
title: Request
description: Running a url as an internal sub-request, and building the superglobals from argv under cli.
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

`$_SERVER['CONTENT_TYPE']` is set only when there is post data, which is what keeps the
framework's content type negotiation sensible under cli: a GET has no content type, so the
negotiation falls through to `NONE` and errors come out as html. That negotiation is a
router concern rather than one of this class - see
[the router](/staticphp-core/core/router/).
