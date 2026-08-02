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
`$config['request_uri']` and `$config['script_name']` to `$_SERVER` entries **by
reference**. Rewriting the superglobals after the configuration was read would leave the
configuration describing the wrong request - which is exactly why this call sits where it
does in the boot sequence. See [bootstrap](/staticphp-core/core/bootstrap/).

`$_SERVER['CONTENT_TYPE']` is set only when there is post data, which is what keeps the
framework's content type negotiation sensible under cli: a GET has no content type, so the
negotiation falls through to `NONE` and errors come out as html. That negotiation is a
router concern rather than one of this class - see
[the router](/staticphp-core/core/router/).

## Proxy headers

Behind a reverse proxy the connection this process sees is the proxy's. `HTTPS` is unset
when the proxy terminated tls, `SERVER_PORT` is the internal hop rather than the port the
client dialled, and `REMOTE_ADDR` is the proxy for every request on the site. Three static
methods on `Router` are the only places the framework asks what the client actually did:

```php
<?php

public static function forwardedHeader(string $name): ?string;
public static function clientIp(): ?string;
public static function requestIsSecure(): bool;
```

All three are gated on one setting:

| Key                             | Default | What it does                                                       |
| ------------------------------- | ------- | ------------------------------------------------------------------ |
| `$config['trust_proxy_headers']` | `false` | Read `X-Forwarded-Proto`, `X-Forwarded-Port` and `X-Forwarded-For` |
| `$config['trusted_proxy_hops']`  | `1`     | How many proxies are in front, counted from the right of `X-Forwarded-For` |
| `$config['client_ip']`           | `null`  | `null` means "work it out"; anything else is kept as set           |

Turn `trust_proxy_headers` on only when a proxy that rewrites those headers is the sole
route in. They are client-supplied otherwise, and what they feed - `base_url`, the session
cookie's `Secure` flag, the address in the logs - ends up in redirects, emails and cached
pages.

### forwardedHeader()

Returns `null` when `trust_proxy_headers` is off, and otherwise the first comma-separated
entry of `$_SERVER[$name]`, trimmed, or `null` when that is empty. Chained proxies append
to these lists and the leftmost entry is the original request, which is right for the
headers that describe the request itself - a proxy sets `X-Forwarded-Proto` outright.
`X-Forwarded-For` is the exception, and `clientIp()` reads it from the other end.

### clientIp()

Starts from `REMOTE_ADDR` and returns it unchanged when `trust_proxy_headers` is off.
Otherwise it splits `X-Forwarded-For` on `,`, drops empty entries, and indexes
`max(0, count - trusted_proxy_hops)` - counting from the right, one entry per trusted hop.
`trusted_proxy_hops` below `1` is clamped to `1`. An entry that does not parse as an
address falls back to `REMOTE_ADDR` rather than being passed on; `[2001:db8::1]:443` and
`192.0.2.1:51234` are unwrapped first, because load balancers differ on whether they append
the source port.

Counting from the right is the whole point. `X-Forwarded-For` is *appended* to rather than
overwritten - nginx's `$proxy_add_x_forwarded_for` tacks the peer onto whatever the client
sent - so with one proxy in front the rightmost entry is the one it added itself, which is
the peer it saw and cannot be forged. The leftmost is whatever the client typed.

With `REMOTE_ADDR` at `10.0.0.5` and `X-Forwarded-For: 203.0.113.9, 198.51.100.7,
192.0.2.1`:

```text
trust off, XFF present                         => '10.0.0.5'
trust on, hops 1                               => '192.0.2.1'
trust on, hops 2                               => '198.51.100.7'
trust on, hops 5 (longer than the chain)       => '203.0.113.9'
trust on, no XFF at all                        => '10.0.0.5'
trust on, XFF entry with a port                => '192.0.2.1'
trust on, bracketed ipv6 with a port           => '2001:db8::1'
trust on, XFF is not an address                => '10.0.0.5'
no REMOTE_ADDR and no XFF (cli)                => NULL
```

The bootstrap calls this once, straight after `Config::load(['Config', 'Routing'])` and
before debug is resolved, and only when `$config['client_ip']` is `null`:

```php
<?php

if (Config::get('client_ip', null) === null) {
    Config::$items['client_ip'] = Router::clientIp();
}
```

`Config::get()` decides with `isset()`, so a key set to `null` and a key never set both
count as "work it out". An application that assigns `$config['client_ip']` - including the
1.x `= & $_SERVER['REMOTE_ADDR']` binding - keeps its own answer, and on a proxied
deployment that answer is the proxy.

### requestIsSecure()

The single answer to "did this request arrive over tls", in this order:

1. `X-Forwarded-Proto` through `forwardedHeader()`, so only when the header is trusted.
   `https`, case insensitively, is true; any other value it names is false.
2. `$_SERVER['HTTPS']` set to anything other than the empty string or `off`. php documents
   it as "a non-empty value" rather than as `on` specifically - apache and nginx say `on`,
   iis says `off` for a plain request, some setups say `1`.
3. `SERVER_PORT === '443'`, the last resort for a server that terminates tls without
   setting `HTTPS` at all. Only reached when no trusted proxy spoke, so the port is the one
   the client connected to.

```text
nothing set                                    => false
HTTPS=on                                       => true
HTTPS=off (iis)                                => false
HTTPS=1                                        => true
SERVER_PORT=443, HTTPS unset                   => true
X-Forwarded-Proto=https, trust off             => false
X-Forwarded-Proto=https, trust on              => true
```

Four call sites read it, and before it existed they each decided separately: the scheme and
port `Router::splitSegments()` builds `base_url` from, the session cookie's `Secure` flag in
[`Sessions`](/staticphp-core/utilities/sessions/), the url on the error page, and the url in
the logged exception. The error page could advertise `https://` for a request whose session
cookie had just gone out without `Secure`.

### The port in base_url

`splitSegments()` only builds `$domain_url` when `$config['base_url']` is empty. When it
does, the port comes from `X-Forwarded-Port` if that is trusted and all digits. Failing
that, and only when a trusted `X-Forwarded-Proto` was present, it assumes that scheme's
default port - `443` or `80` - rather than `SERVER_PORT`, because `X-Forwarded-Proto`
without `X-Forwarded-Port` is the common setup and falling back there advertised the
internal hop: `https://example.com:8080`, a port nothing outside can reach. With no trusted
proxy header at all it is `SERVER_PORT`. The port is then appended only when it is neither
80 on http nor 443 on https. See
[`splitSegments()`](/staticphp-core/core/router/#splitsegments).
