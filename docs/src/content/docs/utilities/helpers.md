---
title: Helpers
description: The twenty-eight free functions in the helpers file, and how to load them.
sidebar:
    order: 8
---

`src/Utils/Helpers/Helpers.php` holds twenty-eight plain functions in the global namespace.
It is not a class and there is nothing to instantiate.

## They are not loaded for you

`composer.json` has **no `files` autoload section at all**:

```json
"autoload": {
    "psr-4": {
        "StaticPHP\\": "src/"
    }
}
```

`README.md` makes a point of composer's `files` autoload being eager - every entry is
required before any application code runs, and twig plus the symfony polyfills it pulls in
account for
[seven entries, nine php files at runtime](/staticphp-core/guides/without-twig/#eager-means-before-any-of-your-code-runs) -
and this package declines to add to that. Nothing in `src/` requires the helpers file
either, so on a stock installation none of these functions exist.

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

function isBlank($input);
function isBlankOrNull($input);
function valueOrNull($input);
function fixFloat($input, $precision = -1);
function trimChars(&$value, $key = null, $character_mask = " \t\n\r\0\x0B"): void;
function localeNumberFormat($number, $decimals = 2);
function cNumberFormat($number, $decimals = 0, $dec_point = '.', $thousands_sep = ' ');
function localeDateFormat($pattern, $when = null, $locale = null, $timezone = null);
function uuid4();
function parseQueryString($str, $delimiter = '&');
function weekRange($week, $year = null): array;
function weekOfMonth($when = null);
function monthRangeDateTime($timestamp = null): array;
function yearRangeDateTime($year = null): array;
function sqlTimestampToDatetime(?string $timestamp = null): ?\StaticPHP\Utils\Models\ExtendedDateTime;
function getIsoWeeksInYear($year): int;
function extractArrayByKeys($array, $keys, $required = false, $fill_missing = false, $callback = null);
function anyEmpty($array);
function allEmpty($array);
function isArrayKeyBlank($array, $key);
function isArrayKeyBlankOrNull($array, $key);
function padEmptyArrayForDropdown($array, $key = '', $value = '');
function tmpFilename($prefix = 'tmp_', $postfix = '');
function uploadCodeToMessage($code);
function groupArray($array, $keys = [], $unique = false, $keys_callback = null, $values_callback = null);
function simpleArray($array, $keys, $values, $keys_callback = null, $values_callback = null, $skip_missing = true): array;
function validISODate($date);
function validISODateTime($datetime);
```

All twenty-eight, run - with `$rows` set to
`[['id' => 1, 'name' => 'One'], ['id' => 1, 'name' => 'Uno'], ['id' => 2, 'name' => 'Two']]`,
i18n not started, and the default timezone left at the container's `Europe/Riga`:

```text
isBlank('   ')                                     => true
isBlank('0')                                       => false
isBlank(null)                                      => false
isBlankOrNull(null)                                => true
isBlankOrNull(0)                                   => false
valueOrNull('')                                    => NULL
valueOrNull('0')                                   => NULL
valueOrNull('x')                                   => 'x'
fixFloat('1 234,56')                               => 1234.56
fixFloat('10,31345', 2)                            => 10.31
fixFloat('abc')                                    => 0.0
trimChars($t) leaves $t as                         => 'padded'
trimChars($t, null, 'x') leaves $t as              => 'hi'
trimChars($t, 'x') leaves $t as                    => 'xxhixx'
array_walk($a, 'trimChars') leaves $a as           => array (
                                                        0 => 'a',
                                                        1 => 'b',
                                                      )
localeNumberFormat(1234.5)                         => '1234.50'
localeNumberFormat(null)                           => '0.00'
cNumberFormat(1234.5, 2)                           => '1 234.50'
cNumberFormat(5.10, -2)                            => '5.1'
cNumberFormat(5.10, 2)                             => '5.10'
cNumberFormat(1234.5, 2, ',', '.')                 => '1.234,50'
localeDateFormat('dd.MM.yyyy', 1785581100, 'lv_LV')=> '01.08.2026'
localeDateFormat('MMMM y', 1785581100, 'lv_LV')    => 'augusts 2026'
localeDateFormat('%d.%m.%Y', 1785581100, 'lv_LV')  => '%1.%45.%2026'
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
weekOfMonth(strtotime('2026-08-01'))               => 1
weekOfMonth(strtotime('2026-08-20'))               => 4
weekOfMonth(strtotime('2026-01-10'))               => 2
monthRangeDateTime(1785066300)                     => array (
                                                        0 => '2026-07-01 00:00:00 +00:00',
                                                        1 => '2026-07-31 23:59:59 +00:00',
                                                      )
yearRangeDateTime(2026)                            => array (
                                                        0 => '2026-01-01 00:00:00 +02:00',
                                                        1 => '2026-12-31 23:59:59 +02:00',
                                                      )
sqlTimestampToDatetime('2026-08-01 13:45:00')      => '2026-08-01 13:45:00 +03:00'
sqlTimestampToDatetime(null)                       => NULL
getIsoWeeksInYear(2026)                            => 53
getIsoWeeksInYear(2025)                            => 52
extractArrayByKeys(['a'=>1,'b'=>2], ['a'])         => array (
                                                        'a' => 1,
                                                      )
extractArrayByKeys(['a'=>1], ['a','b'], true)      => false
extractArrayByKeys(['a'=>1], ['a','b'], false, null)=> array (
                                                        'a' => 1,
                                                        'b' => NULL,
                                                      )
extractArrayByKeys(['a'=>' x '], ['a'], false, false, 'trim')=> array (
                                                        'a' => 'x',
                                                      )
anyEmpty(['a', ''])                                => true
anyEmpty(['a', 'b'])                               => false
allEmpty(['', 0])                                  => true
allEmpty(['', 'x'])                                => false
isArrayKeyBlank(['a' => ''], 'a')                  => true
isArrayKeyBlank(['a' => ''], 'b')                  => false
isArrayKeyBlank(['a' => null], 'a')                => false
isArrayKeyBlankOrNull(['a' => null], 'a')          => true
padEmptyArrayForDropdown([7 => 'Anna'], '', '-')   => array (
                                                        '' => '-',
                                                        7 => 'Anna',
                                                      )
is_file(tmpFilename('doc_', '.txt'))               => true
  ... its permissions                              => '0600'
uploadCodeToMessage(UPLOAD_ERR_OK)                 => 'The file was uploaded successfully'
uploadCodeToMessage(UPLOAD_ERR_NO_FILE)            => 'No file was uploaded'
uploadCodeToMessage(99)                            => 'Unknown upload error'
array_map('count', groupArray($rows, 'id'))        => array (
                                                        1 => 2,
                                                        2 => 1,
                                                      )
groupArray($rows, 'id', true, null, fn($r) => $r['name'])=> array (
                                                        1 => 'Uno',
                                                        2 => 'Two',
                                                      )
simpleArray($rows, 'id', 'name')                   => array (
                                                        1 => 'Uno',
                                                        2 => 'Two',
                                                      )
simpleArray($rows, null, 'name')                   => array (
                                                        0 => 'One',
                                                        1 => 'Uno',
                                                        2 => 'Two',
                                                      )
simpleArray($rows, 'id', 'missing')                => array (
                                                      )
validISODate('2026-08-01')                         => true
validISODate('nonsense 2026-08-01 more')           => true
validISODateTime('2026-08-01T13:45:00Z')           => true
validISODateTime('2026-08-01T13:45:00+03:00')      => true
validISODateTime('2026-08-01 13:45:00')            => false
```

### Blank and empty

Five of these exist to keep "the user submitted nothing" apart from "the user submitted a
zero", which php's own `empty()` cannot do.

`isBlank()` is true only for a string that trims to nothing. `null`, `0`, `'0'` and `[]` are
not blank. `isBlankOrNull()` is the same test with `null` added.

`valueOrNull()` is the odd one out: it is a plain `empty()` check, so it turns `'0'`, `0` and
`[]` into `null` as well as `''`. Its job is the last step before a nullable column, where an
empty form field would otherwise be stored as an empty string - but a legitimate zero goes
the same way, so do not reach for it on a numeric column.

`isArrayKeyBlank($array, $key)` and `isArrayKeyBlankOrNull()` add the presence check on top:
a key that is absent is **not** blank. That is the distinction a partial update needs - "the
field was cleared" against "the field was not submitted".

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

`cNumberFormat()` is the opposite choice: no locale anywhere, both separators passed in, and
a default of `.` and a space. Its one trick is a **negative** `$decimals`, which means "at
most this many": the number is rounded to that many places and the trailing zeros are then
dropped, so `5.10` with `-2` prints as `5.1` and with `2` as `5.10`. Quantities read better
that way, money does not - pass a positive value for money.

### Strings and identifiers

`trimChars()` takes its value **by reference** and returns nothing, which is what makes it
usable as an `array_walk()` callback.

:::caution[The character mask is the third parameter, not the second]
`array_walk()` calls its callback as `($value, $key)`, so `trimChars()` has to keep the
second slot for the key it never uses:

```php
<?php

function trimChars(&$value, $key = null, $character_mask = " \t\n\r\0\x0B"): void;
```

A call written as `trimChars($v, 'x')` therefore puts the mask into `$key`, and the value is
trimmed with the default whitespace mask instead - no error, no warning, as the capture above
shows. Pass `null` for the key: `trimChars($v, null, 'x')`.
:::

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

`localeDateFormat($pattern, $when, $locale, $timezone)` is the replacement for `strftime()`,
which php deprecated in 8.1. The catch is in the first argument: `$pattern` is an **ICU**
pattern, not a set of `%` codes. `dd.MM.yyyy`, not `%d.%m.%Y` - and a `%` pattern is not
rejected, it is formatted literally, which is what the `'%d.%m.%Y'` line of the capture above
shows. The full syntax is in
[ICU's date and time reference](https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax).

`$when` takes a timestamp, any `DateTimeInterface`, or nothing for the current time.
`$locale` defaults to
`ExtendedDateTime::$defaultLocale` - which `i18n::init()` sets - then to
`setlocale(LC_TIME, '0')`, then to `Locale::getDefault()`, with any `.UTF-8` suffix stripped
because ICU wants `lv_LV` rather than `lv_LV.UTF-8`. `$timezone` defaults to
`date_default_timezone_get()` and is handed to the formatter rather than applied to the date,
because an `@` timestamp carries no zone of its own. The formatting is `ext-intl`'s, which
this package requires.

`weekRange($week, $year)` returns `[startTimestamp, endTimestamp]` for an ISO week, anchored
on 4 January - the date the ISO-8601 rules guarantee is in week 1 - and walking forward from
there. `$year` defaults to the current one. The range runs Monday to Sunday, and the end is
`next sunday` at 00:00, not the last second of that day.

`monthRangeDateTime($timestamp)` returns `[$start, $end]` as `DateTime` objects, at
`00:00:00` and `23:59:59`. It accepts an int timestamp, a `DateTime`, or nothing - and its
three input paths do not share a timezone: with no argument it uses UTC explicitly, an int
goes through `"@{$timestamp}"` which is also UTC, and a `DateTime` you pass in keeps whatever
timezone it already had.

`yearRangeDateTime($year)` is the year-wide equivalent, `$year` defaulting to the current
one - but it returns [`ExtendedDateTime`](/staticphp-core/utilities/dates/) objects rather
than `DateTime`, and it builds them in the **default** timezone rather than in UTC. That is
why it shows `+02:00` and `+03:00` in the capture above, on a container set to `Europe/Riga`,
while `monthRangeDateTime()` a few lines up shows `+00:00`. The two are not interchangeable.

`weekOfMonth($when)` answers which ISO week of its own month a date falls in, counting from
`1`: it is the date's `W` minus the `W` of the first of that month, plus one. The first days
of January can still carry the previous year's week 52 or 53, which would make that
difference negative - in that case the raw week number is used instead of the difference, so
the answer for those few days is not meaningful.

`sqlTimestampToDatetime($timestamp)` turns a timestamp string out of the database into an
`ExtendedDateTime`, and passes `null` - and an empty string - straight through as `null`. It
is the one to use on a nullable datetime column, where constructing the object directly
would quietly give you "now".

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
an array also returns `false`, so the `false` return is ambiguous between the two. The fifth
parameter, `$callback`, is applied to each value that was actually found - not to the
`$fill_missing` placeholders.

`anyEmpty()` and `allEmpty()` count `array_filter()` survivors, so "empty" means php's
`empty()` - `0`, `'0'`, `''`, `null` and `[]` all count.

`groupArray($array, $keys, $unique)` indexes a list of rows by one or more columns, nesting
one level per key. With `$unique = false` the leaf is a list of rows; with `$unique = true`
the leaf is the row itself, and a repeated key silently keeps the last one. `$array` is typed
`iterable`, so a generator works as well as a plain array.

Its last two parameters replace the two things the plain form cannot express.
`$keys_callback` is called as `($key, $item)` and returns the value to group under, which is
how you group by something that is not a column - a date's month, a computed bucket.
`$values_callback` is called as `($item)` and returns what to store in the leaf, so the rows
can be reduced to one field on the way in rather than mapped over afterwards.

`simpleArray($array, $keys, $values, ...)` flattens a list of rows into the `key => value`
shape a dropdown wants. `$keys` names the column the key comes from, or `null` to keep the
row's existing key; `$values` names the column the value comes from. It takes the same two
callbacks, with one addition: a `$values_callback` that returns `null` **skips** the row, so
it doubles as a filter. A row missing the `$values` column is skipped silently unless
`$skip_missing` is false, which turns it into
`InvalidArgumentException: Missing column: ...`.

`padEmptyArrayForDropdown($array, $key, $value)` prepends one entry, the placeholder row a
select needs. It is a union rather than an `array_merge()`, on purpose: merge renumbers
integer keys, which would turn an `id => label` list into a `0..n` list and quietly post the
wrong id back.

### Uploads

`uploadCodeToMessage($code)` maps a `$_FILES` entry's `error` code onto readable english -
one arm per `UPLOAD_ERR_*` constant, and `Unknown upload error` for anything else. The
strings are not translated and not passed through the i18n catalogue.

### Files

`tmpFilename($prefix, $postfix)` returns the path to a file it has already created, with mode
`0600`. Without a `$postfix` that is a single `tempnam()`. With one it creates the suffixed
name separately with `fopen($target, 'x')` - `O_EXCL`, so it fails rather than clobbering -
and removes the first file. It throws `RuntimeException` if either step fails.

It creates the file rather than only naming it, on purpose: a function that returns an unused
name leaves a window in which another process can put a symlink there first. The caller is
responsible for deleting it.
