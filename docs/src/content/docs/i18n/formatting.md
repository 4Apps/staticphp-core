---
title: Formatting
description: The ICU formatter behind plurals, numbers and dates, and what it needs from the host.
sidebar:
    order: 5
---

`StaticPHP\Utils\Models\Translation\Formatter` is everything that turns a translated string
into what the page shows: placeholder substitution, escaping, ICU messages, numbers,
currencies, percentages and dates.

## ext-intl is required, not suggested

`composer.json` lists it under `require`, beside `ext-mbstring` and `ext-pdo`:

```json
"require": {
    "php": ">=8.4",
    "ext-intl": "*",
    "ext-mbstring": "*",
    "ext-pdo": "*"
}
```

There is no polyfill path and no "if the extension is loaded" branch anywhere in this class.
`MessageFormatter`, `NumberFormatter` and `IntlDateFormatter` are referenced directly, so an
installation without `intl` has no working formatter at all - and composer will not install
the package there in the first place. CI installs the extension for every matrix entry,
including the no-twig job, and `docker/Dockerfile` installs `php-intl`.

Plurals go through ICU MessageFormat rather than a hand rolled `ngettext`, because the
languages this framework was written for are exactly the ones a two-form `ngettext` gets
wrong: latvian has three plural categories and russian four, and which one applies is a
property of the language, not of the call site.

## Host locales

ICU carries its own locale data, so a locale does not have to be generated on the host for
`Formatter` to use it. Verified in the project's own container, which generates only
`lv_LV.UTF-8` and `en_US.UTF-8` - the derived locales format correctly anyway:

| ICU locale | `number(1234.5, 2)` | `currency(10, 'EUR')` | `date('2026-08-01')` |
| ---------- | ------------------- | --------------------- | -------------------- |
| `lv_LV`    | `1 234,50`          | `10,00 €`             | `2026. gada 1. aug.` |
| `en_US`    | `1,234.50`          | `€10.00`              | `Aug 1, 2026`        |
| `en_LV`    | `1,234.50`          | `€10.00`              | `Aug 1, 2026`        |
| `ru_LV`    | `1 234,50`          | `10,00 €`             | `1 авг. 2026 г.`     |
| `et_EE`    | `1 234,50`          | `10,00 €`             | `1. aug 2026`        |

Of those five, only `lv_LV` and `en_US` exist as system locales in that container.

What *does* need a generated system locale is `$config['i18n']['set_locale']`. With it on,
`i18n::init()` calls `setlocale(LC_TIME, ...)` and `setlocale(LC_CTYPE, ...)` with the
locale's ICU name, and `setlocale()` silently returns `false` when the locale is not
generated - it does not throw, and nothing downstream notices. Off is the default, and the
only reason it is offered at all is third-party code reading `localeconv()` or `strftime()`.

`docker/Dockerfile` runs `locale-gen` for `lv_LV.UTF-8` and `en_US.UTF-8` and sets
`ENV LANG=lv_LV.UTF-8`, and the comment above it attributes that to the i18n suite. Going by
the table above, what the ICU formatting in that suite actually needs is `ext-intl`; the
generated locales are what `LANG` and any `setlocale()` path need.

`i18n::init()` also sets `ExtendedDateTime::$defaultLocale` to the resolved ICU locale,
because `ExtendedDateTime` otherwise reads `setlocale(LC_TIME, 0)`, which is `C` unless
something set it.

:::note[Group separators are non-breaking]
The latvian and russian thousands separator ICU emits is U+00A0, not a plain space. It looks
like a space and does not compare equal to one, which matters if you assert on formatted
output in a test.
:::

## The class

```php
<?php

public function __construct(
    private readonly string $icuLocale = 'en_US',
    private readonly bool $strict = false,
) {
}

public static function replace(string $text, array $replace): string;
public static function escape(string $text, ?string $mode): string;

public function message(string $pattern, array $arguments = []): string;
public function number(int|float $value, ?int $decimals = null): string;
public function currency(int|float $value, string $currency = 'EUR'): string;
public function percent(int|float $value, int $decimals = 0): string;
public function date(
    \DateTimeInterface|int|string $value,
    int $dateType = \IntlDateFormatter::MEDIUM,
    int $timeType = \IntlDateFormatter::NONE,
    ?string $pattern = null
): string;
```

One instance per ICU locale; `i18n` keeps a static array of them and builds each on first
use. Inside an instance, every built ICU formatter is kept too - constructing one loads a
locale bundle, which is the expensive part, so a page that formats fifty numbers pays for
it once.

## replace()

`i18n::translate()`'s `$replace` argument ends up here. It uses `strtr()`, not
`str_replace()`:

```php
<?php

Formatter::replace('a b', ['a' => 'b', 'b' => 'c']);                       // "b c"
Formatter::replace('%name_full%', ['%name%' => 'x', '%name_full%' => 'y']); // "y"
Formatter::replace('%x%', ['%x%' => null]);                                // ""
```

`str_replace()` walks the array and applies each pair to the result of the last, so the
first example returned `c c`, and a short key that is a prefix of a longer one consumed it.
`strtr()` replaces in one pass and prefers the longest matching key. `null` becomes an empty
string; everything else is cast to string.

There is no placeholder syntax of its own - the keys are whatever you write them as. The
`%name%` convention in the examples is just a convention.

## escape()

```php
<?php

Formatter::escape($text, 'html');
```

