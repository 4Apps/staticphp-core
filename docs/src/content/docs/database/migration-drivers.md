---
title: Migration drivers
description: What DriverInterface requires, and how MySQL, Postgres and SQLite differ under the migration runner.
sidebar:
    order: 6
---

The state machine, the checksums and the command flow are database agnostic. Everything
that is not lives behind `StaticPHP\Utils\Models\Migrations\Drivers\DriverInterface`,
rather than in `if ($driver === 'mysql')` scattered across the commands.

## Choosing one

```php
<?php

public static function forPdo(PDO $pdo, ?string $dsn = null): DriverInterface;
```

`Driver::forPdo()` reads `PDO::ATTR_DRIVER_NAME` off the open connection and returns
`PostgresDriver`, `MySqlDriver` or `SqliteDriver`. Anything else is a `MigrationError`:

```text
Migrations do not support the "odbc" PDO driver; supported drivers are pgsql, mysql and sqlite.
```

The `$dsn` argument exists for SQLite alone, which needs the database file path to place
its lock file. `Cli` passes the connection's configured `string` for it.

## What the interface requires

The class docblock calls these "the four things"; the interface actually declares eight
methods. The full contract:

| Method                          | Purpose                                                                 |
| ------------------------------- | ----------------------------------------------------------------------- |
| `name(): string`                | The PDO driver name - `pgsql`, `mysql` or `sqlite`                       |
| `supportsTransactionalDdl(): bool` | Whether a failed migration inside a transaction is actually rolled back |
| `noTransactionFileError(MigrationFile $file): ?string` | Why this database cannot honour the no-transaction directive for this file, or null |
| `createTableSql(string $table): string` | `CREATE TABLE IF NOT EXISTS` for the tracking table; the table name arrives validated and quoted |
| `columns(PDO $pdo, string $table): array` | Column names of the table, raw and unquoted; empty when it does not exist |
| `quoteIdentifier(string $identifier): string` | Quote an identifier, already validated by the caller             |
| `lock(PDO $pdo): void`          | Take the exclusive migration lock, blocking until it is free             |
| `unlock(PDO $pdo): void`        | Release it. Must not throw if the lock was never held                    |

`supportsTransactionalDdl()` is the one that changes the shape of a run. When it is true,
`apply` wraps the file in a transaction and writes the tracking row inside it, so the
migration and its record land together or not at all. When it is false, the row is claimed
before the file runs and confirmed afterwards, so a crash leaves a `FAILED` row rather than
a `PENDING` file that has already half-run.

## At a glance

|                              | Postgres                | MySQL / MariaDB          | SQLite                        |
| ---------------------------- | ----------------------- | ------------------------ | ----------------------------- |
| `name()`                     | `pgsql`                 | `mysql`                  | `sqlite`                      |
| Transactional DDL            | yes                     | **no**                   | yes                           |
| Tracking row written         | inside the transaction  | claimed, then confirmed  | inside the transaction        |
| Identifier quoting           | `"table"`               | `` `table` ``            | `"table"`                     |
| Lock                         | `pg_advisory_lock`      | `GET_LOCK`, 60s timeout  | `flock()` on a sidecar file   |
| No-transaction directive     | single statement only   | accepted, already true   | accepted, any number          |
| Column introspection         | `information_schema`, resolved via the oid | `information_schema` for the current database | `pragma_table_info()` |

## Postgres

The easy case. DDL is transactional, so a failing migration and its tracking row both
disappear on rollback.

```sql
CREATE TABLE IF NOT EXISTS "migrations" (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        TEXT NOT NULL UNIQUE,
    checksum    TEXT NOT NULL,
    applied_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duration_ms INTEGER,
    applied_by  TEXT NOT NULL
)
```

`columns()` resolves the schema from the table's oid through `to_regclass()` rather than
matching on `table_name` alone, so a same-named table in another schema cannot answer for
the one `search_path` picks.

It is the only driver with something to say about the no-transaction directive. Postgres
wraps a multi-statement simple-Query message in an implicit transaction, and the whole file
goes to `PDO::exec()` in one call with no statement splitter, so a multi-statement
no-transaction file is refused before anything runs:

```text
error: 2026-08-07-100000-nt.sql is marked `-- migrations:no-transaction` but contains more
than one statement. Postgres runs a multi-statement send inside an implicit transaction,
which would defeat the directive. Split the file so each no-transaction migration contains
exactly one statement.
```

The count comes from `Discovery::countStatements()`, which strips `--` comments and splits
on `;`. A semicolon inside a dollar-quoted block or a string literal counts as a separator
there, so such a file is over-counted - a loud refusal at scan time rather than a wrong
decision at run time, and the operations the directive exists for are never dollar-quoted.

## MySQL and MariaDB

