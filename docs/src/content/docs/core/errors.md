---
title: Errors
description: The exception hierarchy, the registered handlers, and the two html error pages.
sidebar:
    order: 9
---

Error handling in StaticPHP Core is built around one distinction: **is this the client's
fault or ours**. Client faults are rendered and forgotten; everything else is rendered,
logged and reported. The exception class you throw is what picks a side.

## The hierarchy

```text
\ErrorException
└── StaticPHP\Core\Exceptions\SpErrorException

\Exception
├── StaticPHP\Core\Exceptions\RouterException
└── StaticPHP\Core\Exceptions\ErrorMessage
    ├── StaticPHP\Core\Exceptions\ErrorMessage\BadRequest    (400)
    ├── StaticPHP\Core\Exceptions\ErrorMessage\Forbidden     (403)
    └── StaticPHP\Core\Exceptions\ErrorMessage\NotFound      (404)
```

| Class              | Extends           | Means                                                    |
| ------------------ | ----------------- | -------------------------------------------------------- |
| `SpErrorException` | `\ErrorException` | A PHP error, converted by the error handler. Empty body.  |
| `RouterException`  | `\Exception`      | The application is misconfigured. Empty body.             |
| `ErrorMessage`     | `\Exception`      | A response with a status code, description and format.    |

`SpErrorException` and `RouterException` add nothing to their parents - they exist so the
handlers can recognise them by type. `ErrorMessage` is the one with behaviour.

## ErrorMessage

```php
<?php

public function __construct(
    string $message = '',
    int $code = 0,
    ?string $description = null,
    ?Throwable $previous = null,
    int $httpStatusCode = 500,
    ?string $httpStatusMessage = null,
    ?string $forceOutputType = null,
    bool $showStackTrace = false,
    bool $publicDescription = false
);
```

Nine parameters, so named arguments are the sane way to build one:

```php
<?php

use StaticPHP\Core\Exceptions\ErrorMessage;

throw new ErrorMessage(
    message: 'Payment declined',
    httpStatusCode: 402,
    description: 'The card issuer refused the transaction.',
    publicDescription: true
);
```

- `$httpStatusCode` defaults to **500**, not 200. A thrown `ErrorMessage` is by definition
  not a success.
- `$httpStatusMessage` defaults to `httpStatusCodeToMessage($httpStatusCode)`.
- `$description` is the sentence shown under the title. It reaches the client only in debug
  mode, or when `$publicDescription` is true. The framework's own descriptions name
  classes, methods and paths - `Method "x" of class "Y" could not be found` - so they are
  developer notes by default, and the public sees the standard explanation for the status
  code instead.
- `$showStackTrace` only does anything when debug is on.
- `$forceOutputType` overrides whatever format `outputMessage()` was asked for.

The public properties are `$description`, `$httpStatusCode` and `$httpStatusMessage`;
`$forceOutputType`, `$showStackTrace` and `$publicDescription` are private.

### outputMessage()

```php
<?php

public function outputMessage(
    string $outputType = ErrorMessage::OUTPUT_TYPE_HTML,
    bool $includeHtmlTemplate = false
): void;
```

Prints. It is declared `: void`, so there is no string to capture.

It sends the status code with `http_response_code()` - guarded by `headers_sent()`, and
skipped when the code is 200 - then prints in one of four formats:

| Constant             | Value     | Content-Type               | Output                                        |
| -------------------- | --------- | -------------------------- | --------------------------------------------- |
| `OUTPUT_TYPE_PLAIN`  | `plain`   | `text/plain; charset=utf-8`| `code message`, description, trace             |
| `OUTPUT_TYPE_JSON`   | `json`    | `application/json; charset=utf-8` | `{"msg":{"code":..,"text":..,"description":..}}` |
| `OUTPUT_TYPE_XML`    | `xml`     | `application/xml; charset=utf-8` | `<Msg><Code/><Text/><Description/><Trace/></Msg>` |
| `OUTPUT_TYPE_HTML`   | `html`    | `text/html; charset=utf-8` | see below                                      |

