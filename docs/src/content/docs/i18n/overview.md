---
title: Overview
description: How locale negotiation, the string catalog and the ICU formatter fit together.
sidebar:
    order: 1
---

`StaticPHP\Utils\Models\i18n` is the facade for the whole translation layer. Everything on
it is static, and it delegates to a handful of small classes under
`StaticPHP\Utils\Models\Translation\`.

The design decision that shapes the rest: **the source text is the key.** There is no
`nav.header.login`. A template says `_('Log in')`, and `Log in` is what the database stores
the translations against. A string nobody has translated yet renders as the english it was
written as, with a marker on it, rather than as an identifier.

## The three moving parts

```text
Accept-Language / url prefix
        |
   Negotiator + Locales  ->  Locale        one country and language pairing
        |
      Catalog  ->  Store   ->  database    the string table for that locale
        |
    Formatter  ->  ICU                     plurals, numbers, dates
```

1. **Negotiation picks a locale.** `Locales` holds every configured country and language
   pairing; `Negotiator` reads `Accept-Language` when the url carries no language prefix of
   its own. The result is one immutable `Locale`. See
   [locales and negotiation](/staticphp-core/i18n/locales/).
2. **The store loads a catalog.** `Store` is every database call the layer makes; `Catalog`
   is the warmed string table for one language, memoised per request and cached between
   them. See [catalogs](/staticphp-core/i18n/catalogs/).
3. **The formatter renders.** `Formatter` wraps `MessageFormatter`, `NumberFormatter` and
   `IntlDateFormatter`. See [formatting](/staticphp-core/i18n/formatting/).

`Scanner` and the `staticphp i18n` command sit beside all three, comparing the source tree
against the database. See [extracting strings](/staticphp-core/i18n/extracting-strings/).

## Configuration

The shipped defaults live in `src/Utils/Config/i18n.php` and populate `$config['i18n']`.
[Configuration](/staticphp-core/getting-started/configuration/#i18n) covers how to load it;
each key is explained here on the page for the part that reads it.

| Key                    | Read by                                        |
| ---------------------- | ---------------------------------------------- |
| `available`            | `Locales::fromConfig()` - countries, languages, optional `locale` override |
| `url_format`           | `Locales::prefix()` - `{{country}}` and `{{language}}` |
| `redirect`             | `i18n::init()`, on a request with no prefix    |
| `negotiate`            | `i18n::init()`, to pick the redirect target    |
| `fallback`             | `i18n::translate()` and `i18n::format()`       |
| `auto_register`        | key registration on first sight                |
| `missing_suffix`       | the marker appended to an untranslated string  |
| `strict`               | `Store` and `Formatter`; `null` follows `$config['debug']` |
| `set_locale`           | `i18n::init()`, calls `setlocale()`            |
| `cache`, `cache_prefix`, `cache_subdir`, `cache_ttl` | the `Catalog` that `i18n::init()` constructs |
| `db_config`, `db_scheme`, `tables` | the `Store` that `i18n::init()` constructs |

One thing the file does beyond assigning keys: it derives a url prefix for every configured
pairing and **prepends** them to `$config['url_prefixes']`, so `/lv-en/some/page` is
recognised by the router as a prefix instead of as the first controller segment.

## Starting it

```php
<?php

use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\i18n;

Config::load(['i18n'], 'Utils', 'staticphp');
i18n::init();
```

`init()` in order: reads `Config::$items['i18n']`, builds a `Locales` from it, resolves the
request to one `Locale`, points every static at it, constructs the `Store` and `Catalog`,
optionally calls `setlocale()`, sets `ExtendedDateTime::$defaultLocale`, then loads the
string table for the resolved language.

It also accepts an explicit pairing, which is what the cli and the tests use:

```php
<?php

i18n::init('lv', 'en');
```

An explicit pairing that is not configured throws
`StaticPHP\Utils\Models\Translation\TranslationError`.

:::caution[Load the config yourself]
`init()` tries to load the defaults for you if `$config['i18n']` is empty, but it spells the
call `Config::load(['i18n'], 'Utils', 'System')`. `System` is neither the reserved
`staticphp` project name nor, by default, a key of `$config['module_paths']`, so that path
raises `InvalidArgumentException: Unknown module path "System"` rather than loading
anything. Confirmed by running it against the shipped source on PHP 8.4.

Load the configuration before calling `init()` - through
`$config['autoload_configs'][] = 'staticphp/Utils/i18n'`, through your own `Config/i18n.php`,
or with the explicit `Config::load(['i18n'], 'Utils', 'staticphp')` above, which is the
spelling `staticphp i18n` uses and which does work.
:::

## Translating

```php
<?php

