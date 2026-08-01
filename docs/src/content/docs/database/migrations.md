---
title: Migrations
description: How migration files on disk and rows in the tracking table combine into one state per migration.
sidebar:
    order: 3
---

The migration runner applies plain `.sql` files in filename order and records what it
applied in a tracking table. There are no classes to write, no up/down pairs and no
rollback: a migration is a file of SQL, and undoing one means writing another.

Everything lives in `StaticPHP\Utils\Models\Migrations`:

| Class                     | Job                                                                  |
| ------------------------- | -------------------------------------------------------------------- |
| `Discovery`               | Finds, validates and checksums the files on disk                      |
| `Tracker`                 | Reads and writes the tracking table, and holds the lock               |
| `States`                  | Combines disk and table into one ordered list of `MigrationState`     |
| `State`                   | The five states, as a string-backed enum                              |
| `Commands`                | The commands themselves, driver agnostic and output agnostic          |
| `Cli`                     | Argument parsing, bootstrapping and connecting for `staticphp migrate` |
| `Drivers\*`               | The handful of things each database answers differently               |
| `MigrationFile`, `AppliedRow`, `MigrationState` | Readonly value objects for a file, a row, and the pair |
| `MigrationError`          | An `\Exception` for anything wrong with the files themselves          |

The commands are on [the CLI page](/staticphp-core/database/migrations-cli/), the file
format on [writing migrations](/staticphp-core/database/writing-migrations/), and the
per-database differences on
[migration drivers](/staticphp-core/database/migration-drivers/). This page is the model
they all sit on.

## Configuration

`$config['migrations']` has three keys, shipped in `src/Utils/Config/Migrations.php`:

```php
<?php

$config['migrations'] = [
    'dir' => APP_PATH . '/Migrations',
    'table' => 'migrations',
    'connection' => 'default',
];
```

