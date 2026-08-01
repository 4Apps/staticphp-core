---
title: Writing migrations
description: The filename pattern, the file contents and the one directive the runner understands.
sidebar:
    order: 4
---

A migration is a file of SQL in the migrations directory. There is no class, no method and
no down migration - undoing a change means writing another file.

## The filename

`Discovery::FILENAME_PATTERN` is the whole convention, and it is checked on every file in
the directory, not only the ones about to run:

```php
<?php

public const FILENAME_PATTERN = '/^(\d{4}-\d{2}-\d{2}-\d{6})-([a-z0-9-]+)\.sql$/';
```

In words: a UTC timestamp as `YYYY-MM-DD-HHMMSS`, a hyphen, then a lower-case
kebab-case description, then `.sql`. The first group is the prefix used for ordering and
for `--to`; the second is free text for humans.

```text
2026-08-01-143000-add-users-table.sql
```

The pattern is stricter than it looks. Anything in the directory that fails it stops every
command with
`error: Bad migration filename "...": expected YYYY-MM-DD-HHMMSS-kebab-name.sql`:

| Filename                       | Result                                        |
| ------------------------------ | --------------------------------------------- |
| `2026-08-01-100000-x-1.sql`    | Accepted - digits are fine in the description |
| `2026-8-1-100000-x.sql`        | Rejected - the date must be zero-padded       |
| `2026-08-01-1000-x.sql`        | Rejected - the time is six digits, not four   |
| `2026-08-01-100000-X.sql`      | Rejected - upper case                          |
| `2026-08-01-100000-x_y.sql`    | Rejected - underscores                         |
| `2026-08-01-100000-x.SQL`      | Rejected - the extension is matched literally  |
| `2026-08-01-100000-.sql`       | Rejected - the description cannot be empty     |

Files are ordered by a plain string sort of their paths, which is what makes a zero-padded
timestamp prefix load-bearing rather than decorative. Two files sharing a prefix are
refused outright:

```text
error: Duplicate migration timestamp 2026-08-06-100000: 2026-08-06-100000-x.sql and
2026-08-06-100000-y.sql
```

Two developers branching on the same afternoon would otherwise get different orderings on
different machines, since the tie would be broken by the description.

Note that only `*.sql` is globbed, so a `README` or a `.sql.bak` in the directory is
ignored rather than rejected.

### Let the tool name it

`staticphp migrate new "add users table"` builds the name for you through
`Discovery::newFilename($name, $now)`, which lower-cases the text, replaces every run of
non-alphanumeric characters with a single hyphen, trims the hyphens off both ends and
prefixes `gmdate('Y-m-d-His', $now)` - so the timestamp is UTC, not local time.

```text
"Add Users Table!! v2"  ->  2026-08-01-143000-add-users-table-v2.sql
```

A name that slugifies to nothing is an error rather than a file called `.sql`:

```text
error: Migration name "!!!" contains no usable characters
```

## The file contents

Whatever you write, minus two checks. The file must be valid UTF-8 - a file that is not is
refused by name rather than mangled by the server - and it must not contain psql
meta-commands.

The whole file is sent to `PDO::exec()` in a single call. There is no statement splitter,
so a file may hold as many statements as the database accepts in one send. What it must not
hold is anything only a client program understands:

```text
error: 2026-08-05-100000-dump.sql contains psql meta-command(s) PDO cannot execute:
    line 1: \restrict abc
Strip them (pg_dump emits \restrict / \unrestrict) and try again.
```

Any line whose first non-space character is a backslash counts. This is aimed squarely at
`pg_dump` output, which is the usual way such a line ends up in a migration.

### Never edit an applied file

The checksum is a sha256 over the raw bytes, so changing a comment or the line endings of
an already-applied file puts it in `DRIFT` and blocks the next `apply`. That is the
intended behaviour, not an inconvenience: the recorded checksum is evidence of what
actually ran.

If an edit really was deliberate - a typo in a comment, a reformat - then
`staticphp migrate repair <name>` re-stamps the recorded checksum. If it was not, revert
the file.

## The no-transaction directive

```php
<?php

public const NO_TRANSACTION_DIRECTIVE = '-- migrations:no-transaction';
```

By default a migration runs inside a transaction on any database that supports one for DDL.
Some statements cannot: `CREATE INDEX CONCURRENTLY` on Postgres, and SQLite's 12-step table
rebuild, which needs `PRAGMA foreign_keys = OFF` - silently ignored inside a transaction,
so the rebuild appears to work while leaving dangling foreign key rows.

Such a file opts out by carrying the directive as its **very first line**:

```sql
-- migrations:no-transaction
CREATE INDEX CONCURRENTLY posts_author_idx ON posts (author_id);
```

The check is `trim(strtok($raw, "\n")) === self::NO_TRANSACTION_DIRECTIVE`, so the string
must match exactly - surrounding whitespace and a trailing `\r` are tolerated, anything
else is not, and a directive on the second line does nothing at all.

Opting out has a cost, and it is the reason not to reach for it casually: the migration is
recorded with `claim()` before it runs and confirmed after, so a failure part-way leaves a
`FAILED` row and, quite possibly, a half-applied schema. The runner says so:

```text
FAILED 2026-08-07-100000-nt.sql: <the database error>
This migration ran OUTSIDE a transaction, so it may have PARTIALLY applied.
It is recorded as FAILED and no later migration will run until you resolve it.
```

Postgres refuses a no-transaction file with more than one statement, because it wraps a
multi-statement send in an implicit transaction and the directive would be a lie. MySQL and
SQLite accept any number. See
[migration drivers](/staticphp-core/database/migration-drivers/) for the exact rules.

## What `new` writes

The template `staticphp migrate new` leaves behind is comments only, which is why an
untouched file is still valid and `status` is happy to list it:

```sql
-- add users table
--
-- Runs in a transaction where the database supports one for DDL (postgres, sqlite).
-- MySQL commits DDL implicitly, so there a failure part-way leaves the change half
-- applied - keep MySQL migrations to a single statement where you can.
--
-- Add `-- migrations:no-transaction` as the very first line if this file
-- needs CREATE INDEX CONCURRENTLY, a sqlite table rebuild, or anything else that must
-- not run inside a transaction.
```

Note the first line of that template is a comment, not the directive - adding the directive
means putting it above everything, including this header.

## Practical rules

- **One logical change per file.** There is no rollback, so the unit of recovery is the
  file.
- **On MySQL, prefer a single statement per file.** DDL commits implicitly there, so a file
  with three statements that fails on the second leaves the first applied and nothing to
  undo it.
- **Write migrations that can be read years later.** The file is the permanent record of
  the change; the tracking table only records that it happened.
- **Keep the directory out of `Public/`.** The shipped default is `APP_PATH/Migrations`,
  which already is.