Every value is escaped for its format - `htmlspecialchars()` with `ENT_XML1` for xml and
with `ENT_QUOTES` for html - because exception messages routinely carry request data. The
`Content-Type` header is guarded by `headers_sent()` too, since errors are often rendered
after output has begun.

`$includeHtmlTemplate` is the difference between a fragment and a page. False - the default
- prints `code message<br /><br />description<br /><br />trace`, suitable for dropping into
a page that already exists. True renders a full document through `ErrorPage`, and that is
what `Router::init()` passes.

### Converters

```php
<?php

public static function outputTypeFromRequestType(RequestContentType $requestType): string;
public static function httpStatusCodeToMessage(int $httpStatusCode): string;
```

`outputTypeFromRequestType()` maps the negotiated request content type onto one of the four
output types - json to json, xml to xml, text to plain, and everything else including
`NONE` to html. It does not accept `null`. See
[content type negotiation](/staticphp-core/core/router/#content-type-negotiation).

`httpStatusCodeToMessage()` is a switch over the common 2xx, 3xx, 4xx and 5xx codes
returning the reason phrase, and `'Unknown Status Code'` for anything not listed. Note that
429 is *not* in it, although `ErrorPage` has a default description for it.

### The three subclasses

```php
<?php

public function __construct(
    string $message = 'Not Found',
    ?string $description = null,
    ?Throwable $previous = null
);
```

`BadRequest`, `Forbidden` and `NotFound` all have that shape - three parameters, the
default message matching the class - and pass a fixed `httpStatusCode` of 400, 403 and 404
up to the parent. Everything else stays at the parent's defaults, so `$publicDescription`
is false and the description you pass is a developer note.

```php
<?php

use StaticPHP\Core\Exceptions\ErrorMessage\NotFound;

throw new NotFound('Not Found', "No invoice with reference {$reference}");
```

Throwing one of these rather than a `RouterException` is what keeps a crawler walking dead
urls from generating an error email per request: `Router::init()` catches `ErrorMessage`
separately and renders it without logging or reporting. The framework raises them itself
for an unresolvable url, a method that does not exist, and an untrusted or malformed `Host`
header.

## The registered handlers

`src/Core/Helpers/ErrorHandlers.php` is loaded by the bootstrap with
`Load::helper(['ErrorHandlers'], 'Core', 'staticphp')`, which defines ten global
functions. Two of them are then registered:

```php
<?php

set_error_handler('sp_error_handler', (!empty(Config::$items['debug']) ? E_ALL : E_ALL & ~E_DEPRECATED));
set_exception_handler('sp_exception_handler');
```

Everything the bootstrap does before this point runs under PHP's own handling.

### sp_error_handler()

```php
<?php

function sp_error_handler(
    int $errno,
    string $errstr,
    ?string $errfile = null,
    ?int $errline = null,
    ?array $errcontext = null
): bool;
```

Converts every PHP error into a thrown `SpErrorException`, so a notice about an undefined
array key stops the request the same way an exception does. It returns `false` when
`(error_reporting() & $errno) === 0`, which hands the error back to php rather than
swallowing it and is how `@` suppression is honoured - since PHP 8 the suppression operator
sets a non-zero mask rather than 0, so the old comparison against 0 no longer detects it.
That `false` is the only path that returns at all; everything else throws.

### sp_exception_handler()

```php
<?php

function sp_exception_handler(Throwable $exception): void;
```

The handler of last resort - it only sees what nothing else caught, which in practice means
exceptions thrown outside `Router::init()`, since `init()` catches `Throwable` itself.

1. A `RouterException` is special-cased into
   `Router::error(500, 'Internal Server Error', ...)`, carrying the exception message
   only in debug mode. `Router::error()` exits, so nothing below runs for that case.
2. `http_response_code(500)`, if headers have not been sent.
3. If `display_level` admits `error`, `sp_render_exception($exception)` is printed.
4. If `log_level` admits `error`, `sp_log_error($exception)`.
5. If `report_level` admits `error`, `sp_send_error_email($exception)`.
6. `exit(10)`.

Each threshold goes through `sp_logging_level($key)`, which reads it out of
`$config['logging']` through `sp_logging_setting()` and falls back to `Logger::ERROR` when
it is missing or not a string - dereferencing the array blind meant a missing key raised its
own error from inside the handler, replacing the failure being reported. The comparison
itself is `Logger::above('error', sp_logging_level($key))`; see
[Logger](/staticphp-core/core/logger/) for why a lower threshold is the more inclusive
one.

### The rest

| Function                        | Does                                                                        |
| ------------------------------- | --------------------------------------------------------------------------- |
| `sp_logging_level($key)`        | A `logging` threshold as a string, defaulting to `Logger::ERROR`.            |
| `sp_logging_setting($key)`      | One raw entry of `$config['logging']`, or `null`.                            |
| `sp_server($name, $default = '')` | A `$_SERVER` entry as text; a non-string entry counts as absent.           |
| `sp_render_exception($e)`       | Picks the output format and builds it.                                       |
| `sp_log_error($e)`              | `error_log()` of the plain-text formatting.                                  |
| `sp_send_error_email($e)`       | Email and webhook reporting, with de-duplication.                            |
| `sp_remove_sensitive_data($d)`  | Alias for `ErrorPage::redact()`.                                             |
| `sp_format_exception($e, $full = false, $markup = true)` | Formats an exception, optionally with the request state. |

`sp_render_exception()` commits to a format here rather than guessing further down: the cli
SAPI gets plain text; a browser gets `ErrorPage::debug()` when `$config['debug']` is exactly
`true`, and `ErrorPage::status(500, ...)` otherwise.

`sp_send_error_email()` reads four more configuration keys and calls whatever they name:

```php
<?php

$config['logging'] = [
    'display_level' => 'error',
    'log_level' => 'error',
    'report_level' => 'error',
    'report_email' => 'ops@example.com',
    'report_email_func' => 'my_send_mail',
    'report_webhook' => 'https://hooks.example.com/incoming',
    'report_webhook_func' => 'my_post_webhook',
];
```

There is no mailer in the framework. `report_email_func` is called as
`$func($email, $subject, $body, "Content-Type: text/html; charset=utf-8", 'error')` and
`report_webhook_func` as `$func($webhook, $subject, $body, 'error')`, each only when both
its address and its callable are set. The subject is `PHP ERROR: "<host>"`, or `"cli"`.

A static `$last_error` suppresses an identical formatted exception seen within 30 seconds,
so a loop failing on every iteration does not send a message per iteration.

## ErrorPage

`StaticPHP\Core\Exceptions\ErrorPage` is not an exception. It renders the two html pages.

```php
<?php

public static function status(int $httpStatusCode, ?string $httpStatusMessage = null, ?string $description = null, ?string $reference = null): string;
public static function debug(Throwable $e, int $httpStatusCode = 500, ?string $reference = null, ?string $description = null): string;
public static function report(Throwable $e): string;
public static function requestId(): string;
public static function redact(mixed $data): mixed;
public static function escape(mixed $value): string;
```

| Constant            | Value     | Meaning                                                     |
| ------------------- | --------- | ----------------------------------------------------------- |
| `SOURCE_CONTEXT`    | `8`       | Source lines shown either side of the offending one.        |
| `MAX_SOURCE_BYTES`  | `2097152` | Files larger than this are not read for excerpts.           |
| `MAX_VALUE_LENGTH`  | `4096`    | Context values longer than this are truncated.              |
| `SENSITIVE_PATTERN` | regex     | `password`, `passwd`, `pwd`, `secret`, `token`, `api key`, `authorization`, `cookie` |

`requestId()` is a short correlation id printed on both pages and included in the log line.
It prefers a value a reverse proxy already stamped - `X-Request-Id`, `X-Correlation-Id` or
`UNIQUE_ID` - as long as it matches `/^[A-Za-z0-9._-]{4,64}$/`, and otherwise generates six
random bytes as hex. It is computed once per request.

`redact()` replaces the *value* of any key matching `SENSITIVE_PATTERN` with `***`,
whatever the value's type, and recurses into nested arrays; on a bare string it replaces
matching substrings instead. `escape()` is `htmlspecialchars()` with
`ENT_QUOTES | ENT_SUBSTITUTE`, applied to everything either template prints.

`report()` is the same exception as plain text - the reference, the date, the url, then
each exception in the chain with its frames. It is what the debug page's clipboard button
copies.

## The two pages

Both live in `src/Core/Views/Errors/`, and both are plain php rather than twig, single
documents with inline css and no request to anything external. The page that renders a
broken application cannot afford to depend on the application, the asset pipeline or the
network, since any of those may be what broke.

Which one you get is decided by one thing - `Config::get('debug', false) === true` - tested
in the two places that can reach a template: `ErrorMessage::htmlPage()`, for anything
`Router::init()` caught, and `sp_render_exception()`, for anything that got past it.
Nothing else selects between them.

That `debug` is the boolean the bootstrap computed, not whatever the config file assigned.
Since 2.0 the framework makes no trust decision of its own about who may see a debug page:
`$config['debug']` forces it on and `$config['debug_check']` - the application's own
`callable(): bool` - decides for everyone else. See
[`resolveDebug()`](/staticphp-core/core/config/#resolvedebug).

### debug.php

Everything that helps explain the failure:

- the full exception chain, each entry with its class, message, code, file, line and a
  source excerpt of `SOURCE_CONTEXT` lines either side;
- every stack frame, with its own excerpt, and a flag marking frames inside `/vendor/`.
  Argument *values* are reduced to their types - they are as likely to hold a password as
  anything else, and the type is usually what explains the failure;
- eight tables of request state: Request, Headers, Query, Body, Cookies, Session, Server
  and Runtime;
- the reference id, the timestamp and the plain-text `report()` for copying.

Session data goes through a `formatSession()` global function if the application defines
one, and everything in those tables goes through `redact()`.

When the exception was wrapped - as `Router::init()` wraps everything that is not an
`ErrorMessage` - the page is rendered for `getPrevious() ?? $this`, so the interesting
trace is the original one rather than the wrapper's.

### status.php

What is left when nothing may be disclosed: the status code, a title, one sentence, and a
reference id. No message, no path, no trace, no request data.

The sentence is `$description` if the thrower published one, and otherwise a built-in
explanation per status code - 404 is "The page you were looking for does not exist, or it
has moved.", 500 is "Something went wrong on our side. The problem has been recorded." -
falling back to a generic sentence for any other 4xx or 5xx.

The reference is only printed for 5xx. A reference is worth showing when there is something
in the log to correlate it with, and a 404 is not logged.

Both pages carry a `tone` - `server`, `client`, `redirect` or `neutral`, by status code -
which selects the accent colour, and a `<meta name="robots" content="noindex, nofollow">`.

### Replacing them

```php
<?php

$config['error_pages'] = [
    'status' => APP_PATH . '/Views/Errors/status.php',
    'debug'  => null,
];
```

`templateFor()` uses a configured path only when it is a string *and* an existing file,
falling back to the shipped template otherwise - so a typo degrades to the built-in page
rather than to a second failure. A replacement receives the same variables the shipped one
documents at the top of the file, including the `$esc` closure it must run every printed
value through.

If a template itself throws, the output buffer is unwound and `ErrorPage::minimal()`
returns a bare document with just the code and the title. Nothing downstream would catch a
second exception, so the last fallback is markup that cannot fail.
