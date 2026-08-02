---
title: Catalogs
description: Where translations are stored, how a language table is warmed, and when it goes stale.
sidebar:
    order: 3
---

There are no `.po` files and no per-language php arrays checked into the repository. The
catalog is three database tables, and `Catalog` is a cache in front of them.

- `Store` is every database call the translation layer makes, for postgres, mysql and
  sqlite.
- `Catalog` is one warmed string table per language key, memoised for the request and cached
  between them.

Both are in `StaticPHP\Utils\Models\Translation\`.

## The schema

`staticphp i18n install` copies the schema for your driver into the migrations directory as
a migration; see [extracting strings](/staticphp-core/i18n/extracting-strings/#install). The
templates ship in `src/Utils/Files/I18n/`.

| Table               | Columns                                                    |
| ------------------- | ---------------------------------------------------------- |
| `i18n_keys`         | `id`, `key_hash`, `key`, `created`                         |
| `i18n_translations` | `id`, `key_id`, `language`, `value`, `created`, `updated`   |
| `i18n_cached`       | `id`, `created`                                            |

`i18n_keys` is unique on `key_hash`, `i18n_translations` is unique on
`(key_id, language)` and indexed on `language`, and `i18n_cached` is keyed on `id` - which
holds a language key such as `lv_en`, not a numeric id.

Names come from `$config['i18n']['tables']`, and `$config['i18n']['db_scheme']` qualifies
them. The shipped default is an empty scheme, and the config file's own note explains why
that is right for all three drivers: postgres already has `public` on its search path, and
mysql and sqlite resolve against the connected database. Both the scheme and the table names
are validated against `/^[A-Za-z_][A-Za-z0-9_]*$/` and
rejected with `TranslationError` rather than escaped - an identifier that is not a plain
identifier is a configuration mistake, and quietly rewriting it would only hide where the
mistake is.

### Why keys are hashed

Every lookup and every insert addresses a key by `hash('sha256', $key)`, not by the text.
The source text *is* the key, and source text is a whole english sentence - sometimes a
paragraph. Postgres will index that; mysql's InnoDB will not, because a unique index on a
utf8mb4 column tops out at 768 characters. Hashing moves the uniqueness onto a fixed 64
character column and the length limit disappears on all three drivers.

### Null is not an empty string

`Store::translations()` returns the whole table as source text mapped to translation, built
from a `LEFT JOIN` of keys onto translations for one language. A key with no row for that
language maps to `null`, which is what tells the lookup to try the next locale in the
fallback chain. An empty string is a deliberate "translate this to nothing" and stays
distinct from it.

## Store

```php
<?php

public function __construct(
    private readonly string $connection = 'default',  // entry of config['db']['pdo']
    private readonly string $scheme = '',
    private readonly array $tables = [],
    private readonly bool $strict = false,
) {
}

public function driver(): string;        // pgsql, mysql or sqlite
public function isInstalled(): bool;     // are the tables actually there
public function isDegraded(): bool;
public function lastError(): ?string;

public function keyId(string $key): ?int;
public function ensureKey(string $key): ?int;
public function keys(): array;           // [['id' => int, 'key' => string], ...], oldest first
public function deleteKey(string $key): bool;

public function translations(string $languageKey): array;
public function putTranslation(int $keyId, string $languageKey, string $value, bool $overwrite = true): bool;
public function setTranslation(string $key, string $languageKey, string $value): bool;
public function languages(): array;      // language key => row count

public function isFresh(string $languageKey): bool;
public function markFresh(string $languageKey): void;
public function markStale(?string $languageKey = null): void;
```

`driver()` throws `TranslationError` for anything other than `pgsql`, `mysql` or `sqlite`,
because the upserts below are written per driver and there is no generic spelling of them.

`ensureKey()` inserts and ignores a conflict - `ON CONFLICT DO NOTHING`, `INSERT IGNORE` or
`INSERT OR IGNORE` - rather than checking first and inserting second, because two requests
hitting an unseen string at the same moment would both find nothing, both insert, and one
of them would take the unique violation with it. `putTranslation()` does the same with
`$overwrite` false, and a real upsert with it true.

`deleteKey()` deletes the translations explicitly before the key, rather than leaving it to
`ON DELETE CASCADE`. Sqlite only enforces foreign keys when `PRAGMA foreign_keys` is on,
which is off by default and is a per-connection setting this class does not own.

### Degrading

Every call above except `isInstalled()` runs inside a private `guard()`. Outside strict
mode, a `Throwable` sets the degraded flag, records the message for `lastError()`, writes
one `i18n: ...` line to `error_log()` and returns a neutral value - `null`, `false` or an
empty array. Only the first failure is logged; a database that is down fails every string on
the page, and a few thousand identical lines per request helps nobody.

`TranslationError` is always rethrown, in strict mode or not. It only ever means the
configuration is wrong, and there is no useful page to render past that.

`isInstalled()` deliberately sits outside the guard. It is a probe, and "the table is not
there" is the answer it exists to give; routed through `guard()` in strict mode it would
rethrow, and the command that asks in order to print install instructions would die printing
a stack trace instead.

## Catalog

```php
<?php

public function __construct(
    private readonly Store $store,
    private readonly string $mode = 'external',      // external, internal or none
    private readonly string $prefix = 'language_',
    private readonly ?string $directory = null,      // internal cache directory
    private readonly ?int $ttl = null,               // external cache ttl, seconds
) {
}

