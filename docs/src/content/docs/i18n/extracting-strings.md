---
title: Extracting strings
description: What the scanner can see in your source, and every staticphp i18n command.
sidebar:
    order: 4
---

Because the source text is the key and unseen strings register themselves on first sight,
extraction is not what decides which strings get translated. `Scanner` exists to answer two
narrower questions: which keys the source has that the database does not, and which keys the
database has that nothing references any more.

## Scanner

`StaticPHP\Utils\Models\Translation\Scanner` is static and has one public method:

```php
<?php

public const EXTENSIONS = ['php', 'twig', 'html'];

public static function scan(array $paths, array $extensions = self::EXTENSIONS): array;
```

`$paths` accepts files and directories mixed. Directories are walked recursively; anything
whose path contains `/vendor/` or `/node_modules/` is skipped, because neither is source
anybody wrote and both are large enough to dominate the run. Extensions are compared
lowercased.

The return is source text mapped to the `file:line` places it appears, sorted by key:

```php
<?php

$found = [
    'Log in' => ['/srv/app/src/Application/Views/login.twig:14'],
    'Sign out' => ['/srv/app/src/Application/Views/nav.twig:3'],
];
```

### What it matches

Three regular expressions, each looking for a quoted literal in a specific position:

| Pattern                            | Matches                                          |
| ---------------------------------- | ------------------------------------------------ |
| `_('text')`, `_f('text')`          | not when preceded by a word character, `$`, `>` or `-`, so `$obj->_('x')` and `foo_('x')` are not it |
| `::translate('text')`, `::format('text')` | any class, so `i18n::translate('x')` and an aliased `I18n::format('x')` both match |
| `'text'\|translate`, `'text'\|format` | the twig filter form                          |

Single quoted bodies are unescaped for `\\` and `\'` only; double quoted bodies go through
`stripcslashes()`. An empty result is skipped.

### What it cannot see

It is a heuristic and honest about it. It matches **literal** strings, so `_($heading)` and
`_('Hello ' ~ name)` are invisible to it. A key that is built at runtime therefore looks
unused, which is why `scan` and `prune` both print a warning about it and `prune` asks for
confirmation.

## The command

