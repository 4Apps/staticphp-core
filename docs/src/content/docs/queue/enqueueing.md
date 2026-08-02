---
title: Enqueueing
description: Queue::push() and every option it takes, plus backoff and pending().
sidebar:
    order: 2
---

`StaticPHP\Utils\Models\Queue\Queue::push()` is the one method an application calls to put
work on the queue. Everything else on `Queue` - `driver()`, `handler()`, `backoff()`,
`pending()` - exists to serve that call or to answer questions about what it did. There is no
builder, no `later()`, no tagging: one method, seven parameters, all with defaults sane enough
that `Queue::push(SendInvoice::class, ['id' => 42])` is a complete call.

## `push()`

```php
<?php

public static function push(
    string $name,
    array $payload = [],
    int $delay = 0,
    ?string $queue = null,
    int $priority = 0,
    ?string $unique = null,
    ?int $tries = null
): int;
```

| Parameter | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$name` | `string` | required | Handler class, or a key of `config['queue']['handlers']` |
| `$payload` | `array<string, mixed>` | `[]` | Identifiers and arguments, not objects |
| `$delay` | `int` | `0` | Seconds before the job is eligible to run |
| `$queue` | `?string` | `null` | `null` uses `config['queue']['queue']` |
| `$priority` | `int` | `0` | Higher runs first |
| `$unique` | `?string` | `null` | At most one pending job per key, when set |
| `$tries` | `?int` | `null` | `null` uses `config['queue']['tries']`; this is `maxAttempts` |

It returns the new job's id, or - when `$unique` collides with a job already pending - the id
of that existing job.

A minimal push, and one that uses every option:

```php
<?php

use StaticPHP\Utils\Models\Queue\Queue;

Queue::push(SendInvoice::class, ['id' => 42]);

Queue::push(
    RebuildCatalog::class,
    ['scope' => 'all'],
    delay: 60,
    queue: 'mail',
    priority: 10,
    unique: 'rebuild-all',
    tries: 1
);
```

## Name resolution happens before the driver is touched

Before `push()` calls the driver at all, it runs `assertResolvable($name)`. The comment on
`Queue::push()` explains why this check exists rather than being left to the worker:

> Checked here rather than left for the worker, because a name that resolves to nothing fails
> on every attempt and then sits in the failed table. Better to know at the call site, while
> there is still a stack trace worth reading.

`assertResolvable()` accepts `$name` in exactly two shapes: a key present in
`config['queue']['handlers']`, or a class that implements
[`Handler`](/staticphp-core/queue/handlers/). Anything else throws `QueueError`, with a
message specific to what was wrong:

| Condition | Exception message |
| --- | --- |
| `$name` is `''` | `A job needs a handler name` |
| Not a configured alias, and not an existing class | `Cannot queue "{$name}": no such class, and no config['queue']['handlers'] entry for it` |
| An existing class that does not implement `Handler` | `Cannot queue "{$name}": it does not implement StaticPHP\Utils\Models\Queue\Handler` |

`QueueTest::testPushRefusesAJobNameNothingCanRun()` asserts the message contains `no such
class`, and `testPushRefusesAClassThatIsNotAHandler()` asserts it contains `does not
implement` - both against `Queue::push()` directly, before any row exists.

A configured alias short-circuits the whole check: `assertResolvable()` returns as soon as
`array_key_exists($name, self::handlers())` is true, without touching `class_exists()` at
all. That is what lets `config['queue']['handlers']` name a class that was renamed or removed
after the row was written, and still let new pushes under the old alias through.

Failing here rather than at run time matters because the two failure points have very
different costs. A worker that discovers a bad name has already reserved the row, burns an
attempt, and - once attempts run out - leaves the job in the failed table for someone to find
later, with nothing more informative than the name that didn't resolve. `push()` failing
means the exception surfaces at the call site, in the request or job that tried to queue the
work, with a stack trace pointing at the actual mistake - a typo in a class name, a handler
that was refactored without updating the alias.

## `$delay`

Seconds before the job becomes eligible. The row is written immediately - `push()` still
returns an id right away - but a delayed job does not exist yet as far as a worker or
`pending()` are concerned. `QueueDatabase::push()` stores `available_at` as `now + $delay`,
and both `reserve()` and `pending()` filter on `available_at <= now`.

`QueueDatabaseTest::testADelayedJobIsInvisibleUntilItIsDue()` pushes a job with
`delay: 3600` and then asserts all three things at once: `reserve()` returns `null`,
`pending()` returns `0`, and the row is still there -

```php
<?php

$this->queue->push('later', [], 3600, 'default', 0, null, 3);

