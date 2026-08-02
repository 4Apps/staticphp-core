---
title: Dates
description: What ExtendedDateTime adds to php's DateTime, and the ICU formatting it depends on.
sidebar:
    order: 5
---

`StaticPHP\Utils\Models\ExtendedDateTime` bolts two things onto php's date handling: eight
shortcuts for the boundaries of a day, week or month, and four locale-aware formatters
backed by ICU.

## It extends DateTime, not DateTimeImmutable

```php
<?php

class ExtendedDateTime extends \DateTime
```

That is the first thing to know about it, because every one of the eight boundary methods
calls `$this->modify()` and returns nothing:

```text
$d instanceof DateTime             => true
$d instanceof DateTimeImmutable    => false
$d->format('c')                    => '2026-08-01T13:45:00+03:00'
$d->startOfTheDay() returns        => NULL
$d->format('c') afterwards         => '2026-08-01T00:00:00+03:00'
```

So `$d->startOfTheDay()` changes `$d`. It does not return a new instance, and there is no
value to assign - `$new = $d->startOfTheDay()` gets you `null`. If you need the original
afterwards, `clone` it first.

`DateTimeImmutable`'s `modify()` returns a new object and leaves the receiver alone, which
is the opposite convention. Nothing in this class is immutable, and it is not interchangeable
with `DateTimeImmutable` - though both satisfy `DateTimeInterface`, so it can be handed to
anything that accepts one, including
[the i18n formatter's `date()`](/staticphp-core/i18n/formatting/#numbers-currency-percent-and-dates).

## Boundary methods

```php
<?php

public function previousMonth(): void;
public function nextMonth(): void;
public function startOfTheMonth(): void;
public function endOfTheMonth(): void;
public function startOfTheWeek(): void;
public function endOfTheWeek(): void;
public function startOfTheDay(): void;
public function endOfTheDay(): void;

public static function startOfTheMonthFromTimestamp(int $unixTime): int;
public static function endOfTheMonthFromTimestamp(int $unixTime): int;
```

Each is a single `modify()` with a relative-format string. Run from one instant:

```text
from 2026-08-01 13:45:00 (a Saturday), Europe/Riga

  previousMonth()     2026-07-31 13:45:00 Fri
  nextMonth()         2026-09-01 13:45:00 Tue
  startOfTheMonth()   2026-08-01 00:00:00 Sat
  endOfTheMonth()     2026-08-31 23:59:59 Mon
  startOfTheWeek()    2026-07-27 00:00:00 Mon
  endOfTheWeek()      2026-08-02 23:59:59 Sun
  startOfTheDay()     2026-08-01 00:00:00 Sat
  endOfTheDay()       2026-08-01 23:59:59 Sat

  $ts = 1785581100   (2026-08-01 13:45:00 Europe/Riga, which is 10:45 UTC)
  startOfTheMonthFromTimestamp($ts)   1785542400  = 2026-08-01 00:00:00 UTC
  endOfTheMonthFromTimestamp($ts)     1788220799  = 2026-08-31 23:59:59 UTC
```

Two of those do not mean quite what their names suggest:

-   **`previousMonth()`** is `last day of -1 month` and **`nextMonth()`** is
    `first day of +1 month`. They land on a month boundary and keep the time of day, so
    neither is "the same date one month over". They are the pair you want for stepping a
    month-range cursor, not for date arithmetic.
-   **The week runs Monday to Sunday.** `startOfTheWeek()` is `this week 00:00:00` and
    `endOfTheWeek()` is `sunday this week 23:59:59`, which is php's ISO-8601 week, not a
    Sunday-first one.

`startOfTheMonth()`, `endOfTheMonth()`, `startOfTheDay()` and `endOfTheDay()` do exactly what
they say, ending at `23:59:59` rather than at the next midnight - a range built from them
excludes the last second of the day.

:::caution[The two static helpers work in UTC]
`startOfTheMonthFromTimestamp()` and `endOfTheMonthFromTimestamp()` build the instance from
`"@{$unixTime}"`. Php ignores the timezone argument for `@` formats and uses UTC, so the
month boundary they return is the UTC one regardless of `date_default_timezone_set()`. The
capture above shows a Riga-local timestamp coming back with UTC boundaries.
:::

## Formatting

```php
<?php

public function formatFullDateTime(): string;
public function formatDateTime(): string;
public function formatDate(): string;
public function formatTime(): string;
```

Each maps to an `IntlDateFormatter` with a fixed pair of styles, optionally overridden by a
pattern held in a static property:

| Method                  | Date style | Time style | Pattern property        |
| ----------------------- | ---------- | ---------- | ----------------------- |
| `formatFullDateTime()`  | `FULL`     | `FULL`     | `$fullDateTimeFormat`   |
| `formatDateTime()`      | `SHORT`    | `SHORT`    | `$dateTimeFormat`       |
| `formatDate()`          | `SHORT`    | `NONE`     | `$dateFormat`           |
| `formatTime()`          | `NONE`     | `SHORT`    | `$timeFormat`           |

All four pattern properties default to `null`, which leaves the styles in charge. Set one and
it wins - it is an ICU date pattern, so every ascii letter in it is a field and literal words
have to be quoted.

```text
for 2026-08-01 13:45:00, Europe/Riga

  locale  formatDate()  formatTime()  formatDateTime()      formatFullDateTime()
  en_US   8/1/26        1:45 PM       8/1/26, 1:45 PM       Saturday, August 1, 2026 at 1:45:00 PM Eastern European Summer Time
  lv_LV   01.08.26      13:45         01.08.26 13:45        sestdiena, 2026. gada 1. augusts 13:45:00 Austrumeiropas vasaras laiks
  ru_RU   01.08.2026    13:45         01.08.2026, 13:45     суббота, 1 августа 2026 г. в 13:45:00 Восточная Европа, летнее время

  with $dateFormat = 'yyyy-MM-dd', lv_LV: formatDate() => 2026-08-01
```

Note that these are the framework's own `SHORT`/`FULL` choices; the values above come from
ICU's data for each locale, not from anything in this package.

### Which locale

```php
<?php

public static string|null $defaultLocale = null;
```

The constructor uses `self::$defaultLocale`, falling back to `setlocale(LC_TIME, 0)`, and
then takes everything before the first `.` - so `lv_LV.UTF-8` becomes `lv_LV`.

`i18n::init()` assigns `$defaultLocale` from the resolved ICU locale. Without it,
`setlocale(LC_TIME, 0)` is `C` unless something in the application set it, and `C` is not a
useful formatting locale. See [locales](/staticphp-core/i18n/locales/).

The four pattern properties and `$defaultLocale` are static, so they are global to the
process - there is no per-instance override.

### ext-intl

`IntlDateFormatter` is referenced directly, with no fallback branch. `composer.json` lists
`ext-intl` under `require`, so composer will not install the package without it, and the
[formatting page](/staticphp-core/i18n/formatting/#ext-intl-is-required-not-suggested) covers
what that dependency does and does not need from the host. ICU carries its own locale data,
so the locales above do not have to be generated on the machine.

Building an `IntlDateFormatter` loads a locale bundle, which is the expensive part, so the
class builds each of the four lazily and keeps it for the life of the instance. The source
puts the first at roughly 700 microseconds and each subsequent one at 45 - which is why
eagerly building all four in the constructor was worth removing.

## The timezone, and the parsing fallback

```php
<?php

public function __construct(string $datetime = 'now', ?string $timeZoneString = null);
```

`$timeZoneString` is a string, not a `DateTimeZone`, and defaults to
`date_default_timezone_get()`.

If `DateTime`'s own parser rejects `$datetime`, the constructor does not give up: it catches
the exception, hands the string to the `dateTime` formatter's `parse()` and rebuilds from the
resulting timestamp. That is the one path which builds a formatter at construction time, and
it makes a short locale-formatted string parseable:

```text
default timezone Europe/Riga, $defaultLocale = 'lv_LV'

  new ExtendedDateTime('2026-08-01 13:45:00') => 2026-08-01T13:45:00+03:00
  new ExtendedDateTime('01.08.2026')         => 2026-08-01T00:00:00+03:00
  new ExtendedDateTime('01.08.26 13:45')     => 2026-08-01T10:45:00+00:00
  new ExtendedDateTime('not a date at all')  => DateMalformedStringException: Failed to parse time string (@) at position 0 (@): Unexpected character
```

Two consequences. The rebuilt instance goes through `"@{$timestamp}"`, so like the two static
helpers it lands in UTC rather than in the requested timezone - the third row is the same
instant as the first, expressed differently. And a string neither parser accepts fails inside
the fallback, so the exception you see complains about `@` rather than about what you passed.
