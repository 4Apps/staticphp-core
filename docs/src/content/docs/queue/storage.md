---
title: Storage
description: How the database driver claims a job, where its transactions begin and end, and the two tables the three shipped schemas create.
sidebar:
    order: 4
---

`StaticPHP\Utils\Models\Queue\QueueDatabase` keeps the queue in two tables on the application's own
database, and that choice is the whole argument for this driver: a push can be part of the transaction
that caused it. Queue the confirmation email inside the transaction that writes the payment and either
both happen or neither does - `QueueDatabaseTest::testAPushInsideARolledBackTransactionQueuesNothing()`
is that sentence as a test, where a push inside a `Db::transaction()` that throws leaves the jobs table
empty. No other backend can say it, because no other backend is the same database; the
[redis driver](/staticphp-core/queue/redis/) writes to a second system, which is not the same write.

## The two driver contracts

Every backend answers to `QueueInterface`, the six things a worker needs; one that can also list its own
backlog implements `QueueReports` too, which is the five things the commands need.

```php
<?php

namespace StaticPHP\Utils\Models\Queue;

interface QueueInterface
{
    public function push(
        string $name,
        array $payload,
        int $delay,
        string $queue,
        int $priority,
        ?string $unique,
        int $maxAttempts
    ): int;

    public function reserve(array $queues, int $timeout, string $worker): ?Job;
    public function delete(Job $job): void;
    public function release(Job $job, int $delay, string $error): void;
    public function fail(Job $job, string $error): void;
    public function pending(?string $queue = null): int;
}

interface QueueReports
{
    public function stats(): array;
    public function failedCount(): int;
    public function failedRows(int $limit): array;
    public function retryFailed(?int $id, int $maxAttempts): int;
    public function forgetFailed(?int $id, ?string $before): int;
}
```

| `QueueInterface` | What it is for |
| --- | --- |
| `push()` | Queue a job; returns its id, or the id of the job already holding `$unique`. Must be safe inside the caller's own transaction. |
| `reserve()` | Claim the next due job for `$timeout` seconds, taking `$queues` in precedence order, or `null` when there is nothing to do. |
| `delete()` | The job is done. Forget it. |
| `release()` | Put the job back for another attempt after `$delay` seconds, recording `$error` - `''` when the handler released it on purpose. |
| `fail()` | The job is out of attempts. Keep it somewhere a human will find it. |
| `pending()` | How many jobs could be picked up right now, excluding delayed and held rows. `null` counts every queue. |

| `QueueReports` | What it is for |
| --- | --- |
| `stats()` | The backlog per queue, split into `pending`, `delayed`, `reserved` and `total`. |
| `failedCount()` | How many rows are in the failed table. |
| `failedRows()` | Recent failures, newest first, capped at `$limit`. |
| `retryFailed()` | Put one failed job, or all of them, back on the queue with `$maxAttempts`; returns how many were requeued. |
| `forgetFailed()` | Delete failed jobs by id or by age; returns the affected row count. |

They are apart because reporting answers are shaped by the store - a table can be grouped and counted, a
redis stream has to be totalled from its length and its consumer group - so a backend that runs jobs
without listing them is still usable rather than forced to fake half an interface; see
[the overview](/staticphp-core/queue/overview/). The `QueueReports` docblock miscounts here, calling the
worker contract "the five methods a worker calls": it is six in `QueueInterface`, five in `QueueReports`.
`QueueDatabase` implements both, which is why `staticphp queue status` can answer at all on this driver.

```php
<?php

class QueueDatabase implements QueueInterface, QueueReports
{
    public function __construct(string $connection, string $table, string $failedTable);

    public function table(): string;
    public function failedTable(): string;
    public function connection(): string;

    public static function assertTableName(string $table): void;
}
```

`$connection` is an entry of `$config['db']['pdo']`; the two table names come from
`$config['queue']['table']` and `$config['queue']['failed_table']`.

## Table names are identifiers, not parameters