$this->assertNull($this->queue->reserve(['default'], 60, 'worker-1'));
$this->assertSame(0, $this->queue->pending());
$this->assertCount(1, $this->rows(), 'it is queued, just not yet');
```

The row existing but not counting is deliberate: a delayed job is queued work, not a
scheduled event that materialises later, and `SELECT * FROM queue_jobs` will show it to
anyone who goes looking.

## `$queue`

Names are just strings - nothing declares a queue in advance. Left at `null`, `push()` falls
back to `config['queue']['queue']` (`'default'` unless overridden). A worker watches one or
more queues **in precedence order**, so which named queue a job lands on decides which
workers can even see it and, when several are watched together, how it competes for their
attention. See [workers](/staticphp-core/queue/workers/) for how a worker's
`--queue=high,default` list is read.

## `$priority`

Higher runs first. Same-priority jobs stay FIFO, ordered by `available_at, id` - the same
tuple the reserve query sorts by after `priority DESC`. `idx_queue_jobs_reserve` is built over
exactly `(queue, priority DESC, available_at, id)`, so that ordering costs an index scan
rather than a sort; see [storage](/staticphp-core/queue/storage/) for the index and the
query it serves.

`QueueDatabaseTest::testHigherPriorityGoesFirstAndTiesStayInOrder()` pushes a low-priority
job, then two jobs at priority `10` in the same call order, and reserves three times:

```php
<?php

$this->assertSame([$high, $alsoHigh, $low], $order);
```

The two ties come out in the order they were pushed.

## `$unique`

The subtlest option. Setting `$unique` to a non-empty string makes the push idempotent
against **pending** jobs only: a second `push()` with the same key, while the first job with
that key is still sitting in `queue_jobs`, does not insert a second row - it returns the id of
the row already holding the key.

```php
<?php

$first = Queue::push(RebuildCatalog::class, ['scope' => 'all'], unique: 'rebuild-all');
$second = Queue::push(RebuildCatalog::class, ['scope' => 'all'], unique: 'rebuild-all');

// $second === $first, and only one row exists
```

`QueueDatabaseTest::testAUniqueKeyCollapsesRepeatedPushes()` asserts exactly that: the two ids
are equal and `$this->rows()` counts one.

"Pending" is a precise word here, not a loose one. The key is enforced by a unique index on
`unique_key` (`idx_queue_jobs_unique_key`), and the column is cleared the moment the row
leaves `queue_jobs` - whether that is because a worker deleted it on success, or because it
was moved to `queue_failed_jobs` on final failure. `queue_failed_jobs` has no `unique_key`
column at all. `QueueDatabaseTest::testTheSameUniqueKeyIsFreeAgainOnceTheJobIsDone()` pushes,
reserves, deletes, and then pushes the same key again and gets a fresh id back - the key was
never "used up", only held for as long as a row under it existed in the jobs table.

The corollary the code is explicit about: **a retried failed job does not get its
`unique_key` back**. `QueueDatabase::retryFailed()` inserts the requeued row with
`unique_key` set to `null`, and the comment on the method says why:

> The retried job starts over with a fresh attempt count, because the reason it failed is
> usually something that was fixed outside it. It does not get its unique_key back - that
> column belongs to the pending job, and the key may well have been reused while this one sat
> in the failed table.

So a job that failed and then got retried by hand can end up running alongside a newer job
that reused the same unique key in the meantime - both are legitimate rows, and the queue no
longer has any record that they once shared a key.

An empty string is treated the same as `null` - `push()` does not enforce uniqueness for `''`.

## `$tries`

This is `maxAttempts` on the driver interface - the parameter is literally renamed crossing
that boundary, from `$tries` on `Queue::push()` to `$maxAttempts` on
`QueueInterface::push()`. Left at `null`, it falls back to `config['queue']['tries']`
(`3` by default). The config file's comment on `tries` is worth quoting directly, because it
states the counting convention plainly:

> Attempts before a job is moved to the failed table. Counts the first run, so 3 means one
> attempt and two retries.

So `tries: 1` is not "retry once" - it is "do not retry at all". `QueueDatabase::push()`
floors the stored value at `max(1, $maxAttempts)`, so `0` or a negative value cannot produce a
job with no attempts at all.

## `Queue::backoff()`

```php
<?php

public static function backoff(int $attempt): int;
```

`backoff()` is not called by `push()` - it is read by a worker between a failed attempt and
the next one, off `config['queue']['backoff']`. It is documented here because it is the
other half of the retry story that `$tries` starts, and this page owns everything about
`Queue`'s static configuration surface.

`config['queue']['backoff']` is either a plain int or a list. A plain int is a constant delay
for every attempt. A list gives one delay per attempt, indexed from the first retry, and
**repeats its last entry** for every attempt past the end of the list - it does not fall back
to `0` or throw. For the shipped default, `[10, 60, 300]`:

| `$attempt` | `backoff($attempt)` |
| --- | --- |
| `1` | `10` |
| `2` | `60` |
| `3` | `300` |
| `9` | `300` |

`QueueTest::testBackoffWalksTheListThenRepeatsItsLastEntry()` asserts that table directly,
including `Queue::backoff(9)` still returning `300`. A constant int is covered separately by
`testBackoffAcceptsOneDelayForEveryAttempt()`, which configures `backoff: 30` and asserts both
`backoff(1)` and `backoff(5)` return `30`.

The config comment adds the operational reasoning for the shipped shape:

> The first delay matters most: retrying a transient failure immediately usually just fails
> again, and having done so counts against the attempts.

## Pushing inside a transaction

This is the property the whole database driver exists to give you.
`QueueDatabase::push()` wraps its insert in `Db::transaction()`:

```php
<?php