`CREATE`, `ALTER` and `DROP TABLE` perform an implicit commit inside the server, so
wrapping a migration in a transaction protects nothing. `PDO::inTransaction()` goes false
the moment the first DDL statement lands, and a later `rollBack()` throws rather than
undoing anything. Everything this driver does differently follows from that one fact:

- `supportsTransactionalDdl()` returns false, so every migration takes the claim-then-
  confirm path;
- a failure is reported as possibly partial, and the tracking row is left behind as
  `FAILED` so nothing later can run until an operator resolves it;
- `noTransactionFileError()` always returns null - DDL here never runs inside a transaction
  anyway, so the directive describes what already happens rather than requesting anything.

```sql
CREATE TABLE IF NOT EXISTS `migrations` (
    id          BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL UNIQUE,
    checksum    VARCHAR(64) NOT NULL,
    applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duration_ms INTEGER NULL,
    applied_by  VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

`name` is `VARCHAR(255)` rather than `TEXT` because it carries a `UNIQUE` index and InnoDB
cannot index a `TEXT` column without a prefix length. 255 utf8mb4 characters is 1020 bytes,
inside the 3072-byte limit.

The practical advice follows: **keep MySQL migrations to a single statement per file where
you can.** A file with three statements that fails on the second leaves the first applied
and no way to undo it.

## SQLite

Behaves like Postgres for everything the engine cares about - DDL included, a failed
migration inside a transaction rolls back cleanly.

```sql
CREATE TABLE IF NOT EXISTS "migrations" (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    checksum    TEXT NOT NULL,
    applied_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duration_ms INTEGER,
    applied_by  TEXT NOT NULL
)
```

`columns()` uses `pragma_table_info(?)`, the table-valued form of `PRAGMA table_info`, so
the table name can be bound instead of interpolated. It returns nothing for a table that
does not exist, which is exactly what `ensureTable()` wants.

`noTransactionFileError()` returns null for any file: SQLite autocommits each statement
separately outside an explicit transaction, so the directive holds however many statements
the file contains - which is what a table rebuild needs.

That rebuild is the thing to know as a migration author. SQLite's `ALTER TABLE` cannot drop
a constraint or change a column type, so the official remedy is the 12-step table rebuild,
and that needs `PRAGMA foreign_keys = OFF` - silently ignored inside a transaction. Such a
migration **must** carry `-- migrations:no-transaction`, or it will appear to work while
leaving dangling foreign key rows.

### It is what the tests run against

The migration engine's test suite drives the real `Commands` class against a real SQLite
database in a temporary file: it has transactional DDL like Postgres and needs no server,
so the whole state machine is exercised for real with no service containers. CI installs
`pdo_sqlite` alongside `pdo_pgsql` and `pdo_mysql` for exactly this reason.

The consequence is worth stating plainly: the SQLite path is the best covered of the three,
and the MySQL-specific claim-then-confirm path cannot be reproduced there. The source notes
it is covered separately by an integration script.

## Locking

`Tracker::withLock()` calls `lock()` before the body and `unlock()` after it, whatever
happens. `apply` and `baseline` run inside it; `status`, `new`, `repair` and `forget` do
not.

**Postgres** takes a session-level advisory lock on one fixed key:

```php
<?php

public const LOCK_KEY = 4829173465872001;
```

`pg_advisory_lock()` blocks until the key is free, and the lock disappears with the
session, so a killed process releases it.

**MySQL** uses named locks:

```php
<?php

public const LOCK_NAME = 'staticphp_migrations';
public const LOCK_TIMEOUT = 60;
```

`GET_LOCK()` returns 1 when acquired, 0 on timeout and NULL on error, and only 1 may
proceed - treating a timeout as success is how two deploys end up interleaving DDL. On
timeout it throws:

```text
Could not acquire the migration lock within 60 seconds; another migration run is probably in progress.
```

The timeout is finite rather than infinite because, unlike a Postgres advisory lock, this
one survives on a connection the server has not yet noticed is dead, and a deploy hanging
forever is worse than one that fails loudly.

**SQLite** has no server and therefore no server-side lock. It takes an exclusive `flock()`
on `<database>.migrate.lock`, a sidecar beside the database file, because SQLite takes its
own locks on the database file and a stray `flock()` there would be a good way to deadlock
against the driver. An in-memory or anonymous database - `sqlite::memory:` or a bare
`sqlite:` DSN - has no path and no possibility of a second process, so
`SqliteDriver::pathFromDsn()` returns null and locking is skipped entirely.

A lock file that cannot be opened is an error rather than a silent downgrade:

```text
Cannot open the migration lock file next to /srv/app/storage/app.sqlite; the directory must be writable.
```

This is also why `Cli` forces `persistent` to false on the migration connection: a pooled
connection handed back without ending its session would leave an advisory or named lock
held by nobody.
