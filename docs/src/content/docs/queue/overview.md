---
title: Overview
description: How the queue moves work out of the request, the pieces it is built from, and the design decisions behind the two tables and the two interfaces.
sidebar:
    order: 1
---

`StaticPHP\Utils\Models\Queue\Queue` is the facade for the job queue. The whole subsystem is
one loop with a table in the middle of it: the application calls `Queue::push()` with a job
name and an array payload, the driver writes a row holding that name, the payload as JSON,
a priority, an `available_at` and an attempt budget, and returns its id. Somewhere else, in a
process started by `staticphp queue work`, a `Worker` asks the same driver to reserve the
next due row - which stamps a `reserved_until` deadline on it and increments `attempts` -
resolves the job's name to a `Handler`, and calls `handle()` with the decoded payload. If
that returns normally the row is deleted. If it throws, the row goes back with a backoff
delay, or moves to the failed table once the attempts run out.

Nothing in `Queue` runs a job. The class docblock is blunt about why that separation is the
point:

> Pushing is what the application does, running is what `staticphp queue work` does in a
> process of its own, and the only thing joining them is a table. That separation is the
> point: the request returns as soon as the row is committed, and the work happens somewhere
> a timeout cannot reach it.

On the database driver the push is an ordinary insert on the application's own connection, so
it joins whatever transaction is already open there. That is the argument for keeping jobs in
the same database as the work that causes them, and it is an argument no other backend can
make:

```php
<?php

use StaticPHP\Utils\Models\Db;
use StaticPHP\Utils\Models\Queue\Queue;

Db::transaction(function () use ($invoice) {
    Db::insert('invoices', $invoice);
    Queue::push(SendInvoice::class, ['id' => Db::lastInsertId()]);
});
```

Either the invoice and its email both exist or neither does.

## The pieces

Everything lives in `StaticPHP\Utils\Models\Queue\`.

| Class | What it is |
| --- | --- |
| `Queue` | The static facade. Resolves settings and the driver, validates a job name before it is queued, and answers `backoff()`. See [enqueueing](/staticphp-core/queue/enqueueing/). |
| `QueueInterface` | The driver contract: `push`, `reserve`, `delete`, `release`, `fail`, `pending`. Six methods, and nothing a worker does not need. Documented alongside the driver that implements it, `QueueDatabase`. See [storage](/staticphp-core/queue/storage/). |
| `QueueReports` | The reporting contract - `stats`, `failedCount`, `failedRows`, `retryFailed`, `forgetFailed`. Five methods, kept deliberately apart from the driver contract. Documented alongside the driver that implements it. See [storage](/staticphp-core/queue/storage/). |
| `QueueDatabase` | Two tables on the application's own PDO connection. The default, and implements both interfaces. See [storage](/staticphp-core/queue/storage/). |
| `QueueRedis` | Redis streams with consumer groups, for volume the database cannot take. Also implements both. See [the redis driver](/staticphp-core/queue/redis/). |
| `Handler` | The job contract: one method, `handle(array $payload, Job $job): void`. Returning completes, throwing retries. See [handlers](/staticphp-core/queue/handlers/). |
| `Job` | One reserved attempt at one job - id, queue, name, payload, `attempts`, `maxAttempts` - plus `release()` and `isLastAttempt()`. See [handlers](/staticphp-core/queue/handlers/). |
| `Worker` | The run loop. Reserve, run, record, repeat, and all the ways it stops. See [workers](/staticphp-core/queue/workers/). |
| `Cli` and `Commands` | `staticphp queue`. `Cli` parses arguments and bootstraps; `Commands` holds the driver-agnostic logic and returns exit codes. See [the queue cli](/staticphp-core/queue/queue-cli/). |

`Handler` and `QueueInterface` are the only two contracts an application ever touches, and in
practice it only implements the first.

## Why reporting is a second interface

A driver has to be able to move jobs. Being able to answer questions *about* jobs is a
different capability, and the source treats it as one. `QueueInterface` says so in its own
docblock:

> Reporting - what is pending per queue, what failed and why, retrying a failed job - is
> deliberately not here. Those answers are shaped by the store: a table can be grouped and
> counted, a redis stream has to be totalled from its length and its consumer group. A driver
> that can answer them implements QueueReports as well, and the commands ask for both;
> keeping them apart means a backend that runs jobs without being able to list them is still
> a usable backend rather than one forced to fake half an interface.

The alternative - one fat interface - forces every driver to implement `failedRows()` even
when its store has no notion of a row, and the usual result is a method that returns an empty
array and lies about it. Splitting the two means the shape of the store decides what it can
answer, and the type system carries that decision. `Commands` takes the intersection:

```php
<?php

