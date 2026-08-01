---
title: Locales and negotiation
description: How a request is resolved to one country and language pairing, and in what order.
sidebar:
    order: 2
---

Three classes decide which language a request is served in: `Locale` is one country and
language pairing, `Locales` is the configured set of them, and `Negotiator` reads an
`Accept-Language` header. All three live in `StaticPHP\Utils\Models\Translation\`.

## Locale

A `final` class with a readonly constructor and nothing else that changes:

```php
<?php

public function __construct(
    public readonly string $countryCode,  // "lv"
    public readonly string $countryName,  // "Latvia"
    public readonly string $language,     // "en"
    public readonly string $urlPrefix,    // "lv-en"
    public readonly string $icuLocale,    // "en_LV"
    public readonly bool $isDefault,      // this country's first language
) {
}

public function key(): string;       // "lv_en"
public function hreflang(): string;  // "en-LV"
```

Immutability is deliberate. The class this replaced bound its statics to live references
into the config array inside a `foreach`-by-reference, so the active language could be
rewritten by an unrelated loop elsewhere in the request.

### The language key

`key()` returns `<countryCode>_<language>`, and that string is what everything else keys on:
the `language` column of the translations table, the cache key, the file name of the
internal cache, and the `$language_key` argument of `i18n::translate()` and
`i18n::format()`.

It is **country scoped, not language scoped**. `lv_en` and `ee_en` are two separate string
tables, so the same english source text can be worded differently for two countries.

`hreflang()` is `<language>-<COUNTRY>`, which is the spelling an `hreflang` attribute wants.

## Locales

Built from `$config['i18n']` and never mutated afterwards:

```php
<?php

public static function fromConfig(array $config): self;
public static function prefix(string $format, string $countryCode, string $language): string;

public function all(): array;                          // Locale[], configuration order
public function default(): Locale;                     // the first one
public function find(string $countryCode, string $language): ?Locale;
public function byPrefix(string $prefix): ?Locale;
public function byKey(string $key): ?Locale;           // "lv_en"
public function forCountry(string $countryCode): array;
public function fallbackChain(Locale $locale): array;
```

`fromConfig()` walks `$config['i18n']['available']` in order and produces one `Locale` per
country and language. It throws `TranslationError` when the configuration cannot produce a
single pairing:

| Condition                                            | Message                                                                                    |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| `available` missing, not an array, or empty          | `config['i18n']['available'] is empty`                                                     |
| an entry with no `code`, or with empty `languages`   | `Every entry of config['i18n']['available'] needs a "code" and a non-empty "languages"`     |
| two entries producing the same url prefix            | `Duplicate i18n url prefix "..."`                                                          |

The duplicate check matters more than it looks: two countries resolving to one prefix would
make the url ambiguous, and whichever came second would simply never be reachable.

### What the shipped configuration produces

The default `available` is Latvia serving `lv, en, ru` and Estonia serving `et, en, ru`,
with `url_format` of `{{country}}-{{language}}`. That is six pairings:

| `key()`  | `urlPrefix` | `icuLocale` | `isDefault` | `hreflang()` |
| -------- | ----------- | ----------- | ----------- | ------------ |
| `lv_lv`  | `lv-lv`     | `lv_LV`     | true        | `lv-LV`      |
| `lv_en`  | `lv-en`     | `en_LV`     | false       | `en-LV`      |
| `lv_ru`  | `lv-ru`     | `ru_LV`     | false       | `ru-LV`      |
| `ee_et`  | `ee-et`     | `et_EE`     | true        | `et-EE`      |
| `ee_en`  | `ee-en`     | `en_EE`     | false       | `en-EE`      |
| `ee_ru`  | `ee-ru`     | `ru_EE`     | false       | `ru-EE`      |

`default()` is `lv_lv`: the first language of the first country.

### How the ICU locale is derived

`icuLocale` is `<language>_<COUNTRY>` unless the country entry carries its own `locale` key,
which overrides the derivation for **every** language of that country:

```php
<?php

$config['i18n']['available'] = [
    [
        'name' => 'Latvia',
        'code' => 'lv',
        'locale' => 'lv_LV',
        'languages' => ['lv', 'en'],
    ],
];
```

With that override both `lv_lv` and `lv_en` format as `lv_LV`. Without it, `lv_en` formats
as `en_LV` - english words, latvian number and date conventions - which is usually what you
want on a country-scoped site, and is the reason the shipped config file asks you to keep
the codes honest.

### The fallback chain

`fallbackChain()` answers "where else should I look for this string", always starting with
the locale itself, then the locale's own country default, then the global default, with
duplicates removed:

| Requested | Chain                    |
| --------- | ------------------------ |
| `lv_lv`   | `lv_lv`                  |
| `lv_ru`   | `lv_ru`, `lv_lv`         |
| `ee_et`   | `ee_et`, `lv_lv`         |
| `ee_ru`   | `ee_ru`, `ee_et`, `lv_lv` |

The country's own default comes first so that a Latvian site missing a Russian string shows
Latvian rather than jumping to another country's language. The chain is only consulted when
`$config['i18n']['fallback']` is on; with it off, the lookup sees the requested language
and nothing else.

## Negotiation precedence

This is the part you cannot work out from the class names. `i18n::init(?string $country,
?string $language)` resolves the request in this order, and stops at the first step that
produces a locale.

### 1. An explicit pairing, if both arguments are given

`Locales::find($country, $language)` is an exact match on both. If the pairing is not
configured, `init()` throws:

```text
i18n has no "lv" country serving "de"
```

There is no fallback here on purpose. A caller that named a pairing meant it.

### 2. The url prefix

With no arguments, `init()` walks `Router::$prefixes` **in the order the router stripped
them** and takes the first one that `Locales::byPrefix()` recognises. `Router` fills that
array in `$config['url_prefixes']` order, which is why the i18n config file prepends the
language prefixes to it - a prefix that is matched after some other application prefix
still resolves, but a language prefix that is not in the list at all is never stripped and
ends up as the first controller segment instead.

### 3. Accept-Language, when `negotiate` is on

If no prefix matched, the target starts as `Locales::default()`. Then, with
`$config['i18n']['negotiate']` on, `Negotiator::best($_SERVER['HTTP_ACCEPT_LANGUAGE'],
$locales)` runs and replaces the target if it returns anything.

Negotiation is only ever reached by a request that carried no prefix of its own, so it can
suggest but never override what the visitor explicitly asked for.

### 4. Redirect, or carry on

With `$config['i18n']['redirect']` on and `PHP_SAPI` not `cli`, `init()` calls
`Router::redirect($target->urlPrefix . Router::$requested_url)` - the same url, with the
chosen prefix in front of it. `Router::redirect()` ends the process, so nothing after
`init()` runs on that request. With `redirect` off, or under the cli, the target is simply
used as the request's locale and nothing is sent.

## Negotiator

```php
<?php