`dir` is where the `.sql` files live, kept outside `Public/` deliberately - migrations
describe the whole schema and must never be reachable over http. `table` is the tracking
table. `connection` names the entry of `$config['db']['pdo']` to migrate; see
[configuration](/staticphp-core/getting-started/configuration/#migrations).

Each key has a command-line override, and each falls back to a hard-coded default if the
config section is missing entirely. See
[the CLI page](/staticphp-core/database/migrations-cli/#where-the-settings-come-from).

## Discovery: what is on disk

`Discovery::discover($directory)` globs `*.sql` in the directory, sorts the paths as plain
strings, and loads each one. It throws a `MigrationError` if the directory does not exist
or cannot be listed.

The string sort is the whole ordering mechanism - there is no sequence column and no
dependency graph. It works because the filename starts with a zero-padded timestamp, which
`Discovery::FILENAME_PATTERN` enforces:

```php
<?php

public const FILENAME_PATTERN = '/^(\d{4}-\d{2}-\d{2}-\d{6})-([a-z0-9-]+)\.sql$/';
```

`Discovery::load($path)` produces a `MigrationFile` and refuses anything it cannot vouch
for:

- a filename that does not match the pattern - `Bad migration filename "x.sql": expected
  YYYY-MM-DD-HHMMSS-kebab-name.sql`;
- a file it cannot read;
- contents that are not valid UTF-8.

`discover()` then adds one rule of its own: two files sharing a timestamp prefix are a
`MigrationError`, because their relative order would depend on the rest of the filename and
could differ between machines.

The resulting value object:

```php
<?php

class MigrationFile
{
    public function __construct(
        public readonly string $name,          // 2026-08-01-143000-add-users.sql
        public readonly string $prefix,        // 2026-08-01-143000
        public readonly string $path,
        public readonly string $sql,           // the whole file
        public readonly string $checksum,      // sha256 of the raw bytes
        public readonly bool $noTransaction
    ) {
    }
}
```

`Discovery::checksum()` is `hash('sha256', $data)` over the raw bytes, so a changed comment
or a changed line ending counts as a change. That is deliberate: the checksum answers "is
this the file that ran?", which is answerable, rather than "would this file do the same
thing?", which is not.

Two more static helpers are used during validation rather than discovery.
`findMetaCommands()` returns the 1-indexed lines that start with a backslash - psql
meta-commands such as the `\restrict` and `\unrestrict` that `pg_dump` emits, which PDO
sends straight to the server as syntax errors. `countStatements()` strips `--` comments,
splits on `;` and counts the non-empty remainders; it is deliberately crude and only used
to refuse a multi-statement no-transaction file on Postgres.

## Tracker: what the database says

`Tracker` owns the tracking table. Its constructor validates the table name against
`/^[A-Za-z_][A-Za-z0-9_]*$/` and throws `Invalid migrations table name: "..."` rather than
quoting anything unusual into a working query.

```php
<?php

public const EXPECTED_COLUMNS = ['id', 'name', 'checksum', 'applied_at', 'duration_ms', 'applied_by'];
```

`ensureTable()` runs the driver's `CREATE TABLE IF NOT EXISTS` and then checks that the
table really has those six columns, comparing case-insensitively. It is called at the start
of every command that reads state, including `apply --dry-run`, so a dry run does create
the table on a fresh database even though it changes nothing else.

The column check exists because `CREATE TABLE IF NOT EXISTS` matches on name alone and
`migrations` is a generic name. Without it an unrelated table would be silently adopted:

```text
Table "migrations" exists but is not a migration tracking table: missing column(s) id,
name, checksum, applied_at, duration_ms, applied_by; it has something. Point the tracker at
a free name via the migrations config, or drop/rename the existing table if it is not in
use.
```

The table bootstraps itself on every invocation rather than being migration zero - there
would be nothing to record the fact that it had been created.

### Rows

`appliedRows()` selects `name, checksum, applied_at, duration_ms` ordered by name, with an
explicit `PDO::FETCH_ASSOC` so it does not depend on the connection's
`fetch_mode_objects`. Each row becomes an `AppliedRow`:

```php
<?php

class AppliedRow
{
    public function __construct(
        public readonly string $name,
        public readonly string $checksum,
        public readonly string $appliedAt,   // as the driver returned it
        public readonly ?int $durationMs     // null when the migration never completed
    ) {
    }
}
```

Rows are written in one of two shapes:

- **`record($name, $checksum, $durationMs, $appliedBy)`** inserts a finished migration in
  one statement. Used where DDL is transactional, so the insert rides inside the
  migration's own transaction and the two land together or not at all.
- **`claim($name, $checksum, $appliedBy)`** inserts the same row with a null
  `duration_ms`, before the migration runs, and **`confirm($name, $durationMs)`** fills the
  duration in afterwards. This is the MySQL path, where a failure cannot be rolled back: a
  row with a null duration means "started, never confirmed", which is reported as `FAILED`
  and refused by the next `apply`. A hard kill mid-migration leaves exactly that, which is
  the point.

Two more methods exist for the operator's benefit: `forget($name)` deletes a row and
returns whether one was actually removed, and `updateChecksum($name, $checksum)` re-stamps
a checksum and returns false when there is no such row.

### The lock

`withLock(callable $body)` takes the driver's exclusive lock, runs `$body`, and releases
the lock whatever happens. A failure to release never replaces the body's own exception, so
a cleanup problem cannot hide the real one.

`apply` and `baseline` run entirely inside it. `status`, `new`, `repair` and `forget` do
not. What the lock actually is differs per database - see
[migration drivers](/staticphp-core/database/migration-drivers/#locking).

## States: the two sides combined

`States::compute(array $files, array $rows)` is a pure function - no database, no
filesystem. It takes the union of filenames and row names, sorts it as strings, and pairs
each name with whatever exists on each side:

```php
<?php

class MigrationState
{
    public function __construct(
        public readonly string $name,
        public readonly State $state,
        public readonly ?MigrationFile $file,  // null when the file is gone
        public readonly ?AppliedRow $row       // null when it never ran
    ) {
    }
}
```

A row with no file sorts in among the files rather than being appended, so a listing reads
chronologically whatever went wrong.

### The five states

```php
<?php

enum State: string
{
    case APPLIED = 'applied';
    case PENDING = 'PENDING';
    case DRIFT   = 'DRIFT';
    case MISSING = 'MISSING';
    case FAILED  = 'FAILED';
}
```

Only `applied` is lower-case. The other four are spelled in capitals so they stand out in a
listing - `PENDING` included, because it is the one that means "this database is not up to
date yet".

| State     | Meaning                                                          | Way out                                          |
| --------- | ---------------------------------------------------------------- | ------------------------------------------------ |
| `APPLIED` | On disk, recorded, checksums agree                                | Nothing to do                                     |
| `PENDING` | On disk, not recorded                                             | `apply`, or `baseline` to accept it without running |
| `DRIFT`   | On disk and recorded, but the file changed after it was applied   | Revert the edit, or `repair` if it was deliberate |
| `MISSING` | Recorded, but the file is gone                                    | Restore from version control, or `forget`         |
| `FAILED`  | Recorded as started and never confirmed                           | Inspect the database, then `forget` or `baseline` |

The order of the tests in `compute()` is itself a decision:

1. A row with a null `durationMs` is `FAILED` - checked **first**, before drift and before
   missing. A half-applied migration is the more urgent fact, and re-stamping its checksum
   would bury it.
2. No file, but a row: `MISSING`.
3. A file, but no row: `PENDING`.
4. Both, checksums differ: `DRIFT`.
5. Otherwise `APPLIED`.

### Pending and blocking

```php
<?php

public static function pending(array $states): array;   // PENDING only
public static function blocking(array $states): array;  // DRIFT, MISSING, FAILED
```

`apply` refuses to run while `blocking()` returns anything, before it looks at the queue at
all. `baseline` deliberately skips that guard, because accepting a `FAILED` migration as
done is one of the two ways out of that state.

## Using the engine directly

`Commands` takes its collaborators as constructor arguments and writes through a callable
rather than echoing, so the whole surface is usable outside the CLI - a deploy script, a
test, an admin task:

```php
<?php

use PDO;
use StaticPHP\Utils\Models\Migrations\Commands;
use StaticPHP\Utils\Models\Migrations\Drivers\Driver;
use StaticPHP\Utils\Models\Migrations\Tracker;

$dsn = 'sqlite:' . APP_PATH . '/storage/app.sqlite';
$pdo = new PDO($dsn, '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$commands = new Commands(
    $pdo,
    new Tracker($pdo, Driver::forPdo($pdo, $dsn), 'migrations'),
    APP_PATH . '/Migrations',
    function (string $line = ''): void {
        echo $line . "\n";
    }
);

$exitCode = $commands->apply(false, null, 'deploy@ci');
```

The `$out` callable receives one line at a time without a trailing newline. Every command
returns a process exit code: 0 for success, 1 for anything the command itself refused or
could not do. This is exactly how the test suite drives it, which is why the whole state
machine can be exercised against a real SQLite database with no service containers.