public function __construct(QueueInterface&QueueReports $queue, string $driver, callable $out)
```

So the commands that need reporting cannot be constructed over a driver that does not have
it, while `Worker` takes a plain `QueueInterface` and is happy with any backend at all.

`QueueReports` also fixes the *shape* of the answers rather than leaving each driver to
invent its own, and is candid about where that shape comes from:

> The shapes here are the database driver's, because that is the one somebody will already
> have read the table of. A driver that stores things differently answers in these terms
> anyway rather than inventing its own columns.

Both shipped drivers implement both interfaces, so this is architecture for a driver that
does not exist yet. It is still the right split: it is what lets one be written.

## Two tables, not one with a status column

The obvious schema is a single `jobs` table with a `status` column moving through pending,
running, done and failed. All three shipped schemas reject that, and each opens with the same
comment explaining why:

> Two tables rather than one with a status column. A finished job is deleted, so `queue_jobs`
> stays roughly the size of the backlog rather than the size of history, which is what keeps
> the reserve query cheap without any index tuning. Jobs that ran out of attempts move to
> `queue_failed_jobs`, where they can be read, retried or forgotten without ever being in the
> way of a worker.

That is the whole trade. With a status column, `queue_jobs` grows for as long as the
application runs, the reserve query has to be taught to ignore almost all of it, and keeping
that query fast becomes a partial-index-and-vacuum problem that someone has to notice before
it becomes an outage. With two tables, the live table is the backlog: an application that has
processed ten million jobs and has forty waiting has forty rows, and the index over
`(queue, priority DESC, available_at, id)` stays small enough that nobody ever has to think
about it.

The failed table is not a bin. It is the only durable record of a job, it keeps the payload as
written and the exception message and trace, and it is what `staticphp queue failed`, `retry`
and `forget` read. Both names are prefixed for a reason the schemas also state: `jobs` is a
word an application wants for its own work orders.

Retention is left to the application - nothing prunes `queue_failed_jobs` on its own. The
columns, the reserve query and the dead-lettering are covered on
[storage](/staticphp-core/queue/storage/).

## Choosing a driver

`database` is the default and the shipped config steers hard toward it:

> Start with 'database' and stay there unless something forces the issue. It is the only one
> where a push joins the transaction that caused it, so the job exists exactly when the work
> that needs it does; on redis a push is a write to a second system and the two can disagree.
> 'redis' is for volume the database genuinely cannot take, or for jobs you can afford to
> lose - it uses streams, so a worker that dies still hands its job back, but nothing makes a
> lost push reappear.

The redis driver's own docblock makes the same point against itself, which is the more
convincing version of it: streams solve the *delivery* problem - an entry stays in the
consumer group's pending list until it is acknowledged, and `XAUTOCLAIM` hands it back once
it has been idle longer than the visibility timeout, which is the same "a claim is a
deadline, not a flag" the database driver gets from `reserved_until`. What streams do not
solve is the two-writes problem: commit the invoice, fail to reach redis, and the email is
never queued; or queue the email, roll the invoice back, and it goes out for an invoice that
does not exist.

So the choice is not about throughput first. It is about whether losing a push is acceptable.
If it is not, use `database` and get atomicity for free. If the volume genuinely will not fit,
or the jobs are ones you can afford to lose, `redis` is there.

| | `database` | `redis` |
| --- | --- | --- |
| Push joins the caller's transaction | Yes | No |
| Requires | A PDO connection | `ext-redis`, redis 6.2 or newer for `XAUTOCLAIM` |
| Concurrent workers | Yes; sqlite serialises writers, so treat it as development only | Yes; not cluster aware, one server or a primary with replicas |
| Schema | `staticphp queue install` writes a migration | None; keys are created on demand |

Details on [storage](/staticphp-core/queue/storage/) and
[the redis driver](/staticphp-core/queue/redis/).

## Configuration

The shipped defaults live in `src/Utils/Config/Queue.php` and populate `$config['queue']`.
[Configuration](/staticphp-core/getting-started/configuration/#loading-more-config-files)
covers how to load one of this package's config files.

| Key | Default | Controls |
| --- | --- | --- |
| `driver` | `'database'` | Which backend `Queue::driver()` builds. Only `'database'` and `'redis'` are accepted. |
| `connection` | `'default'` | The entry of `$config['db']['pdo']` jobs are written to. Database driver only. |
| `table` | `'queue_jobs'` | Where pending jobs live. Database driver only. |
| `failed_table` | `'queue_failed_jobs'` | Where jobs go when they run out of attempts. Database driver only. |
| `redis` | see below | Connection and naming for the redis driver. Only read when `driver` is `'redis'`. |
| `queue` | `'default'` | The queue a `push()` lands on when it does not name one. |
| `tries` | `3` | Attempts before a job is moved to the failed table, counting the first run. |
| `backoff` | `[10, 60, 300]` | Seconds before the next attempt. A list is one delay per attempt and repeats its last entry; an integer is the same delay every time. |
| `timeout` | `300` | How long a worker's claim lasts, in seconds, and the best-effort time limit on the job itself. |
| `sleep` | `1` | Seconds a worker waits before looking again when there is nothing to do. |
| `handlers` | `[]` | Job names that are not class names, as `name => class-string` or `name => callable(): Handler`. |

The nested `redis` block:

| Key | Default | Controls |
| --- | --- | --- |
| `redis.hostname` | `'127.0.0.1'` | Server to connect to. |
| `redis.port` | `6379` | Port. |
| `redis.database` | `0` | Database number. Give the queue one of its own. |
| `redis.password` | `null` | `AUTH`, when the server wants it. |
| `redis.timeout` | `2` | Connect timeout, in seconds. |
| `redis.prefix` | `'queue:'` | Namespaces every key the queue owns, so two applications can share a server. |
| `redis.group` | `'workers'` | Consumer group name. |

Two of those defaults carry warnings worth repeating. `connection` should be the same
connection the work that queues the jobs runs on - pointing it at a separate database gives up
the transactional push and gains nothing else. And the `redis` block is deliberately its own
connection rather than the one `$config['cache']` uses, because "the cache turns on php
serialization and a queue that shares a database with a cache is one `FLUSHDB` away from an
empty backlog."

`timeout` does double duty and the config comment flags the conflict: it is the deadline after
which another worker may claim a job whose worker died, so it has to be longer than the
slowest job or a long job gets run twice concurrently - and it is also the per-job time limit,
which needs `ext-pcntl` and cannot interrupt a call blocked inside the database driver.

### The defaults are duplicated in the facade

`Queue` carries a private `DEFAULTS` constant holding the same keys, "so the queue works
before an application has written a config file for it." `Queue::setting()` merges
`$config['queue']` over those defaults key by key, memoises the result, and `Queue::reset()`
forgets both the merge and the built driver - which is what a test or a long-running process
that changes configuration needs.

The duplication is not exact, and the difference matters. `DEFAULTS` has `'redis' => []`,
where the config file ships a fully populated block. `Queue::setting()` merges
`$config['queue']` over `DEFAULTS` one key at a time - `$merged[$name] = $value` for every key
present in the loaded config - so the merge never descends into a nested array. Only an
application that never loads the config file at all, or whose loaded `queue` array simply has
no `redis` key, falls through to `DEFAULTS`'s `'redis' => []`. An application that loads the
config file and then sets `'redis'` to a partial array does not get emptied out: that partial
array replaces the shipped block verbatim and is kept exactly as written -
`QueueDriverTest::testSettingArrayKeepsWhatTheApplicationConfigured()` asserts a two-key
`'redis' => ['hostname' => 'cache-1', 'port' => 6380]` comes back from `Queue::settingArray()`
unchanged, not merged with the shipped block's other five keys and not discarded. A partial
block simply does not inherit the shipped siblings it left out. `QueueRedis::connect()` supplies
its own fallbacks for the individual keys, so a missing key is not fatal either way, but the two
lists are not interchangeable and only the config file's is the documented set of defaults.
Treat the config file as the reference and set the `redis` block explicitly, in full, when you
use that driver.

## Errors

```php
<?php