public static function best(?string $header, Locales $locales): ?Locale;
```

Header parsing:

- The header is split on commas; each part's tag is lowercased and its `q=` parameter read.
- A tag with `q` at or below zero is dropped, and so is `*` - "anything" is already what the
  caller does when this returns `null`, and answering it here would just pick the first
  configured language twice.
- The tag must match `^([a-z]{2,3})(?:-([a-z]{2}|[0-9]{3}))?`; anything that does not is
  skipped, so a malformed header degrades to the tags in it that are well formed rather
  than throwing.
- Candidates are sorted by quality descending, and **equal qualities keep header order**,
  which is the order of preference the browser wrote them in. A tag with no `q` at all has
  quality 1.0, so it outranks any tag carrying a lower one and ties with an explicit `q=1`.

Matching, for each candidate in that order:

1. If the tag carried a region, `Locales::find($region, $language)` - an exact country and
   language match wins when one exists. Note that the region is matched against the
   configured **country code**, not against a real ISO country: `et-EE` finds the country
   configured as `ee`.
2. Otherwise, or if that missed, the first configured locale whose `language` matches, in
   configuration order. Region is deliberately loose here: a browser sending `en-GB` should
   land on the site's english, whatever country the site is scoped to.

`null` when nothing matches, including for an empty or `null` header.

Against the shipped configuration:

| `Accept-Language`              | Result  | Why                                          |
| ------------------------------ | ------- | -------------------------------------------- |
| `lv,en;q=0.8`                  | `lv_lv` | highest quality first                        |
| `en;q=0.4,ru;q=0.9,lv;q=0.1`   | `lv_ru` | quality beats header order                   |
| `ru;q=0.5,lv`                  | `lv_lv` | an unqualified tag is 1.0                    |
| `ru;q=0,en;q=0.2`              | `lv_en` | zero quality is not a preference             |
| `et-EE`                        | `ee_et` | exact region match                           |
| `en-EE`                        | `ee_en` | exact region match, on a shared language     |
| `en-GB`                        | `lv_en` | unknown region, first configured `en`        |
| `*`                            | `null`  | wildcards are not answered here              |
| `de-DE,fr;q=0.8`               | `null`  | nothing configured matches                   |
| an empty string, or `null`     | `null`  | nothing to parse                             |

The same negotiation is available on the facade, after `init()`:

```php
<?php

// Defaults to the current request's header
i18n::negotiate();
i18n::negotiate('et-EE,en;q=0.5');
```

## Reading the resolved locale

`init()` points a set of statics at the resolved pairing:

```php
<?php

i18n::$country_code;     // "lv"
i18n::$language_code;    // "en"
i18n::$language_key;     // "lv_en"
i18n::$url_prefix;       // "lv-en"
i18n::$current_country;  // the entry of $config['i18n']['available'] it came from
i18n::$countries;        // all of them
i18n::$config;           // $config['i18n'] as loaded

i18n::locale();          // the Locale object, or null before init()
i18n::locales();         // the Locales set
i18n::hash();            // sha1 of country_code . language_code
```

## Urls for the other languages

```php
<?php

// The current url, served in another language
i18n::url('ee_et');

// A different path, in another language
i18n::url('ee_et', 'some/other/page');

// A url prefix from a country entry and a language
i18n::urlPrefix(['code' => 'lv'], 'en');   // "lv-en"
```

`url()` throws `TranslationError` for a language key that is not configured, and otherwise
builds `Router::baseUrl($locale->urlPrefix . '/' . $path)` - or just the prefix when the
path is empty. `$path` defaults to `Router::$segments_url`, the current url with its
prefixes already stripped, and a leading slash on it is trimmed either way.

`alternates()` is the same thing for every language at once, shaped for `rel="alternate"`
tags:

```php
<?php

public static function alternates(bool $sameCountryOnly = false): array;
```

Each entry is `['key' => 'lv_en', 'hreflang' => 'en-LV', 'language' => 'en',
'country' => 'lv', 'url' => ...]`. With `$sameCountryOnly` true it covers only the current
country's languages; otherwise every configured pairing. `twigRegister()` puts the full list
into `view_data` for you.