$id = Db::transaction(fn(): int => $this->insert($row), $this->connection);
```

`Db::transaction()` nests as a savepoint rather than a new transaction when one is already
open on that connection - see [`Db`](/staticphp-core/database/db/). That is what lets
`Queue::push()` be called from inside the caller's own `Db::transaction()` block safely: the
insert becomes part of whatever the caller is already doing, and if the caller's transaction
rolls back, the queued row rolls back with it.

```php
<?php

Db::transaction(function () use ($invoice) {
    Db::insert('invoices', $invoice);
    Queue::push(SendInvoice::class, ['id' => Db::lastInsertId()]);
});
```

Either the invoice and its email both exist, or neither does - no other queue backend can
make that claim, because no other backend is the same database.
`QueueDatabaseTest::testAPushInsideARolledBackTransactionQueuesNothing()` proves the failure
side directly: a push followed by a thrown exception inside `Db::transaction()` leaves
`queue_jobs` empty.

The savepoint nesting has a second, narrower purpose: it insulates the caller from a
`unique_key` collision. `push()`'s insert is wrapped even though it is one statement, because
on Postgres a duplicate `unique_key` fails the statement, and a failed statement poisons
whatever transaction it ran in - nothing else in that transaction can run until the failure is
undone. Rolling back *to a savepoint* is exactly what undoes it: the abort is scoped to the
savepoint, not to the transaction as a whole, which is the entire reason `push()` nests through
`Db::transaction()` rather than trusting the caller's own transaction to survive the collision
unguarded. Without that nesting, a duplicate `unique_key` would abort the caller's outer
transaction the moment `push()` collided - the invoice write goes down with the duplicate push.
With it, `Db::transaction()` rolls back to the savepoint itself before rethrowing, so by the time
`push()` catches the `PDOException` the caller's transaction is already clean; `push()` then
looks the existing row up by `unique_key` and returns its id instead, and the caller can keep
writing as if the collision never happened. See [storage](/staticphp-core/queue/storage/) for
the driver-side comment on the same wrapping.

## Payload rules

`$payload` must be JSON-encodable. `QueueDatabase::encode()` calls:

```php
<?php

json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
```

and turns a `\JsonException` into `QueueError`: "A job payload has to be JSON encodable:
{message}". `QueueDatabaseTest::testAPayloadThatCannotBeEncodedIsRefused()` pushes a payload
containing an open file resource and asserts `QueueError` is thrown - the failure surfaces at
the `push()` call site, inside whatever transaction it was called from, same as an
unresolvable name.

The schema comment on the `payload` column states the reason it is JSON rather than
`serialize()`, beyond portability:

> JSON, not serialize(). It survives a deploy that changes the class, it can be read in a
> SELECT while somebody is asking why a job did not run, and it cannot be turned into an
> object-injection gadget by anything that manages to write to this table.

Payloads should be identifiers and arguments - an invoice id, a scope string - not objects.
Nothing enforces that beyond "must be JSON-encodable", but a payload holding a live resource
or an object with no meaningful JSON form is exactly the case `push()` rejects.

## `Queue::pending()`

```php
<?php

public static function pending(?string $queue = null): int;
```

Delegates straight to the driver. On `QueueDatabase`, it counts rows where
`available_at <= now` and `reserved_until` is either `NULL` or in the past - so it excludes
both delayed jobs that are not yet due and jobs another worker currently holds. Passing
`$queue` restricts the count to one named queue; `null` counts every queue. This is the
backlog a worker would see if it looked right now, not the row count of the table -
`QueueDatabaseTest::testPendingCountsOnlyWhatCouldRunNow()` pushes four jobs across two
queues, one of them delayed, and gets `3` back before any are reserved, then `2` after one
is reserved.

## The rest of `Queue`'s static surface

A handful of other static methods round out `Queue`, mostly for tests and tooling rather than
for application call sites:

- **`Queue::driver(): QueueInterface`** - the configured backend, built once from
  `config['queue']['driver']` and cached. `push()` and `pending()` both go through this.
- **`Queue::setDriver(?QueueInterface $driver): void`** - replaces the cached backend
  directly. This is how the test suite substitutes a driver without touching config, and how
  an application that assembles its own `QueueInterface` implementation installs it.
- **`Queue::reset(): void`** - forgets both the cached driver and the cached settings, so the
  next call re-reads `config['queue']` from scratch. Needed after changing
  `Config::$items['queue']` mid-process, which is normal in a test but not in a request.
- **`Queue::build(string $driver): QueueInterface`** - constructs a fresh backend for a given
  driver name (`'database'` or `'redis'`) without touching the cache; throws `QueueError` for
  anything else.
- **`Queue::settingString()` / `Queue::settingInt()` / `Queue::settingArray()` /
  `Queue::setting()`** - typed accessors over the merged `config['queue']` settings, used
  internally by `push()`, `driver()` and `backoff()` and available to application code that
  wants to read the same configuration the queue itself reads.