class QueueError extends \RuntimeException
```

The docblock is one line and it is the entire design of the class: thrown "for a queue that is
configured or called wrongly, never for a job that failed." A job that throws is not an error
condition of the queue - it is the ordinary path, handled by releasing the job for another
attempt or moving it to the failed table. `QueueError` means the queue itself cannot do its
job, and it is not something an application catches around a `push()` and recovers from; it is
something a deploy fixes. See [errors](/staticphp-core/core/errors/) for how the framework
treats uncaught exceptions generally.

What raises it:

- **The driver name is not `database` or `redis`.** From `Queue::build()`.
- **A job name that nothing can run**, refused by `Queue::push()` before anything is written:
  an empty name, a name that is neither a `handlers` key nor an existing class, or a class
  that does not implement `Handler`. Checked at the call site on purpose - "a name that
  resolves to nothing fails on every attempt and then sits in the failed table. Better to know
  at the call site, while there is still a stack trace worth reading."
- **A `handlers` entry that will not produce a handler**: a callable that returns something
  other than a `Handler`, a string naming a class that does not exist, or one that is not a
  `Handler`. From `Queue::handler()`, which is what the worker resolves through.
- **A configured table name that is not a plain identifier.** `QueueDatabase` concatenates
  table names into its queries, so it validates them against a pattern in its constructor
  rather than trusting configuration.
- **A payload that will not encode as JSON.** Both drivers refuse it at push time.
- **Redis being unreachable or unusable**: `ext-redis` not loaded, the connection failing, a
  lua script erroring, or a `--before` value that is not a date the driver can read.

One case sits outside that description and is worth knowing about. When a job overruns
`timeout`, the worker's `SIGALRM` handler throws `QueueError('The job ran past the timeout')`
*into* the running job, and the worker then catches it exactly as it catches anything else a
handler throws. So a `QueueError` can appear in the failed table's `error` column despite the
docblock's "never for a job that failed" - the exception is still reporting a queue-level
condition, but it reaches the job rather than the caller.

## Getting started

Four steps, in order. The database driver needs its tables and nothing creates them for you:

```bash
staticphp queue install
staticphp migrate apply
```

`queue install` copies the schema for the configured PDO driver into the migrations directory
as an ordinary migration, substituting your configured table names, and prints where it put
it; `migrate apply` runs it. On the redis driver it prints that there is nothing to install and
exits successfully. Both are covered under
[the queue cli](/staticphp-core/queue/queue-cli/#install) and
[the migrations cli](/staticphp-core/database/migrations-cli/#apply).

Then write a handler:

```php
<?php

