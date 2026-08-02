---
title: Audit CLI
description: Both audit commands, their arguments, their real output and their exit codes.
sidebar:
    order: 5
---

`StaticPHP\Utils\Models\Audit\Cli` is the `staticphp audit` command. This package provides
the class; the `staticphp` executable that dispatches to it ships with the
[application skeleton](/staticphp-core/skeleton/cli/), and the map that connects
the two is `StaticPHP\Core\Cli` - see
[the command registry](/staticphp-core/database/migrations-cli/#the-command-registry).

Like `migrate` and `i18n` it is reached before `src/Core/Helpers/Bootstrap.php` runs and
never touches the router. Routing configuration has no notion of a cli-only route, so a
controller would also answer over http - here on a tool that writes schema and deletes
history.

The verbs are `install` and `prune`. There are no others.

## Usage

```text
Usage: staticphp audit <command> [options]

Commands:
  install             Write the audit schema into the migrations directory
  prune               Delete trail rows older than a date

Options:
  --before=DATE       prune: delete rows older than this, YYYY-MM-DD
  --batch=N           prune: rows per statement (default: 10000)
  --dry-run           prune: count what would go, delete nothing
  --dir=PATH          install: migrations directory to write into
  --table=NAME        Audit table (default: from config)
  --connection=NAME   Entry of config['db']['pdo'] to use (default: from config)
  --project=NAME      Application under src/ to load config from (default: Application)
  --help              This text
```

That text is `Cli::USAGE`. It is printed by `--help` - and by `-h`, which the text does not
mention - anywhere in the arguments, by an empty command line, and by an unknown command.
`--help` exits 0; the other two exit 2.

Options are order-insensitive and may appear before or after the verb. `--dry-run` is the only
flag; the rest take a value in `--name=value` form. Anything else is a hard error before a
connection is opened:

```text
error: unknown option --force
```

```text
error: --before needs a value, as --before=something
```

Both go to stderr and exit 2. So does an unrecognised verb, which prints the usage text after
the error:

```text
error: unknown command "bogus"
```

:::note[`--dry-run` ignores anything after the `=`]
It is parsed as a flag, so `--dry-run=false` sets it to `true`. There is no way to switch a
flag off once it is on the command line; leave it out instead.
:::

## install

```bash
staticphp audit install
staticphp audit install --table=history_catalog
staticphp audit install --dir=/srv/app/src/Application/Migrations
```

Copies the schema for the connection's driver into the application's migrations directory, as
an ordinary migration:

```text
Wrote /srv/app/src/Application/Migrations/2026-08-02-010330-create-audit-log.sql
Review it, then: staticphp migrate apply
```

```text
applied 2026-08-02-010330-create-audit-log.sql (0 ms)
Applied 1 migration(s).
```

Core does not ship a migration of its own and does not create the table at runtime, which is a
deliberate choice rather than an omission.
[`Discovery`](/staticphp-core/database/migrations/) globs one directory and sorts by filename,
and `Tracker` checksums every file it applied, so a framework-owned migration would sort by
the day the package was released and turn every `composer update` into checksum drift on a
file the application cannot edit. Generating one hands it over instead: it is the
application's file, in the application's timeline, and it can be edited before it is applied.

The filename comes from `Discovery::newFilename()` rather than being spelled here, so it is
guaranteed to satisfy the pattern
[`migrate`](/staticphp-core/database/writing-migrations/#let-the-tool-name-it) globs for -
an underscore in the table name would otherwise produce a file that tool refuses to read.

:::caution[`install` is not idempotent]
The timestamp comes from `time()`, so a second run in a later second writes a **second**
migration containing the same `CREATE TABLE`. The "already exists" guard only fires when both
runs land in the same second. Applying both is what tells you:

```text
applied 2026-08-02-010327-create-audit-log.sql (0 ms)
FAILED 2026-08-02-010329-create-audit-log.sql: SQLSTATE[HY000]: General error: 1 table audit_log already exists
Rolled back. Nothing from this migration, or after it, was applied.
```

Delete the surplus file before running `migrate apply`. Nothing checks for an existing
`audit_log` migration, and nothing checks for an existing `audit_log` table.
:::

### Renaming the table

`--table=NAME` rewrites every occurrence of `audit_log` in the template, which renames the
indexes with it, so two trails can coexist in one schema. That is also why the name is
validated before the rewrite.

A deployment whose `$config['audit']['table']` is a resolver cannot be asked which table to
install - the resolver answers per event - so it has to be named on the command line, one run
per table:

```text
error: config['audit']['table'] is a resolver, so it names a different table per event.
Name the one to work on with --table=NAME.
```

### install failures

```text
error: no migrations directory at /srv/app/nope
```

An unsupported driver is the other exit 2:

```text
error: no install template for oracle
```

An existing target file, an unreadable template and a failed write exit 1. Two runs that do
land in the same second show the guard working:

```text
Wrote /tmp/cap_install_0e33657f/2026-08-03-000000-create-audit-log.sql
Review it, then: staticphp migrate apply
error: /tmp/cap_install_0e33657f/2026-08-03-000000-create-audit-log.sql already exists
```

:::caution[`--table` with an invalid name crashes install]
`prune` validates the name and reports it; `install` calls the same validator without catching
what it throws, so an uncaught `AuditError` reaches the top and php exits 255:

```text
PHP Fatal error:  Uncaught StaticPHP\Utils\Models\Audit\AuditError: Refusing to write the audit trail to "audit-log": not a plain table name in /srv/core/src/Utils/Models/Audit/Store.php:128
Stack trace:
#0 /srv/core/src/Utils/Models/Audit/Commands.php(89): StaticPHP\Utils\Models\Audit\Store::assertTableName()
#1 /srv/core/src/Utils/Models/Audit/Cli.php(323): StaticPHP\Utils\Models\Audit\Commands->install()
#2 /srv/core/src/Utils/Models/Audit/Cli.php(76): StaticPHP\Utils\Models\Audit\Cli::dispatch()
#3 /srv/app/staticphp(12): StaticPHP\Utils\Models\Audit\Cli::run()
#4 {main}
  thrown in /srv/core/src/Utils/Models/Audit/Store.php on line 128
```

The same argument on the other verb:

```text
error: Refusing to write the audit trail to "audit-log": not a plain table name
```

Unlike [`migrate`](/staticphp-core/database/migrations-cli/#exit-codes), `audit` has no
catch-all around its dispatch, so any unexpected exception surfaces this way. Reported against
the module as shipped.
:::

## prune

```bash
staticphp audit prune --before=2026-01-01
staticphp audit prune --before=2026-01-01 --dry-run
staticphp audit prune --before="2026-01-01 03:00:00" --batch=5000
```

Deletes trail rows whose `created_at` is **strictly before** the given date. In batches,
because a single unbounded `DELETE` against the biggest table in the database is how a
retention job becomes an outage.

`--dry-run` counts and reports and changes nothing:

```text
4 rows in audit_log older than 2026-01-01

Nothing deleted (--dry-run).
```

Without it, with a deliberately small batch so the loop runs more than once:

```text
4 rows in audit_log older than 2026-01-01
Deleted 2/4
Deleted 4/4
Done. 4 rows removed.
```

Nothing to delete is a success, and prints only the count:

```text
0 rows in audit_log older than 2026-01-01
```

`--before` is required and is matched against `^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$` - a
date, or a date and a time, and nothing else. There is no relative form:

```text
error: prune needs --before=YYYY-MM-DD
```

```text
error: --before must be YYYY-MM-DD or "YYYY-MM-DD HH:MM:SS", got "yesterday"
```

`--batch` defaults to 10000 and must be at least 1. A value that is not numeric becomes 0 and
fails the same check:

```text
error: --batch must be at least 1
```

All three exit 2. A table that cannot be read exits 1 with
`error: cannot read audit_log: <the database error>` rather than a stack trace.

### The delete statement

```sql
DELETE FROM audit_log WHERE id IN (
  SELECT id FROM (SELECT id FROM audit_log WHERE created_at < ? ORDER BY id LIMIT 10000) AS batch
)
```

The inner select is wrapped a second time because mysql refuses to read the table it is
deleting from in a subquery, and a derived table is the way round it that the other two do not
mind. The loop repeats until it has removed the number of rows the initial count reported, and
stops early if a statement removes none - so it cannot spin on a batch it is unable to
delete.

`--before` is bound; the table name and the batch size are concatenated, which is what
`assertTableName()` and the `is_numeric()` cast are there for.

### Partitioned tables

:::note[Not exercised here]
**A partitioned table was not set up while writing this page.** The block below is
transcribed from the `$this->line()` calls in `Commands::prune()`, not captured from a run.
Every other output on this page was captured.
:::

Before deleting anything, `prune` asks postgres whether the table is a partitioned parent -
only postgres, because it is the only one of the three where the answer changes the advice -
and if it is, prints how to do it properly instead:

```text
<table> is partitioned. Dropping whole partitions is instant where
this delete is not:
  ALTER TABLE <table> DETACH PARTITION <name>;
  DROP TABLE <name>;
```

It still performs the delete afterwards.

## Where the settings come from

| Setting | Option | Config key | Default |
| --- | --- | --- | --- |
| Connection | `--connection=NAME` | `audit.connection` | `default` |
| Table | `--table=NAME` | `audit.table` | `audit_log` |
| Directory (`install`) | `--dir=PATH` | `migrations.dir` | `APP_PATH . '/Migrations'` |
| Project | `--project=NAME` | - | `Application` |

The directory comes from `$config['migrations']`, not from `$config['audit']` - the file it
writes is a migration, and it belongs where the rest of them are. A `table` config value of
`null` resolves to `audit_log`; a callable is refused unless `--table` overrides it.

Before any of that, `Cli` does the part of the bootstrap a schema tool needs and none of the
part it does not - no router, no session, no view engine. It checks that
`{$basePath}/src/{$project}/Public` exists, defines `PUBLIC_PATH` as that directory, registers
the autoloader, loads `Config`, then everything listed in `$config['autoload_configs']`.
Afterwards it checks three keys independently, and loads this package's own config file for
each one the application left empty: `$config['db']['pdo']` from `Utils/Config/Db.php`,
`$config['audit']` from `Utils/Config/Audit.php` and `$config['migrations']` from
`Utils/Config/Migrations.php`.

`--project=NAME` selects which application under `src/` to boot, and a name with no `Public`
directory exits 2 before anything is loaded:

```text
error: project "Nope" not found at /srv/app/src/Nope/Public
```

An unknown connection name is reported with the ones that do exist:

```text
error: no database connection "reporting" in config['db']['pdo'] (configured: default)
```

See [running several applications](/staticphp-core/guides/multiple-applications/).

Unlike `migrate`, this command does **not** force `persistent` to false on the connection it
opens. It takes no locks, so there is nothing for a pooled connection to strand.

## Exit codes

| Code | Meaning |
| ---- | ------- |
| 0 | Success, including `--help` and a `prune` that found nothing to delete |
| 1 | The command refused or failed: a target file that exists, an unreadable template, a failed write, a connection that could not be opened, a table that could not be read, or not running under the cli SAPI |
| 2 | The invocation was wrong: unknown verb or option, missing option value, missing `--before`, a malformed date, a batch below 1, a missing migrations directory, an unsupported driver, an unresolvable table, an unknown project or an unknown connection |

`Cli`'s own diagnostics go to **stderr**; everything `Commands` prints - including its
`error:` lines - goes to **stdout**, because `Commands` writes through an injected callable so
that the whole surface is testable without capturing output buffers. A shell that redirects
only one of the two will miss half the errors.

There is no catch-all, so an unexpected exception is a php fatal with exit 255 rather than a
tidy `error:` line. See [the caution above](#install-failures).

## In a cron job

```bash
staticphp audit prune --before="$(date -u -d '2 years ago' +%Y-%m-%d)" --batch=5000
```

Run it as a role that has `DELETE` on the table while the application does not; see
[operational notes](/staticphp-core/audit/storage/#operational-notes). Unlike
[`migrate apply`](/staticphp-core/database/migrations-cli/#apply), this command takes no lock,
so nothing serialises two runs that overlap.