PDO cannot parameterise an identifier, so every table name here is concatenated into the SQL string. The
constructor therefore runs both names through `assertTableName()`, which matches
`/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/` - a plain identifier, optionally
schema-qualified. Anything else throws `QueueError` with the message `"<name>" is not a plain table
name`, and it throws when the driver is built rather than when a query runs, so a mistyped config entry
fails at startup instead of at three in the morning; a test pins the obvious attempt, where a
`QueueDatabase` on `queue_jobs; DROP TABLE queue_jobs` raises rather than getting near a query. It is a
public static, so an application that builds table names itself can run the same check, and the
[audit store](/staticphp-core/audit/storage/) enforces the identical pattern for the identical reason.

## How a job is claimed

`reserve()` walks the queues in the order it was given and returns the first job it can take; the work
happens in `reserveFrom()`, which is two statements. First a candidate - the next row that *could* be
taken, without taking it:

```sql
SELECT id FROM queue_jobs
 WHERE queue = ? AND available_at <= ? AND (reserved_until IS NULL OR reserved_until <= ?)
 ORDER BY priority DESC, available_at, id
 LIMIT 1
 -- FOR UPDATE SKIP LOCKED, where the server has it
```

Then the claim, an `UPDATE` whose `WHERE` re-checks everything the `SELECT` relied on:

```sql
UPDATE queue_jobs
   SET reserved_until = ?, reserved_by = ?, attempts = attempts + 1
 WHERE id = ? AND (reserved_until IS NULL OR reserved_until <= ?)
```

`claim()` returns `$statement->rowCount() === 1`. If another worker got there in the microseconds
between the two statements, the row no longer matches the guard, zero rows are updated, and this worker
knows it lost. The claim is also what increments `attempts` - reserving a job *is* the attempt, which
the reserve test asserts directly, along with the payload arriving intact. The point the class docblock
makes, and the one that survives every driver difference below:

> Reserving is a candidate SELECT followed by a guarded UPDATE, and correctness rests on
> the guard - `WHERE id = ? AND (reserved_until IS NULL OR reserved_until <= ?)` claims the
> row only if nobody else already did. `FOR UPDATE SKIP LOCKED` is added where the server
> supports it, which turns "workers wait for each other" into "workers step past each
> other", but it is an optimisation and not what makes the claim safe.

`reserved_until` is a deadline rather than a flag, and that is deliberate: a worker killed mid-job
cannot come back to release what it was holding, so the row has to become claimable on its own.
`testAJobHeldByADeadWorkerComesBackWhenTheClaimExpires()` expires a reservation by hand and watches a
second worker pick the row up as attempt two.

### Losing the race

`reserveFrom()` loops, up to `private const CLAIM_ROUNDS = 5` times, and the number is argued for in
both directions. With `SKIP LOCKED` a lost race is close to impossible; without it, two workers picking
the same candidate is ordinary, and retrying a few times covers that. Giving up rather than looping
forever means a genuinely contended queue returns to the worker loop, which sleeps and comes back,
instead of spinning here.

Each round ends one of three ways. No candidate at all returns `null` immediately - the queue is empty
and there is nothing to retry. A lost claim continues to the next round. A row whose payload will not
decode is failed on the spot and the loop continues, because something else may still be due. After five
rounds without a job, `reserveFrom()` returns `null` and the queue reports itself empty for this pass -
at which point the [worker](/staticphp-core/queue/workers/) sleeps for `$config['queue']['sleep']`
seconds and asks again, which under real contention is the right thing for a process that just lost five
races in a row. `reserved_by` is `substr($worker, 0, 64)`, sized for the column; nothing reads it back,
and it is there for the person running `SELECT * FROM queue_jobs` while something is stuck.

## The lock clause

`lockClause()` is a `match` on the PDO driver name, appended to the candidate `SELECT`:

| Driver | Lock clause |
| --- | --- |
| `pgsql` | always |
| `mysql` | MySQL 8.0.0 or later, MariaDB 10.6.0 or later |
| `sqlite` and anything else | never |

`mysqlSkipsLocked()` reads `PDO::ATTR_SERVER_VERSION`, pulls the leading `\d+\.\d+\.\d+` out of it, and
compares against `10.6.0` when the string contains `mariadb` (case-insensitively) or `8.0.0` when it does
not; a string it cannot parse falls back to `0.0.0`, which fails both comparisons. The answer is cached
for the life of the process, and it is a version check rather than a `try`/`catch` because the failure
mode of simply trying the clause is a syntax error inside a transaction, which on some setups takes the
transaction with it.

