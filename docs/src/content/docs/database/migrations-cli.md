---
title: Migrations CLI
description: Every migrate command, its arguments, its output and its exit code.
sidebar:
    order: 5
---

`StaticPHP\Utils\Models\Migrations\Cli` is the `staticphp migrate` command. This package
provides the class; the `staticphp` executable that dispatches to it ships with the
[application skeleton](https://github.com/gintsmurans/staticphp), which requires the file
directly and calls:

```php
<?php

public static function run(array $arguments, string $basePath): int;
```

`$arguments` is everything after `migrate`, and `$basePath` is the repository root - the
directory holding `src/`. It returns a process exit code, and returns 1 immediately if
`PHP_SAPI` is not `cli`.

It is reached before `Bootstrap.php` runs and never touches the router. That is deliberate:
routing configuration has no notion of a cli-only route, so a migrations controller would
also answer `POST /migrations/apply` over http - on a tool whose whole job is changing the
schema.

## Usage

```text
Usage: staticphp migrate <command> [options]

Commands:
  status              List every migration and what it is doing
  apply               Apply every pending migration, in order
  new <name>          Create an empty migration file
  baseline            Record migrations as applied WITHOUT running them
  repair <name>       Re-stamp a migration's checksum after a deliberate edit
  forget <name>       Drop a migration's tracking row; does not touch the schema

Options:
  --check             status: exit 1 if anything is pending or blocked (for CI)
  --dry-run           apply: list what would run, change nothing
  --to=PREFIX         apply/baseline: stop after the migration with this timestamp
  --yes               baseline: accept every candidate without prompting
  --connection=NAME   Entry of config['db']['pdo'] to use (default: from config)
  --dir=PATH          Override the migrations directory
  --table=NAME        Override the tracking table
  --project=NAME      Application under src/ to load config from (default: Application)
  --help              This text
```

That text is `Cli::USAGE`, printed by `--help` (and by `-h`, which the text does not
mention) anywhere in the arguments, by an empty command line, and by an unknown command.

Options are order-insensitive and may appear before or after the command. `--check`,
`--dry-run` and `--yes` are flags; the rest take a value in `--name=value` form. Anything
else is a hard error before a connection is opened:

```text
error: unknown option --force
error: --to needs a value, as --to=something
```

Both go to stderr and exit 2.

## status

```bash
staticphp migrate status
staticphp migrate status --check
```

Lists every migration - on disk, in the tracking table, or both - one per line, as state,
name and the recorded `applied_at`:

```text
  applied  2026-08-01-100000-users.sql                          2026-08-01 16:58:29
  PENDING  2026-08-02-100000-posts.sql

1 applied, 1 pending, 0 blocked
```

An empty directory and an empty table together print `No migrations found.` and exit 0.

Anything in `DRIFT`, `MISSING` or `FAILED` is counted as blocked, and each blocked
migration gets a reason and a remedy after the summary:

```text
  DRIFT    2026-08-01-100000-users.sql                          2026-08-01 16:58:29
  applied  2026-08-02-100000-posts.sql                          2026-08-01 16:58:29

1 applied, 0 pending, 1 blocked

  DRIFT 2026-08-01-100000-users.sql  (file changed after it was applied)

DRIFT 2026-08-01-100000-users.sql: revert the edit, or run `staticphp migrate repair 2026-08-01-100000-users.sql` if the edit was deliberate.
```

The advice differs per state, because `repair` re-reads the file to re-stamp its checksum
and is therefore useless for `MISSING`:

```text
MISSING 2026-08-02-100000-posts.sql: restore the file from version control. If it is gone for
good and its schema change is known to be in place, run `staticphp migrate forget 2026-08-02-100000-posts.sql`.

FAILED 2026-08-01-100000-users.sql: this migration started and never confirmed, so part of it
may have landed. Inspect the database, undo or complete whatever it did, then run
`staticphp migrate forget 2026-08-01-100000-users.sql` to retry it, or `staticphp migrate baseline` to accept it as done.
```

**Without `--check`, `status` exits 0 even when migrations are blocked.** It is a report,
not a gate. With `--check` it exits 1 if anything is pending *or* blocked, which is the
form to put in CI. A problem loading the files at all - a bad filename, a duplicate
timestamp, a missing directory - prints `error: ...` and exits 1 either way.

## apply

```bash
staticphp migrate apply
staticphp migrate apply --dry-run
staticphp migrate apply --to=2026-08-01-100000
```

Runs every pending migration in filename order, holding the exclusive migration lock for
the whole run. In order, it:

1. loads the states, refusing on any file-level error;
2. **stops if anything is blocking** - `DRIFT`, `MISSING` or `FAILED` - printing the same
   report `status` does, and exits 1 without running anything;
3. builds the queue from the pending migrations, filtered by `--to` if given;
4. validates the entire queue before executing any of it, so a problem in the last file is
   found before the first one has touched the database;
5. runs each file, recording it as it goes.

```text
applied 2026-08-01-100000-users.sql (0 ms)
applied 2026-08-02-100000-posts.sql (0 ms)
Applied 2 migration(s).
```

Nothing to do is a success:

```text
Database is up to date; nothing to apply.
```

`--dry-run` prints the queue and changes nothing except the tracking table's existence,
which is created by the state load on a fresh database:

```text
would apply 2026-08-01-100000-users.sql
would apply 2026-08-02-100000-posts.sql
2 migration(s) would be applied; nothing was changed.
```

`--to=PREFIX` takes a timestamp prefix, not a filename, and stops after it - the filter is
`prefix <= to`, so the named migration is included. The prefix must belong to a file that
exists, otherwise:

```text
error: no migration with prefix "1999-01-01-000000"
```

A failure mid-run stops the run. On a database with transactional DDL the failing migration
leaves no trace at all, and everything before it stays applied:

```text
FAILED 2026-08-02-100000-broken.sql: <the database error>
Rolled back. Nothing from this migration, or after it, was applied.
```

Without transactional DDL, or for a file carrying the no-transaction directive, the message
is the honest version instead - the migration may have partially applied, it is recorded as
`FAILED`, and nothing later will run until it is resolved. See
[migration drivers](/staticphp-core/database/migration-drivers/).

## new

```bash
staticphp migrate new "add users table"
```

Writes an empty migration into the migrations directory. Every positional argument after
the command is joined with spaces, so quoting the name is a courtesy rather than a
requirement.

```text
created /srv/app/src/Application/Migrations/2026-08-01-143000-add-users-table.sql
```

The filename and the template are covered in
[writing migrations](/staticphp-core/database/writing-migrations/#let-the-tool-name-it).
The command refuses rather than overwrites, and refuses a name that slugifies to nothing:

```text
error: /srv/app/src/Application/Migrations/2026-08-01-143000-add-users-table.sql already exists
error: Migration name "!!!" contains no usable characters
error: migrations directory does not exist: /srv/app/src/Application/Migrations
```

Calling `new` with no name at all exits 2:

```text
error: new needs a name, as: staticphp migrate new "add users table"
```

## baseline

```bash
staticphp migrate baseline
staticphp migrate baseline --yes
staticphp migrate baseline --to=2026-08-01-100000
```

Writes tracking rows **without executing anything**. This is the adoption path for a
database that already has the schema - an existing production database meeting the
migration runner for the first time - and the way to accept a `FAILED` migration as done
after finishing it by hand.

Candidates are every `PENDING` migration plus every `FAILED` one, sorted by name. Unlike
`apply`, there is no blocking guard: accepting a `FAILED` migration is the point.

Each candidate is prompted for individually:

```text
  2026-08-03-100000-a.sql                              mark as already applied? [y/N/a/q]
```

The answer is lower-cased and trimmed. `y` stamps this one, `a` stamps this one and every
remaining candidate without asking again, `q` aborts, and anything else - including a bare
Enter - skips it. Aborting writes nothing at all, because nothing is written until every
answer is in:

```text
Aborted; nothing was written.
```

That path exits 1. A completed run prints a blank line and a summary, and exits 0:

```text
stamped 1 migration(s) as applied (not executed); 1 left pending
```

With nothing to do:

```text
Nothing to baseline; every migration on disk is already recorded.
```

`--yes` accepts every candidate without prompting, which is what a scripted adoption wants.
`--to=PREFIX` limits the candidates the same way it limits `apply`'s queue, and rejects an
unknown prefix the same way.

Rows written by `baseline` carry a duration of 0 and the file's real checksum, so the
migration goes straight to `APPLIED`. A `FAILED` migration's existing row is deleted first
rather than inserted alongside, which would trip the unique index on `name`.

## repair

```bash
staticphp migrate repair 2026-08-01-100000-users.sql
```

Re-stamps one migration's recorded checksum from the file on disk. It is the only way out
of `DRIFT` short of reverting the file, and it changes nothing in the schema.

```text
repaired 2026-08-01-100000-users.sql: checksum re-stamped to f93cb410c769...
```

The argument is a bare filename. A path is refused, because `../elsewhere/x.sql` would
resolve outside the migrations directory and stamping that file's checksum under its
basename would leave the real migration permanently in `DRIFT` while reporting success:

```text
error: "../x.sql" must be a plain migration filename, not a path
error: no such migration file: /srv/app/src/Application/Migrations/nope.sql
error: 2026-08-01-100000-users.sql has no tracking row - it was never applied, so there is nothing to repair
```

All three exit 1. Missing the argument entirely exits 2 with
`error: repair needs a migration filename`.

## forget

```bash
staticphp migrate forget 2026-08-02-100000-posts.sql
```

Drops one migration's tracking row. This is the way out of `MISSING`, and the way to retry
a `FAILED` migration after undoing whatever it managed to do.

```text
forgot 2026-08-02-100000-posts.sql; the database schema was not touched.
```

That second clause is the point: `forget` changes what the tool believes, never the
schema. A migration that is forgotten and still on disk goes back to `PENDING` and will run
again on the next `apply`.

```text
error: "../x.sql" must be a plain migration filename, not a path
error: no tracking row for "2026-08-02-100000-posts.sql"
```

Both exit 1; a missing argument exits 2 with `error: forget needs a migration filename`.

## Where the settings come from

Each of the three settings is resolved as **command-line option, then
`$config['migrations']`, then a built-in default**:

| Setting     | Option              | Config key                  | Default                  |
| ----------- | ------------------- | --------------------------- | ------------------------ |
| Connection  | `--connection=NAME` | `migrations.connection`     | `default`                |
| Directory   | `--dir=PATH`        | `migrations.dir`            | `APP_PATH . '/Migrations'` |
| Table       | `--table=NAME`      | `migrations.table`          | `migrations`             |

Before any of that, `Cli` does the part of the bootstrap a schema tool needs and none of
the part it does not - no router, no session, no view engine. It checks that
`{$basePath}/src/{$project}/Public` exists, defines `PUBLIC_PATH` as that directory,
registers the autoloader, loads `Config`, then everything listed in
`$config['autoload_configs']`. If the application loaded neither, it falls back to this
package's own `Utils/Config/Db.php` and `Utils/Config/Migrations.php`.

`--project=NAME` selects which application under `src/` to boot, defaulting to
`Application`; a name with no `Public` directory exits 2. See
[running several applications](/staticphp-core/guides/multiple-applications/).

The connection is opened through `Db::init()` with the configured entry, with one forced
change: **`persistent` is set to false**. A persistent connection is handed back to the
pool without ending the session, so a crashed run would leave `pg_advisory_lock` or
`GET_LOCK` held by a connection nobody owns and nobody can release.

An unknown connection name is reported with the ones that exist, and exits 2:

```text
error: no database connection "reporting" in config['db']['pdo'] (configured: default, archive)
```

Each tracking row records who applied it, as `$config['version']` (or `unknown`) followed
by ` @ ` and the hostname - for example `2.1.4 @ deploy-01`.

## Exit codes

| Code | Meaning                                                                                    |
| ---- | ------------------------------------------------------------------------------------------ |
| 0    | Success. For `status --check`, also means nothing pending and nothing blocked               |
| 1    | The command refused or failed: blocked states, a failing migration, a bad file, `status --check` with work outstanding, `baseline` aborted with `q`, or not running under the cli SAPI |
| 2    | The invocation was wrong: unknown command or option, missing option value, missing argument, unknown project, unknown connection |

Anything unexpected that escapes a command is caught, printed to stderr as
`error: <class>: <message>`, and exits 1. Never exiting 0 on an unexpected failure is
deliberate - an unattended deploy would read that as "migrations succeeded" and restart
services against an unmigrated schema.

## In a deploy

```bash
staticphp migrate status --check || staticphp migrate apply
```

`apply` takes the lock, so two deploys racing each other serialise rather than interleave.
Note that `status --check` is not a lock and not a guarantee; it is a cheap way to keep the
log quiet when there is nothing to do.
