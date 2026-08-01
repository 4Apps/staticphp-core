---
title: Logger
description: In-request log collection, the level constants, and how thresholds are compared.
sidebar:
    order: 7
---

`StaticPHP\Core\Models\Logger` collects messages in memory for the duration of a request
and renders them as a debug block at the end. It does not write to disk, does not open a
socket and has no handler or formatter abstraction - `Logger::$logs` is a protected array
that grows until the process exits.

It is PSR-3 shaped without being PSR-3: the eight level methods have the familiar names and
`(string $message, array $context = [])` signatures, but they are static, there is no
interface, and `$context` is not interpolated into the message.

```php
<?php

namespace StaticPHP\Core\Models;

class Logger
{
    public const ERROR_LEVELS = [...];

    public const NONE = 'none';
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    public static function contains(string $errorLevel1, string $errorLevel2): bool;

    public static function emergency(string $message, array $context = []): void;
    public static function alert(string $message, array $context = []): void;
    public static function critical(string $message, array $context = []): void;
    public static function error(string $message, array $context = []): void;
    public static function warning(string $message, array $context = []): void;
    public static function notice(string $message, array $context = []): void;
    public static function info(string $message, array $context = []): void;
    public static function debug(string $message, array $context = []): void;
    public static function log(string $level, $message, array $context = []): void;

    public static function debugOutput(): string;
}
```

## Levels

`Logger::ERROR_LEVELS` maps each level name to a number. The scale runs **downwards** -
more severe means a larger number, and `none` sits above everything:

| Constant     | Name          | Value |
| ------------ | ------------- | ----- |
| `NONE`       | `none`        | 1000  |
| `EMERGENCY`  | `emergency`   | 800   |
| `ALERT`      | `alert`       | 700   |
| `CRITICAL`   | `critical`    | 600   |
| `ERROR`      | `error`       | 500   |
| `WARNING`    | `warning`     | 400   |
| `NOTICE`     | `notice`      | 300   |
| `INFO`       | `info`        | 200   |
| `DEBUG`      | `debug`       | 100   |

The eight level methods all delegate to `log()`, which appends
`['level' => ..., 'message' => ..., 'context' => ...]` to the array and does nothing else -
no threshold is applied at write time. Every message logged during a request is retained;
the levels only matter when something reads them back.

## contains()

```php
<?php

public static function contains(string $errorLevel1, string $errorLevel2): bool;
```

Returns `ERROR_LEVELS[$errorLevel1] <= ERROR_LEVELS[$errorLevel2]`. Read it as "a threshold
of `$errorLevel1` includes messages of severity `$errorLevel2`", which is how the error
handlers use it:

```php
<?php

if (Logger::contains(sp_logging_level('log_level'), 'error')) {
    sp_log_error($e);
}
```

Because the scale is inverted, a *lower* configured threshold is the more inclusive one.
`'debug'` (100) includes errors; `'error'` (500) includes errors exactly; `'emergency'`
(800) does not - it admits nothing below emergency. `'none'` (1000) admits nothing at all,
which is what makes it the off switch.

An unknown level name on either side returns `false` rather than raising. That is
deliberate: a mistyped level in the configuration used to produce a `TypeError` from inside
the error handler, which replaced the failure being reported. A name the class does not
know means "do not log at this level".

The three thresholds the error handlers consult - `$config['logging']['display_level']`,
`['log_level']` and `['report_level']` - are read through `sp_logging_level()`, which falls
back to `Logger::ERROR` for a missing or non-string value. See
[errors](/staticphp-core/core/errors/).

## debugOutput()

```php
<?php

public static function debugOutput(): string;
```

Calls `Timers::logTimers()` first, which appends the execution time, the memory used and
every recorded timer as `info` entries, and then renders every collected message as a line
of html:

```html
<span class="text-info">INFO: </span>Total execution time: 0.00412 seconds;
```

The class on the span is `danger` for emergency, alert and critical; `warning` for error
and warning; `info` for notice, info and debug - bootstrap-style utility class names, so
the block picks up whatever the surrounding stylesheet defines. A non-empty `$context` is
appended as `[a,b,c]`, imploded with `,`, so context values need to be strings or things
that cast cleanly to one.

Each line ends with `\n` and nothing wraps the block, so a template decides where it goes:

```twig
{% if config.debug %}
    <pre class="debug">{{ debugOutput()|raw }}</pre>
{% endif %}
```

The bootstrap registers `debugOutput` as a twig function when the view engine is built. In
a plain php view, call `Logger::debugOutput()` directly.

Calling it twice calls `logTimers()` twice, which appends a second set of timing entries to
the same array - so the second call prints everything from the first plus the new lines.
Render it once, at the end.

## An example

```php
<?php

use StaticPHP\Core\Models\Logger;

Logger::info('Cache miss for report', ['monthly', '2026-07']);

try {
    $db->query($sql);
} catch (\PDOException $e) {
    Logger::error('Report query failed: ' . $e->getMessage());
}
```

Neither line goes anywhere on its own. They appear when `debugOutput()` is rendered, which
in practice means when debug mode is on and the layout includes the block. For a message
that has to survive the request, use `error_log()`, or throw - the exception handler routes
through `sp_log_error()` and `sp_send_error_email()`.