| Mode                      | What it does                                                              |
| ------------------------- | -------------------------------------------------------------------------- |
| `html`, `attr`, `input`   | `htmlspecialchars()` with `ENT_QUOTES \| ENT_SUBSTITUTE`, UTF-8. All three are the same call |
| `js`                      | `json_encode()` with `JSON_HEX_TAG`, `JSON_HEX_AMP`, `JSON_HEX_APOS`, `JSON_HEX_QUOT` and `JSON_UNESCAPED_UNICODE`, with the surrounding quotes trimmed off |
| `url`                     | `rawurlencode()`                                                          |
| `null`, or anything else  | returns the text unchanged                                                |

An unrecognised mode is silently a no-op, so a typo in the third argument of
`i18n::translate()` gets you unescaped output. There is no error for it.

For `<b> "q" (a) & ā/b`:

```text
html   &lt;b&gt; &quot;q&quot; (a) &amp; ā/b
js     <b> "q" (a) & ā\/b
url    %3Cb%3E%20%22q%22%20%28a%29%20%26%20%C4%81%2Fb
```

The js mode produces a correctly escaped **body** for a quoted literal - `json_encode`
produces a complete javascript string literal, quotes included, and trimming them leaves the
body. The escaper this replaced handled `'`, `\r` and `\n` and nothing else, so a backslash
or a `</script>` in a translation walked straight out of the literal it was meant to sit in.

## message()

```php
<?php

$formatter = new Formatter('lv_LV', true);
$formatter->message('{n, plural, zero{# failu} one{# fails} other{# faili}}', ['n' => 21]);
```

A full ICU MessageFormat pattern, with named arguments. Latvian and russian, from the same
call:

| Pattern locale | `n = 0`   | `n = 1`   | `n = 3` | `n = 5`   | `n = 21`  | `n = 22`  |
| -------------- | --------- | --------- | ------- | --------- | --------- | --------- |
| `lv_LV`        | `0 failu` | `1 fails` | -       | `5 faili` | `21 fails` | `22 faili` |
| `ru_RU` categories | -     | `one`     | `few`   | `many`    | `one`     | -          |

That is why the pattern lives in the translation rather than in the calling code: which
categories exist, and which one a number falls into, is a property of the target language.
`select` works the same way for gender and any other closed set.

Formatters are memoised per pattern within the instance, so the same pattern in a loop is
compiled once.

## Numbers, currency, percent and dates

```php
<?php

$f = new Formatter('lv_LV', true);

$f->number(1234.5);        // locale default decimals
$f->number(1234.5, 2);     // "1 234,50"
$f->currency(10, 'EUR');   // "10,00 €"
$f->percent(0.25);         // "25%" - 1.0 is one hundred percent
$f->date('2026-08-01', pattern: 'yyyy-MM-dd');  // "2026-08-01"
$f->date('2026-08-01', pattern: 'd. MMMM y');   // "1. augusts 2026"
```

`date()` accepts a `DateTimeInterface`, a unix timestamp, or a string - a string is passed
to `new DateTimeImmutable()`, so anything php parses works and anything it does not throws
from there. `$pattern` is an ICU date pattern and overrides `$dateType` and `$timeType`
entirely; note that in an ICU pattern every ascii letter is a field, so literal words have
to be quoted or they come out as garbage.

`$decimals` on `number()` sets both `MIN_FRACTION_DIGITS` and `MAX_FRACTION_DIGITS`, so it
is an exact width rather than a maximum: `number(1.5, 4)` is `1.5000`.

## Through the facade

`i18n` exposes the same formatting against the current locale, and these are the exact
`IntlDateFormatter` styles it passes:

```php
<?php

i18n::number($value, $decimals = null);
i18n::currency($value, $currency = 'EUR');
i18n::percent($value, $decimals = 0);

i18n::date($value, $pattern = null);      // MEDIUM date, NONE time
i18n::dateTime($value, $pattern = null);  // MEDIUM date, SHORT time
i18n::time($value, $pattern = null);      // NONE date, SHORT time
```

`i18n::format()` is `message()` plus the translation lookup and the escaping, and it picks
its ICU locale from the language key it was given rather than always from the current one.

None of those six wrappers requires `init()` to have run: with no locale resolved they fall
back to `en_US`. `translate()` and `format()` do require it, and throw `TranslationError`
otherwise.

## When ICU refuses

`$strict` on the constructor comes from `$config['i18n']['strict']`, with `null` following
`$config['debug']`. It decides what happens when ICU rejects something:

- **Strict on:** `TranslationError`, with the ICU error message in it.
- **Strict off:** one `i18n: ...` line to `error_log()` - only the first per instance - and a
  fallback return.

The fallback differs by call. `message()` returns the pattern itself, which is at least
readable; `date()` returns `date('c', $timestamp)`. `number()`, `currency()` and `percent()`
fall back to `number_format()` if the `NumberFormatter` could not be built.

:::caution[An unparsable locale throws before that fallback]
On PHP 8.4 with ICU 76.1, `NumberFormatter::create()` raises a `ValueError` for a locale
string it cannot parse rather than returning `null`, and `Formatter` does not catch it - so
`number()`, `currency()` and `percent()` propagate that regardless of strict mode, and the
`number_format()` fallback in the source is not the path you get.

`MessageFormatter::create()` does not behave the same way: the same unparsable locale is
accepted there and formatted against ICU's root data.

The `icuLocale` is derived from the configured country and language codes, so this only
comes up when those are not real codes. Keep them honest, as the shipped config file asks.
:::
