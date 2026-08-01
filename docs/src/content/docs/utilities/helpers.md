---
title: Helpers
description: The fifteen free functions in the helpers file, and how to load them.
sidebar:
    order: 8
---

`src/Utils/Helpers/Helpers.php` holds fifteen plain functions in the global namespace. It is
not a class and there is nothing to instantiate.

## They are not loaded for you

`composer.json` has **no `files` autoload section at all**:

```json
"autoload": {
    "psr-4": {
        "StaticPHP\\": "src/"
    }
}
```

`README.md` makes a point of composer's `files` autoload being eager - twig plus the symfony
polyfills it pulls in load eight files on every request whether or not a template is ever
rendered - and this package declines to add to that. Nothing in `src/` requires the helpers
file either, so on a stock installation none of these functions exist.

Load it explicitly through
[`Load::helper()`](/staticphp-core/core/load/#loading-files), naming the module and the
reserved `staticphp` project:

```php
<?php

use StaticPHP\Core\Models\Load;

Load::helper(['Helpers'], 'Utils', 'staticphp');
```

Or have the bootstrap do it on every request, which is the same call written as
configuration:

```php
<?php

$config['autoload_helpers'][] = 'staticphp/Utils/Helpers';
```

Entries in `autoload_helpers` are slash separated and read right to left, exactly like
`autoload_configs` - see
[configuration](/staticphp-core/getting-started/configuration/#loading-more-config-files).

:::caution[Loading twice is a fatal error]
`Load::helper()` uses `require`, not `require_once`, and these are function declarations. A
second load redeclares them and kills the request. Pick one of the two mechanisms above,
not both.
:::

`Fv`'s `setDecForRecord()` and `setDecOrNullForRecord()` call `fixFloat()` from this file,
so [validation](/staticphp-core/utilities/validation/#record-helpers) needs it loaded for
those two methods.

## The functions

```php
<?php

function fixFloat($input, $precision = -1);
function trimChars(&$value, $character_mask = " \t\n\r\0\x0B");
function localeNumberFormat($number, $decimals = 2);
function uuid4();
function parseQueryString($str, $delimiter = '&');
function weekRange($week, $year = null);
function monthRangeDateTime($timestamp = null);
function getIsoWeeksInYear($year);
function extractArrayByKeys($array, $keys, $required = false, $fill_missing = false);
function anyEmpty($array);
function allEmpty($array);
function tmpFilename($prefix = 'tmp_', $postfix = '');
function groupArray($array, $keys = [], $unique = false);
function validISODate($date);
function validISODateTime($datetime);
```

All fifteen, run:

```text
fixFloat('1 234,56')                               => 1234.56
fixFloat('10,31345', 2)                            => 10.31
fixFloat('abc')                                    => 0.0
trimChars($t) leaves $t as                         => 'padded'
localeNumberFormat(1234.5)                         => '1234.50'
localeNumberFormat(null)                           => '0.00'
strlen(uuid4())                                    => 36
parseQueryString('a=1&b=x%20y')                    => array (
                                                        'a' => '1',
                                                        'b' => 'x y',
                                                      )
parseQueryString('a=1;b=2', ';')                   => array (
                                                        'a' => '1',
                                                        'b' => '2',
                                                      )
parseQueryString('a=1&flag')                       => array (
                                                        'a' => '1',
                                                      )
weekRange(31, 2026)                                => array (
                                                        0 => '2026-07-27 Mon',
                                                        1 => '2026-08-02 Sun',
                                                      )
monthRangeDateTime(1785066300)                     => array (
                                                        0 => '2026-07-01 00:00:00 +00:00',
                                                        1 => '2026-07-31 23:59:59 +00:00',
                                                      )
getIsoWeeksInYear(2026)                            => 53
getIsoWeeksInYear(2025)                            => 52
extractArrayByKeys(['a'=>1,'b'=>2], ['a'])         => array (
                                                        'a' => 1,
                                                      )
extractArrayByKeys(['a'=>1], ['a','b'], true)      => false
extractArrayByKeys(['a'=>1], ['a','b'], false, null) => array (
                                                        'a' => 1,
                                                        'b' => NULL,
                                                      )
anyEmpty(['a', ''])                                => true
anyEmpty(['a', 'b'])                               => false
allEmpty(['', 0])                                  => true
allEmpty(['', 'x'])                                => false
is_file(tmpFilename('doc_', '.txt'))               => true
  ... its permissions                              => '0600'
array_map('count', groupArray($rows, 'id'))        => array (
                                                        1 => 2,
                                                        2 => 1,
                                                      )
validISODate('2026-08-01')                         => true
validISODate('nonsense 2026-08-01 more')           => true
validISODateTime('2026-08-01T13:45:00Z')           => true
validISODateTime('2026-08-01T13:45:00+03:00')      => true
validISODateTime('2026-08-01 13:45:00')            => false
```

### Numbers

`fixFloat()` replaces `,` with `.` and drops spaces before casting, which is what turns a
European-formatted number typed into a form back into a float. `$precision` of `-1` - the
default - means no rounding. Anything unparseable casts to `0.0` rather than failing.

`localeNumberFormat()` prefers the active i18n locale and only falls back to `localeconv()`
when `i18n::isInitialised()` is false. That fallback reads `LC_NUMERIC`, which nothing in the
framework sets and which does nothing at all unless the locale has been generated on the
host - which is why the capture above, with i18n not started, formats the C way. With i18n
running it delegates to [`i18n::number()`](/staticphp-core/i18n/formatting/#through-the-facade).
A `null` argument is treated as `0`.

### Strings and identifiers

`trimChars()` takes its value **by reference** and returns nothing, which is what makes it
usable as an `array_walk()` callback.

`uuid4()` builds a version 4 uuid from `random_bytes(16)`, setting the version and variant
bits by hand. `random_bytes()` is core rather than an extension, so there is nothing to
install and nothing for `composer.json` to declare, and it throws `Random\RandomException`
when the system has no usable source of randomness instead of handing back bytes that only
might be strong. `uuid4()` does not catch that, so the failure reaches the caller.

`parseQueryString()` splits on `$delimiter` and then on the first `=`, url-decoding both
sides. It is not a `parse_str()` replacement: a pair with no `=` is dropped rather than
recorded as an empty value, `a[]=1&a[]=2` gives you one key holding the last value instead of
an array, and a value containing `=` is truncated at the second one. Its reason to exist is
the `$delimiter` argument.

### Dates

`weekRange($week, $year)` returns `[startTimestamp, endTimestamp]` for an ISO week, anchored
on 4 January - the date the ISO-8601 rules guarantee is in week 1 - and walking forward from
there. `$year` defaults to the current one. The range runs Monday to Sunday, and the end is
`next sunday` at 00:00, not the last second of that day.

`monthRangeDateTime($timestamp)` returns `[$start, $end]` as `DateTime` objects, at
`00:00:00` and `23:59:59`. It accepts an int timestamp, a `DateTime`, or nothing - and its
three input paths do not share a timezone: with no argument it uses UTC explicitly, an int
goes through `"@{$timestamp}"` which is also UTC, and a `DateTime` you pass in keeps whatever
timezone it already had.

`getIsoWeeksInYear($year)` asks for ISO week 53 of that year and reports whether php agrees
it exists - 53 for a long year, 52 otherwise.

`validISODate()` and `validISODateTime()` are regex checks, not parses. `validISODate()` is
notably loose: its pattern is unanchored, so any string containing `nnnn-nn-nn` anywhere
passes, and there is no calendar check on either. `validISODateTime()` is anchored and does
require the `T`, a two-digit time, and either `Z` or a `±hh:mm` offset - so a space-separated
timestamp is rejected.

### Arrays

`extractArrayByKeys()` narrows an array to a list of keys. `$required = true` makes a missing
key abort the whole thing with `false`; otherwise `$fill_missing` supplies a placeholder,
unless it is `false`, in which case the key is simply left out. Passing something that is not
an array also returns `false`, so the `false` return is ambiguous between the two.

`anyEmpty()` and `allEmpty()` count `array_filter()` survivors, so "empty" means php's
`empty()` - `0`, `'0'`, `''`, `null` and `[]` all count.

`groupArray($array, $keys, $unique)` indexes a list of rows by one or more columns, nesting
one level per key. With `$unique = false` the leaf is a list of rows; with `$unique = true`
the leaf is the row itself, and a repeated key silently keeps the last one. The docblock
types `$array` as an `\Iterator`, and the `foreach` accepts either that or a plain array.

### Files

`tmpFilename($prefix, $postfix)` returns the path to a file it has already created, with mode
`0600`. Without a `$postfix` that is a single `tempnam()`. With one it creates the suffixed
name separately with `fopen($target, 'x')` - `O_EXCL`, so it fails rather than clobbering -
and removes the first file. It throws `RuntimeException` if either step fails.

It creates the file rather than only naming it, on purpose: a function that returns an unused
name leaves a window in which another process can put a symlink there first. The caller is
responsible for deleting it.