public function strings(string $languageKey): array;
public function isLoaded(string $languageKey): bool;
public function remember(string $languageKey, string $key, ?string $value): void;
public function invalidate(?string $languageKey = null): void;
```

`i18n::init()` builds it from configuration: `mode` from `$config['i18n']['cache']`,
`prefix` from `cache_prefix`, `ttl` from `cache_ttl`, and `directory` from
`APP_PATH . '/Cache/' . $config['i18n']['cache_subdir']` - but only when the mode is
`internal` **and** `APP_PATH` is defined; otherwise it passes `null` and the internal
backend does nothing.

### Loading a language

`strings()` is the only entry point that reads, and it goes in this order:

1. Already materialised this request? Return it. The per-request memo is not optional and
   is not affected by the mode.
2. Mode `none`? Go straight to `Store::translations()` and memoise that. With no warming
   there is nothing for the freshness flag to describe, so asking about it is a round trip
   that can only ever be answered "reload anyway".
3. Otherwise ask `Store::isFresh($languageKey)`. If the flag is set, read the warmed copy;
   a hit is memoised and returned.
4. Miss, or stale: read `Store::translations()`. If the store is **not** degraded, write the
   warmed copy and `Store::markFresh()`. A degraded store returns an empty table, which is
   not the same thing as a language with no strings - warming that would cache the outage
   and mark it authoritative.

### The freshness flag lives in the database

`i18n_cached` holds one row per language whose warmed copy may be trusted. `markStale()`
deletes the row - one language, or every language when called with `null`.

The flag is in the database rather than beside the cached copy so that one translator saving
one string invalidates every application server at once, without any of them being told. The
warmed copy itself is left where it is; the missing flag is what stops it being read.

`Catalog::invalidate()` does both halves: marks stale in the database, and drops what this
request had of it. `i18n::set()` calls it after a successful write, and `i18n::cacheInvalidate()`
exposes it.

### The three cache modes

`$config['i18n']['cache']`:

**`none`** - no warming at all. Every first use of a language in a request reads the
database. This is what the test suite uses.

**`external`** - `StaticPHP\Utils\Models\Cache\Cache::get()` and `Cache::set()`, under the
key `$prefix . $languageKey`, with `cache_ttl` seconds or the backend's own default when it
is `null`. Both calls are wrapped in a `try`: no backend registered, or an unreachable one,
is treated as a miss, and the database below still has the strings. See
[caching](/staticphp-core/utilities/cache/) for the backends.

**`internal`** - a generated php file per language under the application's cache directory,
named `$prefix . $languageKey . '.php'`:

```php
<?php

// Generated by staticphp i18n, do not edit

return array (
  'Log in' => 'Pieslēgties',
);
```

It is written to a sibling temp file and renamed into place. `rename()` is atomic within a
filesystem, so a concurrent request either includes the whole previous file or the whole new
one - never the half of a new one that has been flushed so far. `opcache_invalidate()` is
called on it afterwards when the function exists. The body is produced by `var_export()`
rather than hand rolled quoting: the writer this replaced escaped apostrophes with
`str_replace` and ran the value through `stripslashes` first, so a translation ending in a
backslash emitted `'foo\';` and every subsequent request died on a parse error.

The language key reaches the filesystem here, so it is checked against
`/^[A-Za-z0-9_-]+$/` and anything else throws `TranslationError`. It is built from
configuration rather than from the url, but a traversal would write php into an arbitrary
directory, so it is checked rather than trusted.

## Lookup, fallback and the placeholder

`i18n::translate()` and `i18n::format()` share one private lookup that asks the catalog for
each language key in turn:

1. Build the chain: `Locales::fallbackChain($locale)` with `$config['i18n']['fallback']` on,
   or just the locale itself with it off.
2. If the requested key is not a configured pairing at all - the cli translates into whatever
   the caller names - the chain is emptied, because there is nothing to fall back to.
3. The requested key is put at the front of the chain if it is not already there.
4. The first key whose table has the text mapped to something that is not `null` wins.
5. Nothing matched: register the key and return the source text with `missing_suffix` on it.

Registration writes into the per-request memo with `Catalog::remember()` either way, so a
page using the same unknown string a hundred times does not go looking for it a hundred
times. The database insert additionally requires `auto_register` on and a store that is not
degraded, and it uses `putTranslation(..., overwrite: false)` - another request may have
registered it a moment ago and a translator may already have replaced the placeholder with
the real thing. Only then, and only for that one language, is `markStale()` called: the
warmed table no longer has every key.

:::caution[A placeholder blocks the fallback for that key]
The fallback chain is consulted for a key with **no row** in the requested language. Once
auto-registration has written `Log in*` for `lv_ru`, that row is not `null`, so it is a hit -
and translating the key in `lv_lv` afterwards will not change what `lv_ru` renders. The key
has to be translated in `lv_ru` too.

Verified against a sqlite database on PHP 8.4: with `Log in` translated only in `lv_lv` and
no `lv_ru` row, `lv_ru` renders the latvian translation and writes nothing; with a `lv_ru`
placeholder already present, it renders `Log in*` regardless of `lv_lv`.

`staticphp i18n missing` counts a placeholder as untranslated, so the list it prints is the
list to work through.
:::

## Reading the table directly

```php
<?php

// Warm a language without translating anything
i18n::load('lv_en');

// The whole table for a language, source text mapped to translation
i18n::cache('lv_en');

// The Store, for a translation backend that needs keys() or languages()
i18n::store();
```

Both `load()` and `cache()` default to the current language key, and both throw
`TranslationError` if `init()` (or `inject()`) has not run.