// Plain lookup
i18n::translate('Log in');

// Placeholders, substituted in a single pass
i18n::translate('Hello %name%', ['%name%' => $user->name], 'html');

// ICU message, which is what handles plurals
i18n::format('{n, plural, zero{# faili} one{# fails} other{# faili}}', ['n' => $count]);
```

Both take the same four arguments:

```php
<?php

public static function translate(
    string $text,
    array $replace = [],
    ?string $escape = null,
    ?string $language_key = null
): string;

public static function format(
    string $text,
    array $arguments = [],
    ?string $escape = null,
    ?string $language_key = null
): string;
```

`$escape` is one of `html`, `attr`, `input`, `js`, `url`, or `null` to leave the string
alone. `$language_key` translates into a language other than the current one, in the
`<country>_<language>` form described on the
[locales page](/staticphp-core/i18n/locales/#the-language-key).

## What happens to a string nobody has translated

`translate()` and `format()` both go through the same private lookup: walk the fallback
chain for the locale, return the first value that is not `null`, and if there is none,
register the key.

Registration appends `$config['i18n']['missing_suffix']` - `*` by default - to the source
text and returns that. The suffix is the point: an untranslated string shows up on the page
as `Log in*`, visible to whoever is reviewing it and searchable in the translation backend,
without anyone having to run an extraction step first.

With `$config['i18n']['auto_register']` on, and if the store is not degraded, the key is
also inserted and the placeholder written for the requested language. The insert happens
once per key per request no matter how many times the page asks for it. Turn
`auto_register` off on a read-only replica, or anywhere a page render must not write.

## Writing translations from php

```php
<?php

// Insert or update; registers the key if it is new
i18n::set('Log in', 'Pieslēgties', 'lv_lv');

// Same, but throws TranslationError if the key is not registered
i18n::update('Log in', 'Pieslēgties', 'lv_lv');

// Mark warmed copies stale - one language, or every language with null
i18n::cacheInvalidate('lv_lv');
```

`set()` returns `false` rather than throwing when the write fails and strict mode is off.

## Degrading instead of dying

`$config['i18n']['strict']` decides what a database or ICU failure does. `false` returns the
source string and logs; `true` rethrows, which is what you want in tests and development;
`null` follows `$config['debug']`.

Outside strict mode, a store that has taken a failure sets a flag and stops registering
keys, and the catalog refuses to warm a table it could not read, so an outage is never
cached as authoritative. Ask about it with:

```php
<?php

i18n::isInitialised();  // has init() or inject() run
i18n::isDegraded();     // has a database call failed this request
i18n::debug();          // prints the current locale, degraded flag and string table
```

`TranslationError` is the exception to that: it only ever means the configuration is wrong,
and `Store` rethrows it even outside strict mode.

## Twig

`i18n::twigRegister()` writes the current locale into `Config::$items['view_data']['i18n']`
- `country_code`, `language_code`, `language_key`, `url_prefix`, `countries` and
`alternates` - so a plain php view can read it, and then, if a view engine is configured,
registers:

| Name            | Kind     | Maps to             |
| --------------- | -------- | ------------------- |
| `translate`     | filter   | `i18n::translate()` |
| `format`        | filter   | `i18n::format()`    |
| `_`             | function | `i18n::translate()` |
| `_f`            | function | `i18n::format()`    |
| `i18n_url`      | function | `i18n::url()`       |
| `i18n_number`   | function | `i18n::number()`    |
| `i18n_currency` | function | `i18n::currency()`  |
| `i18n_date`     | function | `i18n::date()`      |

None of them is marked html safe. A translation that really does carry markup needs an
explicit `|raw`; everything else is escaped by twig as usual, which is what keeps a user
supplied value substituted into a translation from becoming stored xss.

Twig is optional for the package as a whole, and so it is here: `twigRegister()` sets
`view_data` either way and returns early when `$config['view_engine']` is empty.

## Testing and the cli

`i18n::inject()` wires an already built config, `Locale`, `Store` and `Catalog` in without
touching the router or the request:

```php
<?php

public static function inject(array $config, Locale $locale, Store $store, Catalog $catalog): void;
```

`i18n::reset()` clears every static, so one process can serve two languages in turn.