What an older MySQL costs is throughput, not correctness. The shipped mysql schema says so in its header:
*"Older servers still run the queue correctly - the claim is a guarded UPDATE either way - but two
workers will queue behind each other's row locks instead of stepping past them."* On such a server,
`CLAIM_ROUNDS` stops being theoretical. sqlite never gets the clause and does not want it; its schema is
blunt about the reason: sqlite serialises writers, *"so running more than one worker against it buys
nothing. Treat this as the development and test schema."*

## Where the transactions are

Four methods open one, and one conspicuous stretch of time does not.

**`push()`** wraps a single `INSERT`. The comment says why a single statement needs wrapping at all:

> Wrapped even though it is one statement, because losing the unique_key race aborts the
> surrounding transaction on postgres. Nested, this takes a savepoint, so the caller's
> transaction survives the duplicate and carries on.

[`Db::transaction()`](/staticphp-core/database/db/) takes a savepoint when one is already open, so the
duplicate-key `PDOException` rolls back to the savepoint rather than poisoning the invoice the caller was
writing. Outside it, `push()` catches that exception, re-reads `unique_key`, and returns the existing id
if the loser of the race can find the winner - otherwise it rethrows.

**The candidate and the claim** share one transaction, *"so that FOR UPDATE still holds the row when the
UPDATE runs"*. **Running the job does not**, which is the most important sentence about transactions on
this page: the job is run well outside that transaction, because *"holding a transaction open for the
length of a job is how a queue takes a database down with it."* The transaction ends when
`reserveFrom()` returns the `Job`; everything the [handler](/staticphp-core/queue/handlers/) then does -
the HTTP call, the SMTP conversation, the twenty seconds of PDF rendering - happens with no queue
transaction open, and the subsequent `delete()`, `release()` or `fail()` is its own statement.

**`moveToFailed()`** wraps the insert into the failed table and the delete from the jobs table, so a job
is never in both and never in neither. **`retryFailed()`** wraps each row, not the batch: the insert and
the delete for one job are atomic, but requeueing 400 failed jobs is 400 transactions. A crash halfway
leaves the first two hundred requeued and the rest still failed, which is the resumable state - running
the command again finishes the job.

## Retries, failure and dead-lettering

`release()` puts a job back for another attempt:

```sql
UPDATE queue_jobs
   SET reserved_until = NULL, reserved_by = NULL, available_at = ?, last_error = ?
 WHERE id = ?
```

Four columns, and note which is absent: `attempts` is not touched, because the claim already counted
this one. `available_at` is `time() + max(0, $delay)` - a negative delay is treated as none.
`last_error` is kept on the pending row rather than only in the failed table so that, as the schema
comment puts it, *"a job that is retrying can be asked why without waiting for it to exhaust its
attempts."*

Errors are trimmed by `fit()`, which cuts anything longer than 60,000 and appends `"\n... truncated"` -
*"something a text column and a human can both take"*. The measure is `strlen()`/`substr()`, so the limit
is 60,000 **bytes** rather than characters and a stack trace cut mid-character can end in a broken utf-8
sequence. The same function trims the error on the way into the failed table, where `fail()` puts the job
by calling `moveToFailed()` with its queue, name, raw payload JSON, attempt count and the error,
inserting and deleting in one transaction. The test covers all of it, including that the payload arrives
byte-identical (`{"id":7}`).

### retryFailed() starts the job over

`retryFailed()` retries every failed row when `$id` is `null` and one row when it is not, returning how
many were requeued. Each requeued row is inserted with `attempts` reset to `0`, `max_attempts` from the
caller's `$maxAttempts` (floored at 1), `priority` reset to `0`, `available_at` and `created_at` stamped
now, `last_error` emptied, and `unique_key` set to `null`. The docblock covers the two decisions:

> The retried job starts over with a fresh attempt count, because the reason it failed is
> usually something that was fixed outside it. It does not get its unique_key back - that
> column belongs to the pending job, and the key may well have been reused while this one sat
> in the failed table.