namespace Application\Jobs;

use StaticPHP\Utils\Models\Queue\Handler;
use StaticPHP\Utils\Models\Queue\Job;

class SendInvoice implements Handler
{
    public function handle(array $payload, Job $job): void
    {
        // Load the record by id and do the work.
    }
}
```

Push it from wherever the work is caused:

```php
<?php

use StaticPHP\Utils\Models\Queue\Queue;

Queue::push(\Application\Jobs\SendInvoice::class, ['id' => 42]);
```

The payload holds identifiers and arguments, not objects: it round-trips through JSON, and a
job row outlives the deploy that wrote it. See [enqueueing](/staticphp-core/queue/enqueueing/)
for delay, priority, uniqueness and per-job attempt budgets, and
[handlers](/staticphp-core/queue/handlers/) for name resolution and the `Job` object.

Finally, run something that will pick it up:

```bash
staticphp queue work
```

That is a long-lived process, meant for systemd or supervisord. If the host will not run a
daemon, `staticphp queue work --max-time=55` from cron every minute does the same job with
up to a minute of latency. Neither is a fallback for the other - see
[workers](/staticphp-core/queue/workers/) for the loop, the signals and the limits, and
[the cli](/staticphp-core/skeleton/cli/) for how `staticphp` finds these commands.
