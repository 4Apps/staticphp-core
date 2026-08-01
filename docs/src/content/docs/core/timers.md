---
title: Timers
description: Measuring elapsed time inside a request and reporting it through the logger.
sidebar:
    order: 8
---

`StaticPHP\Core\Models\Timers` measures wall-clock time inside a request. It holds two
protected static arrays - a stack of started timers and a map of finished ones - and feeds
the results to [the logger](/staticphp-core/core/logger/) at the end.

```php
<?php

namespace StaticPHP\Core\Models;

class Timers
{
    public static function startTimer();
    public static function stopTimer($name, $returnSeconds = false);
    public static function markTime($name);
    public static function logTimers();
}
```

## The global start time

`markTime()` and `logTimers()` both begin with `global $microtime`. That variable is set by
the first statement of `src/Core/Helpers/Bootstrap.php`:

```php
<?php

$microtime = microtime(true);
```

which is why the front controller has to `require StaticPHP\Core\Bootstrap::FILE` at global
scope rather than calling a method that does it. Required from inside a function, the
assignment would land in that function's scope and both methods would measure from `null`.
See [bootstrap](/staticphp-core/core/bootstrap/).

## startTimer() and stopTimer()

```php
<?php

Timers::startTimer();
$rows = $db->query($sql)->fetchAll();
Timers::stopTimer('report query');
```

`startTimer()` pushes `microtime(true)` onto a stack and takes no name.
`stopTimer($name)` pops the most recent entry, subtracts it from the current time, rounds
to five decimal places, stores the result under `$name` and returns it.

The name belongs to the stop, not the start, so **timers nest but do not interleave**. Two
running timers have to be stopped innermost first; stopping them in the order they were
started attributes the outer duration to the inner name.

```php
<?php

Timers::startTimer();       // outer
Timers::startTimer();       // inner
Timers::stopTimer('inner'); // pops the inner start - correct
Timers::stopTimer('outer'); // pops the outer start - correct
```

The value is in **seconds**, despite the docblock in the source saying microseconds.
`$returnSeconds` is accepted and never read; passing it changes nothing.

Stopping without a matching start pops from an empty stack, which yields `null` for the
start time and produces a duration equal to the current unix timestamp. There is no guard
against it.

Reusing a name overwrites the earlier entry, so a timer inside a loop reports the last
iteration rather than the total.

## markTime()

```php
<?php

Timers::markTime('after auth');
```

Records the time elapsed since `$microtime` - since the very start of the request, not
since any `startTimer()` - under the key `*{$name}`, and returns it. The leading `*` is what
distinguishes a mark from a stopwatch reading in the output.

Marks are for answering "how far into the request are we here"; `startTimer()` and
`stopTimer()` are for "how long did this one thing take".

## logTimers()

```php
<?php

public static function logTimers();
```

Called by `Logger::debugOutput()`, so in normal use you never call it yourself. It logs, at
`info` level:

1. `Total execution time: <seconds> seconds;` measured from `$microtime`.
2. `Memory used: <n> MB;` from `memory_get_usage()`, rounded to four decimals.
3. One line per finished timer, formatted `[<seconds>s] <name>`.

The finished timers are sorted with `krsort()` before being emitted - descending by name,
not by duration and not by the order they ran. Since `*` sorts below every letter, that
puts the marks after the named stopwatch entries and reverse-alphabetises both groups.

## From a template

The bootstrap registers three twig functions that forward straight to this class:
`startTimer()`, `stopTimer(name)` and `markTime(name)`. They exist so a template can
measure its own rendering:

```twig
{{ startTimer() }}
{% include 'Site/Views/expensive-table.twig' %}
{{ stopTimer('expensive table') }}
```

All three twig wrappers are closures that call the method and return nothing, so
`{{ stopTimer('expensive table') }}` records the duration and prints an empty string - the
number only appears in the `debugOutput()` block. The php methods themselves do return the
float.