Tests confirm the queue name survives, the attempts are `0`, `max_attempts` is what was passed, and the
failed table is left empty. What is *not* restored is a per-job `tries`: the CLI passes the current
`config['queue']['tries']`, and nothing at this level could do otherwise since the failed table has no
column for the original - see [`queue retry`](/staticphp-core/queue/queue-cli/#retry).

`forgetFailed()` deletes by id, or by `failed_at < ?` when a `$before` string is given, and returns the
affected row count. Both arguments are optional, and with neither the statement is
`DELETE FROM queue_failed_jobs` with no `WHERE` and no guard at this level - only the
[`queue forget`](/staticphp-core/queue/queue-cli/#forget) argument check stands between an accidental
call and an unconditional delete.

## A payload that will not decode is failed at once

`toJob()` decodes the `payload` column. If `json_decode()` does not produce an array, the row does not go
back on the queue - it goes straight to the failed table with the fixed message `Payload is not valid
JSON, so no handler could be given it.` Such a row can never run, so retrying it is only a slower way of
reaching the same place; the failed table is where somebody can look at it.
`testAnUnreadablePayloadIsFailedRatherThanRetried()` corrupts a payload with a direct `UPDATE`, then
asserts that `reserve()` returns `null`, the jobs table is empty, and exactly one failed row exists
carrying `not valid JSON`. This consumes one of the five claim rounds, and the failed row records the
attempt count the claim had already incremented - the job is counted as attempted even though no handler
ever saw it. The mirror case is at push time: a payload that will not *encode* never reaches the
database, because `encode()` uses `JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE |
JSON_UNESCAPED_SLASHES` and converts a `JsonException` into `QueueError("A job payload has to be JSON
encodable: ...")`, which the encode test triggers with a file handle in the array.

## The two tables

Three schemas ship under `src/Utils/Files/Queue/`: `install.pgsql.sql`, `install.mysql.sql` and
`install.sqlite.sql`. Each creates two tables and three indexes, and all three headers open with the same
argument for two tables rather than one with a status column: a finished job is deleted, so `queue_jobs`
stays roughly the size of the backlog rather than the size of history, which is what keeps the reserve
query cheap without any index tuning. Both names are prefixed *"because `jobs` is a word an application
wants for its own work."* Jobs that ran out of attempts move to `queue_failed_jobs`, *"where they can be
read, retried or forgotten without ever being in the way of a worker."*

### queue_jobs

| Column | postgres | mysql and mariadb | sqlite |
| --- | --- | --- | --- |
| `id` | `bigserial PRIMARY KEY` | `bigint unsigned NOT NULL AUTO_INCREMENT`, with `PRIMARY KEY (id)` in the table body | `integer PRIMARY KEY AUTOINCREMENT` |
| `queue` | `varchar(64) NOT NULL DEFAULT 'default'` | same | `text NOT NULL DEFAULT 'default'` |
| `name` | `varchar(190) NOT NULL` | same | `text NOT NULL` |
| `payload` | `text NOT NULL` | same | same |
| `attempts` | `integer NOT NULL DEFAULT 0` | `int NOT NULL DEFAULT 0` | `integer NOT NULL DEFAULT 0` |
| `max_attempts` | `integer NOT NULL DEFAULT 3` | `int NOT NULL DEFAULT 3` | `integer NOT NULL DEFAULT 3` |
| `priority` | `integer NOT NULL DEFAULT 0` | `int NOT NULL DEFAULT 0` | `integer NOT NULL DEFAULT 0` |
| `unique_key` | `varchar(190)` | `varchar(190) DEFAULT NULL` | `text` |
| `available_at` | `timestamptz NOT NULL` | `datetime NOT NULL` | `text NOT NULL` |
| `reserved_until` | `timestamptz` | `datetime DEFAULT NULL` | `text` |
| `reserved_by` | `varchar(64)` | `varchar(64) DEFAULT NULL` | `text` |
| `last_error` | `text NOT NULL DEFAULT ''` | `text NOT NULL` | `text NOT NULL DEFAULT ''` |
| `created_at` | `timestamptz NOT NULL` | `datetime NOT NULL` | `text NOT NULL` |

`name` is 190 characters on every driver, and the postgres file explains why a postgres column is sized
for somebody else's limit: *"190 characters because that is what MySQL can index under utf8mb4, and a
queue that only works on postgres is not worth the extra room."* The mysql file states the same limit
from the other side, as what InnoDB can index under utf8mb4, and `unique_key` matches it because it
carries a unique index. `payload` is JSON rather than `serialize()`, for three reasons rather than one:
*"It survives a deploy that changes the class, it can be read in a SELECT while somebody is asking why a
job did not run, and it cannot be turned into an object-injection gadget by anything that manages to
write to this table."*

On mysql the timestamps are `datetime`, never `timestamp`: *"timestamp is converted through the session
timezone on the way in and out, and every value here is written by the application in UTC."* On sqlite
they are `text`, and the format is load-bearing: *"'YYYY-MM-DD HH:MM:SS' sorts and compares
chronologically as a string, which is the whole reason for that format."* mysql's `text` columns take no
literal default, which is the only reason `last_error` reads `text NOT NULL` there and `text NOT NULL
DEFAULT ''` on the other two; `push()` always writes `''` anyway.

### queue_failed_jobs

| Column | postgres | mysql and mariadb | sqlite |
| --- | --- | --- | --- |
| `id` | `bigserial PRIMARY KEY` | `bigint unsigned NOT NULL AUTO_INCREMENT` + `PRIMARY KEY (id)` | `integer PRIMARY KEY AUTOINCREMENT` |
| `queue` | `varchar(64) NOT NULL DEFAULT ''` | same | `text NOT NULL DEFAULT ''` |
| `name` | `varchar(190) NOT NULL` | same | `text NOT NULL` |
| `payload` | `text NOT NULL` | same | same |
| `attempts` | `integer NOT NULL DEFAULT 0` | `int NOT NULL DEFAULT 0` | `integer NOT NULL DEFAULT 0` |
| `error` | `text NOT NULL DEFAULT ''` | `text NOT NULL` | `text NOT NULL DEFAULT ''` |
| `failed_at` | `timestamptz NOT NULL` | `datetime NOT NULL` | `text NOT NULL` |

There is no `unique_key` column here, which is the schema-level half of why `retryFailed()` cannot
restore one. `error` holds message and trace together: *"The trace is what makes this table worth
keeping; without it a failed job is a note saying something went wrong somewhere."*

### The indexes

| Index | Columns | For |
| --- | --- | --- |
| `idx_queue_jobs_reserve` | `queue, priority DESC, available_at, id` | the candidate query |
| `idx_queue_jobs_unique_key` | `unique_key`, unique | uniqueness, and the `push()` pre-check |
| `idx_queue_failed_jobs_failed_at` | `failed_at DESC` on postgres and sqlite, `failed_at` on mysql | `failedRows()` |

The reserve index is the candidate query written down column by column - *"pick the queue, drop what is
not due, sort"* - which is why its order is `queue, priority DESC, available_at, id` and not anything
more intuitive. The one predicate deliberately missing from it is the reservation check, and the file
says why: it discards very few rows, and a nullable column here would only stop the index from serving
the sort. The unique index does double duty, because multiple NULLs are permitted in a unique index,
which is what makes one index cover both "at most one pending job with this key" and "most jobs have no
key". That holds on all three drivers, which the `unique_key` comment states outright - *"NULL repeats
freely, which every one of the three treats as 'not a duplicate'"* - and it is why a job pushed without a
`unique` key costs nothing extra.

The `failed_at` index is the one asymmetry: postgres and sqlite declare it `DESC`, matching
`failedRows()`'s `ORDER BY failed_at DESC, id DESC`, while mysql declares a plain
`KEY idx_queue_failed_jobs_failed_at (failed_at)`. No comment in any of the three files explains the
difference, so none is offered here. Index names are derived from the table names, so
[`staticphp queue install`](/staticphp-core/queue/queue-cli/#install) rewriting a table name rewrites its
indexes too, in an order that matters.

## What differs per driver

| | postgres | mysql and mariadb | sqlite |
| --- | --- | --- | --- |
| id column | `bigserial` | `bigint unsigned AUTO_INCREMENT` | `integer PRIMARY KEY AUTOINCREMENT` |
| timestamps | `timestamptz` | `datetime` | `text`, `YYYY-MM-DD HH:MM:SS` |
| `stamp()` format | `Y-m-d H:i:sP` | `Y-m-d H:i:s` | `Y-m-d H:i:s` |
| indexes declared | separate `CREATE INDEX` | `KEY` and `UNIQUE KEY` in the table body | separate `CREATE INDEX` |
| lock clause | always | 8.0 / 10.6 and up | never |
| new row id | `Db::insert($table, $row, $connection, 'RETURNING id')`, then `id` read off the returned row | `Db::lastInsertId()` | `Db::lastInsertId()` |
| table options | - | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` | - |

Two of those need a sentence. Postgres gets `RETURNING id` because *"Postgres has no lastInsertId without
a sequence name, and guessing the name from the table is how that breaks on a renamed sequence."*
[`Db::insert()`](/staticphp-core/database/db/) takes the connection name as its third argument and the
returning clause as its fourth, and with `$returning` set it returns the **fetched row** rather than an
id, so the driver reads `id` back off that row.

Postgres is also the only driver whose stamps carry an offset. Every comparison the queue makes is
against a value bound from `stamp()` rather than the server's `CURRENT_TIMESTAMP`, *"so a worker and the
database disagreeing about the timezone cannot make a job run early or never. Postgres is given an
explicit offset because timestamptz would otherwise read a bare string in the session's timezone."* The
`P` in `Y-m-d H:i:sP` is that offset, and since the `DateTimeImmutable` is built from
`@<unix timestamp>`, it is always `+00:00`.

## The reporting half

`stats()` is one grouped query per queue name, computing four values with `SUM(CASE WHEN ...)` against a
single bound `now`:

| Key | Predicate |
| --- | --- |
| `pending` | `available_at <= now AND (reserved_until IS NULL OR reserved_until <= now)` |
| `delayed` | `available_at > now` |
| `reserved` | `reserved_until > now` |
| `total` | `COUNT(*)` |

Rows come back ordered by queue name, and a test pins the split against one pending, one delayed and one
reserved job on the same queue. [`pending()`](/staticphp-core/queue/enqueueing/) is the `pending`
predicate on its own, counted across every queue or one named queue, and `failedCount()` is a `COUNT(*)`
on the failed table. `failedRows(int $limit)` returns whole rows ordered
`failed_at DESC, id DESC`; the limit goes through `max(1, $limit)` and is interpolated into the `LIMIT`
clause as an integer rather than bound. `QueueReports` names the keys the command actually reads: `id`,
`failed_at`, `queue`, `name`, `attempts`, `error`. All five are what [`staticphp queue status`, `failed`,
`retry` and `forget`](/staticphp-core/queue/queue-cli/) are built on, and every one of them reads rows
through a helper that takes an array or an object, so `PDO::FETCH_OBJ` works as well as `FETCH_ASSOC`.

## Where the schema comes from

Core ships no migration of its own. `staticphp queue install` copies the template matching the
connection's driver into the application's migrations directory, substituting the configured table names,
and names the file through
[`Discovery::newFilename()`](/staticphp-core/database/writing-migrations/#let-the-tool-name-it) so it
satisfies the pattern that tool globs for. The reasoning is the same as the audit trail's: `Discovery`
sorts by filename and `Tracker` checksums what it applied, so a framework-owned file would sort by
release date and turn every `composer update` into checksum drift on a file the application cannot edit.
The file is written, not applied. The command prints the path and tells you to review it, then run
`staticphp migrate apply`. See [the queue CLI page](/staticphp-core/queue/queue-cli/#install) for the
flags and the table-name substitution order, and [migrations](/staticphp-core/database/migrations/) for
what happens to the file afterwards. Retention of the failed table is left to the application - every
schema ends with the same two-line note pointing at `staticphp queue forget --before=YYYY-MM-DD`.
Nothing prunes it on its own, and a failed table nobody reads is a failed table nobody empties.