`StaticPHP\Utils\Models\Translation\Cli` is the `staticphp i18n` command. This package
provides the class; the `staticphp` executable that dispatches to it ships with the
[application skeleton](/staticphp-core/skeleton/cli/) and finds the class
through
[`StaticPHP\Core\Cli::commands()`](/staticphp-core/database/migrations-cli/#the-command-registry),
then calls:

```php
<?php

public static function run(array $arguments, string $basePath): int;
```

`$arguments` is everything after `i18n`, and `$basePath` is the repository root - the
directory holding `src/`. It returns a process exit code, and returns 1 immediately if
`PHP_SAPI` is not `cli`.

It is reached before `Bootstrap.php` runs and never touches the router, for the same reason
the [migrations command](/staticphp-core/database/migrations-cli/) is: routing configuration
has no notion of a cli-only route, so anything reachable through a controller is also
reachable over http - and this one deletes keys.

### Usage

```text
Usage: staticphp i18n <command> [options]

Commands:
  status                     Configured languages and how much of each is translated
  missing [<language>]       List untranslated keys
  keys                       List every registered key
  set <language> <key> <value>
                             Write one translation
  export <language>          Write a language out as csv or json
  import <language> <file>   Read a language back in
  scan                       Compare the source tree against the database
  prune                      Delete keys the source tree no longer references
  clear [<language>]         Mark warmed copies stale
  install                    Write the schema into the migrations directory

Options:
  --check             status: exit 1 if anything is untranslated (for CI)
  --write             scan: register the keys found in source
  --yes               prune: do not ask
  --overwrite         import: replace translations that are already there
  --upgrade           install: emit the upgrade from the pre-2.0 schema instead
  --format=FORMAT     export/import: csv or json (default: csv, import auto-detects)
  --out=PATH          export: write to this file instead of stdout
  --path=PATH         scan/prune: source tree to read (default: the application)
  --connection=NAME   Entry of config['db']['pdo'] to use (default: from config)
  --project=NAME      Application under src/ to load config from (default: Application)
  --help              This text
```

That text is `Cli::USAGE`, printed by `--help` (and by `-h`, which the text does not
mention) anywhere in the arguments, by an empty command line, and by an unknown command.

`--check`, `--write`, `--yes`, `--overwrite` and `--upgrade` are flags; the rest take a
value in `--name=value` form. Anything else is a hard error before a connection is opened,
on stderr, exit 2:

```text
error: unknown option --force
error: --format needs a value, as --format=something
```

### Before anything runs

`run()` bootstraps in this order: check that `src/<project>/Public` exists, define
`PUBLIC_PATH`, require the autoloader, `Config::load(['Config'])`, then everything in
`$config['autoload_configs']`. Framework defaults are loaded for anything the application
did not load itself - `staticphp/Utils/Db` when `$config['db']['pdo']` is empty, and
`staticphp/Utils/i18n` when `$config['i18n']` is.

Then it resolves the connection: `--connection`, or `$config['i18n']['db_config']`, or
`default`. An unknown one lists what is configured and exits 2; a connection that cannot be
opened exits 1.

The `Store` the commands run against is built with **strict mode on**, whatever
`$config['i18n']['strict']` says. A command that silently degraded would report "0 keys" for
a database it never reached, and somebody would believe it.

Every command except `clear` and `install` starts by checking that the tables exist, and
stops with exit 1 if they do not:

```text
error: the i18n tables are not there
  staticphp i18n install     writes the schema as a migration
  staticphp migrate apply    applies it
```

## status

```bash
staticphp i18n status
staticphp i18n status --check
```

Prints the driver, the key count, and one line per configured pairing - language key, ICU
locale, translated, untranslated, and whether it is complete:

```text
driver: sqlite
keys:   3

LANGUAGE     ICU            DONE  MISSING  STATE
lv_lv        lv_LV             1        2  INCOMPLETE
lv_en        en_LV             0        3  INCOMPLETE
lv_ru        ru_LV             0        3  INCOMPLETE
ee_et        et_EE             0        3  INCOMPLETE
ee_en        en_EE             0        3  INCOMPLETE
ee_ru        ru_EE             0        3  INCOMPLETE
```

A key counts as missing when its value is `null`, an empty string, **or** the source text
with `missing_suffix` on it. The auto-registered placeholder is the source text with a
marker on it, which is exactly what "nobody has translated this" looks like.

Languages that have translation rows but no configuration are listed after the table, as
`Languages with rows but no configuration: xx_yy`. An empty database prints
`Nothing registered yet. Strings appear here the first time a page asks for them.` and exits
0.

Without `--check`, `status` exits 0 whatever it found. With `--check` it exits 1 if anything
is untranslated, which is the form to put in CI.

## missing

```bash
staticphp i18n missing
staticphp i18n missing lv_en
```

The same "missing" definition as `status`, listed instead of counted. With no argument it
covers every configured pairing; with one it covers exactly that language key, configured or
not.

```text
# lv_en (3)
  Log in
  Hello %name%
  {n, plural, one{# fails} other{# faili}}
```

Keys come out in registration order, and each language's block is followed by a blank line.

## keys

```bash
staticphp i18n keys
```

Every registered key, one per line, oldest first. No truncation, no counts - it is meant to
be piped.

## set

```bash
staticphp i18n set lv_lv 'Log in' 'Pieslēgties'
```

Needs three positional arguments and exits 2 with `error: set needs a language, a key and a
value` otherwise. Anything after the third is joined back onto the value with single spaces,
so an unquoted multi-word value still works - but the round trip through the shell is
lossy for runs of spaces, so quote it.

Registers the key if it is new, marks the language stale, and echoes what it wrote with both
sides truncated to 70 characters. A failed write prints
`error: could not write the translation` and exits 1.

## export

```bash
staticphp i18n export lv_lv
staticphp i18n export lv_lv --format=json --out=lv_lv.json
```

Writes the whole table for one language - every registered key, including the ones with no
translation. Default format is csv, with a `key,value` header and `null` written as an empty
field:

```text
key,value
"Log in",Pieslēgties
"Hello %name%","Hello %name%*"
"{n, plural, one{# fails} other{# faili}}",
```

`--format=json` keeps `null` as `null`, and is pretty printed with unescaped unicode and
slashes:

```text
{
    "Log in": "Pieslēgties",
    "Hello %name%": "Hello %name%*",
    "{n, plural, one{# fails} other{# faili}}": null
}
```

Missing language exits 2 with `error: export needs a language, as: staticphp i18n export
lv_en`. An unknown format exits 2. `--out` writes to the file and prints
`N strings written to PATH` instead; a failed write exits 1.

## import

```bash
staticphp i18n import lv_lv lv_lv.csv
staticphp i18n import lv_lv lv_lv.json --overwrite
```

Needs a language and a file. The format defaults to `auto`, which is json only when the
extension is `.json` and csv otherwise. The csv reader skips a `key,value` first row but
does not require one.

Without `--overwrite`, a key whose current value is not `null` is left alone and counted as
skipped; with it, everything in the file is written. Empty keys are ignored. The language is
marked stale at the end:

```text
lv_lv: 12 written, 3 left alone
```

A missing file, an unreadable one, or one that does not parse as the chosen format exits 2.
A write that fails exits 1, immediately, with the offending key.

## scan

```bash
staticphp i18n scan
staticphp i18n scan --path=src/Application,src/Modules
staticphp i18n scan --write
```

Runs `Scanner::scan()` over `--path` (comma separated, trimmed), defaulting to `APP_PATH`,
and diffs the result against the registered keys both ways:

```text
found in source:  3
registered:       3

# in source, not registered (2)
  Sign out   /srv/app/src/Application/Views/nav.twig:2
  {n, plural, other{# files}}   /srv/app/src/Application/Views/page.php:2

# registered, not found in source (2)
  Hello %name%
  {n, plural, one{# fails} other{# faili}}

Only literal strings are visible to the scanner. Check before pruning.
```

Keys are truncated to 70 characters in the listing, and each new key is shown with the first
place it was found.

`--write` registers the keys in the first list under the **default** language key -
`Locales::default()`, the first language of the first country - with `missing_suffix`
appended, without overwriting anything that is already there. Then every language is marked
stale, and a final line says what was written:

```text
2 keys registered under lv_lv
```

Nothing in the second list is touched: `scan` never deletes.

`scan` exits 0 whatever it finds.

## prune

```bash
staticphp i18n prune
staticphp i18n prune --yes
```

The second half of `scan`, acted on. Lists the registered keys the scanner did not find,
says how many would go, and asks:

```text
  Hello %name%
  {n, plural, one{# fails} other{# faili}}

2 keys, and every translation of them, would be deleted.
A key built at runtime looks unused here. Read the list.
Delete them? [y/N]
```

Only `y` or `yes`, case insensitively, proceeds; anything else prints `Nothing deleted.` and
exits 0. `--yes` skips the question. Deleting a key deletes its translations too, and every
language is marked stale afterwards, followed by a count of what went:

```text
2 keys deleted.
```

Nothing to prune prints `Nothing to prune.` and exits 0.

## clear

```bash
staticphp i18n clear
staticphp i18n clear lv_en
```

Marks warmed copies stale - one language, or every language with no argument - and prints
`Marked lv_en stale.` or `Marked every language stale.`. It deletes rows from `i18n_cached`
and nothing else; the warmed copies themselves are left where they are, and the missing
freshness flag is what stops them being read.

It does not check the schema first. Against a database where the tables are missing, the
strict `Store` therefore lets the driver error through, and the command wrapper turns it
into `error: PDOException: ...` on stderr with exit 1 rather than reporting success.

## install

```bash
staticphp i18n install
staticphp i18n install --upgrade
```

Copies `src/Utils/Files/I18n/install.<driver>.sql` into the migrations directory as
`<UTC timestamp>-i18n-install.sql`, in `Y-m-d-His` form, and tells you what to do next:

```text
Wrote /srv/app/src/Application/Migrations/2026-08-01-140000-i18n-install.sql
Review it, then: staticphp migrate apply
```

The schema arrives as a migration rather than being applied here so that it reaches every
environment the way the rest of the schema does, and so that "has i18n been installed here"
has the same answer as every other table. See
[migrations](/staticphp-core/database/migrations/).

The migrations directory comes from `$config['migrations']['dir']`, loaded from the
framework default if the application has not loaded its own, and falls back to
`APP_PATH . '/Migrations'`. A missing directory exits 2, and so does a missing template. An
existing target file exits 1 rather than overwriting.

`--upgrade` emits `upgrade.<driver>.sql` instead, which migrates a pre-existing i18n schema
to this one. Only postgres has one, because the schema it upgrades from only ever shipped
for postgres; asking for it on another driver prints:

```text
error: no upgrade template for mysql
The schema this upgrades from only ever shipped for postgres.
```

Read the upgrade before applying it. It deletes orphaned and duplicate translation rows on
the way to the unique constraint, and it says so in its own header comment.

## Exit codes

| Code | Meaning                                                                  |
| ---- | ------------------------------------------------------------------------ |
| 0    | The command did what it was asked                                        |
| 1    | It ran and failed - `--check` found untranslated keys, a write failed, the tables are missing, the connection failed, or `PHP_SAPI` is not `cli` |
| 2    | It was asked wrongly - unknown command or option, missing argument, unknown format, missing file or directory |
