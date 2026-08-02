---
title: The redis driver
description: QueueRedis keeps jobs in redis streams read through a consumer group, with delays, priority and uniqueness built on top by hand.
sidebar:
    order: 6
---

`StaticPHP\Utils\Models\Queue\QueueRedis` is the second backend. It implements both
`QueueInterface` and `QueueReports`, so everything on
[the worker](/staticphp-core/queue/workers/) and every
[`staticphp queue` subcommand](/staticphp-core/queue/queue-cli/) works over it unchanged - the
commands know nothing about which driver is underneath, which is what
`QueueRedisCommandsTest` exists to prove.

It is not the default, and the shipped config steers away from it in as many words:
[choosing a driver](/staticphp-core/queue/overview/#choosing-a-driver) has the quote. The
driver's own docblock argues the same case against itself, which is worth reading before you
switch:

> What it does not get you is the thing the database driver is for. A push here is a second
> write to a second system, so it cannot join the transaction that caused it: commit the
> invoice, fail to reach redis, and the email is never queued - or queue the email, roll the
> invoice back, and it goes out for something that does not exist. Nothing about streams
> addresses that, and no amount of redis durability settings will.

What it does buy is a queue that is not the application's database. Reserving is one lua
script per poll instead of a transaction holding a row lock; a thousand workers polling once a
second is a thousand cheap script calls rather than a thousand `SELECT ... FOR UPDATE`s
against the table your requests are also using. Reach for it when the volume genuinely
warrants it, or when the jobs are ones you can afford to lose.

## Streams, not lists

The choice inside the driver is streams over lists, for the reason behind `reserved_until` in
[the database schema](/staticphp-core/queue/storage/#the-two-tables): "a list hands a job out
and forgets it: the worker that popped it and then died took the job with it." A stream keeps
every delivered entry in the consumer group's pending list until somebody acknowledges it, and
`XAUTOCLAIM` hands one back once it has been idle longer than the visibility timeout - the
same "a claim is a deadline, not a flag".

Every mutation - push, reserve, complete, release, fail, requeue - is one lua script, sent once
and called by SHA afterwards. `NOSCRIPT` from a redis that restarted or was `SCRIPT FLUSH`ed
makes the driver resend the body and retry once, transparently
(`testTheDriverRecoversWhenRedisHasForgottenItsScripts`). Script bodies are class constants and
nothing caller-supplied is spliced into one: job names, payloads and queue names arrive as
`ARGV`, which redis passes as data and never parses as lua.

## The key layout

Every key is namespaced by `$config['queue']['redis']['prefix']`, so two applications can
share a server (`testTwoDriversOnOneServerDoNotSeeEachOther`). With the default prefix
`queue:`:

| Key | Type | Holds |
| --- | --- | --- |
| `queue:j:{id}` | hash | the job - the same fields the database driver's row has |
| `queue:q:{queue}:s:{n}` | stream | the ids that are ready, one stream per priority level `n` |
| `queue:q:{queue}:levels` | sorted set | the priorities that queue has streams for, scored by the priority |
| `queue:q:{queue}:delayed` | sorted set | ids scored by the unix second they may run |
| `queue:u:{key}` | string | the job id currently holding a unique key |
| `queue:failed` | sorted set | ids scored by when they failed |
| `queue:queues` | set | queue names, so `status` can enumerate them |
| `queue:seq` | string | the id counter, `INCR`ed once per push |

That is the complete list; the driver creates nothing else.

**The stream carries the job id in one field, `job`, and nothing else.**

> Everything mutable - attempts above all - lives in the hash, because a stream entry cannot be
> edited and a retry that has to be delayed has to leave the stream anyway. So the stream is an
> index of what is ready and the hash is the job; losing track of that is the fastest way to
> misread the code below.

The hash a push writes, with every field:

```text
queue           default
name            Application\Jobs\SendInvoice
payload         {"id":42}
attempts        0
max_attempts    3
priority        0
unique_key      nightly-import        (or '')
available_at    1785649677            (unix seconds)
reserved_until  0
reserved_by                           (worker id, cut to 64 characters, while held)
last_error
created_at      2026-08-02 05:47:57   (UTC)
entry           1785649677469-0       (the stream entry id, or '' when not in a stream)
```

Timestamps are split on purpose: `available_at`, `reserved_until` and the sorted set scores
are unix seconds because lua compares them, while `created_at` and `failed_at` are
`Y-m-d H:i:s` in UTC because a human reads them and the database driver spells them the same
way.

## How a job is claimed

The database driver's claim is a candidate `SELECT` followed by a guarded `UPDATE`, and the
guard is what makes it safe - see
[how a job is claimed](/staticphp-core/queue/storage/#how-a-job-is-claimed). Here the guard is
the consumer group: `XREADGROUP` delivers each entry to exactly one reader, so two workers
calling `reserve()` at the same moment cannot be handed the same entry. There is no retry loop
and no equivalent of `CLAIM_ROUNDS`, because there is no race to lose.

One `RESERVE` script per queue in the list, in the order given, does three things in one round
trip:

1. **Promote what is due.** `ZRANGEBYSCORE` over `q:{queue}:delayed` up to now, at most 50 ids
   (`PROMOTE_LIMIT`), each `XADD`ed into its priority's stream with its `entry` field updated.
2. **Walk the priorities, highest first.** `ZREVRANGE` over `q:{queue}:levels`. The group is
   created lazily per stream with `XGROUP CREATE ... 0 MKSTREAM` - **at id 0 rather than `$`,
   because jobs are pushed long before any worker starts and `$` would skip every one of
   them.**
3. **Take an abandoned entry before a new one.** `XAUTOCLAIM ... COUNT 1` first, then
   `XREADGROUP ... >` if that found nothing. Reclaiming first is why a worker that died does
   not leave its job at the back of the queue.

A claim increments `attempts`, writes `reserved_by` and `reserved_until = now + timeout`, and
returns the id, the entry id and the whole hash in one reply. The entry id is what the driver
puts in `Job::$handle` - the database driver leaves that empty and finds its row by id, but
acknowledging a stream entry needs the id the stream gave it.

There is one consumer name for the whole fleet, the constant `'shared'`. Worker ids are
host:pid and differ on every restart, "which would leave the group's consumer list growing a
dead name per deploy"; the group hands out distinct entries whatever name asks for them, and
idle time is kept per entry rather than per consumer, so sharing one name costs nothing. Who
actually holds a job is in the job's own `reserved_by`, which is what you read when something
is stuck.

### Recovering a job a dead worker was holding

`XAUTOCLAIM` decides what is abandoned by how long an entry has gone unacknowledged, measured
against the *reclaiming* worker's timeout rather than the one the original holder was running.
So `reserved_until` on the hash gets the last word, and the script has three branches:

| The reclaimed entry's hash | What happens |
| --- | --- |
| gone | `XACK` and `XDEL` the entry; it is a tombstone |
| `reserved_until` still in the future | the job is put back in `q:{queue}:delayed` scored at `reserved_until`, its entry acknowledged and deleted, and **`attempts` is not incremented** |
| `reserved_until` in the past, or zero | claimed: attempts up, `reserved_by` and `reserved_until` rewritten, hash returned |

The middle branch is the surprise, and it is the point:
`testAReclaimArrivingBeforeTheDeadlineParksTheJobInstead` pins it - a worker with a
one-second timeout that reclaims a job held by a worker with a thirty-second timeout parks the
job until that deadline instead of running it a second time. "Parking is not an attempt", as
the test says. The reverse case - a dead worker that used a shorter timeout than the one
reclaiming - is not an error, only a wait. The way to avoid both is to run one
`$config['queue']['timeout']` across the fleet.

`testAJobIsReclaimedOnceItsReservationRunsOut` covers the ordinary case: a worker takes a job
with a one-second claim, never comes back, and the next reserve after that second returns the
same id on its second attempt.

## Delays, priority and uniqueness

Streams give none of these. Each is built by hand here, and each is close to the behaviour
[`Queue::push()`](/staticphp-core/queue/enqueueing/) documents without being identical to the
database driver's.

### Delay

A push whose `available_at` is later than now goes into `q:{queue}:delayed` instead of a
stream, and no stream entry exists for it until a reserve promotes it
(`testADelayedJobWaitsForItsTime` asserts the stream length is 0). Promotion is work a
reserving worker does, capped at 50 per call:

> A bound rather than "all of them" because a scheduled burst - ten thousand jobs all due at
> midnight - would otherwise hold the server inside one script while it moved them. Fifty per
> poll drains a burst in seconds and leaves redis answering everybody else in between.

The consequence is that **nothing promotes a delayed job except a worker reserving from that
exact queue.** A delayed job on a queue no worker watches stays in the sorted set. On the
database driver there is nothing to promote - `available_at <= now` is just a predicate in the
reserve query - so the difference only shows up in how a burst drains, not in whether a job
eventually runs.

The delayed set is also where a released job goes, and where a parked job goes.

### Priority

One stream per priority level, with `q:{queue}:levels` recording which levels exist. Reserve
walks them highest first, so a level-10 job beats a level-0 job, and jobs at the same level
stay in the order the stream took them (`testHigherPriorityRunsFirstAndTiesStayInOrder`).
Negative priorities work: the level is the sorted set's score, so `-5` sorts below `0`.

The tie-break is where this differs from the database. There, equal-priority jobs sort by
`available_at, id`, so a job that became due earlier goes first. Here, order within a level is
insertion order into the stream, and a delayed job joins the back of the stream at the moment
it is promoted - not at the position its due time would have given it. Same rule for a
released job. FIFO within a level, but the clock does not order the queue.

### Uniqueness

`u:{key}` holds the id of the job that owns the key, and the check and the take both happen
inside the `PUSH` script:

> The unique key is checked and taken inside the script, because doing it in two round trips
> is exactly the race the key exists to prevent.

A second push with a live key returns the first job's id and writes nothing
(`testAUniqueKeyCollapsesTwoPushesIntoOne` - the first push is the one that stands, payload
included). The key is deleted when the job completes and when it fails, so it is free again in
both cases. A key pointing at a job hash that is no longer there is treated as free and
overwritten, which "only happens if something removed the hash by hand, and refusing to queue
for ever afterwards would be the worse answer".

This is the same contract the database driver gets from its unique index on `unique_key`, and
the same scope: keys are global to the prefix, not per queue.

## Failure and the dead-letter path

There is no second structure to move a job into, and that is deliberate:

> The hash stays exactly where it was and joins the failed set instead of being copied
> anywhere, so `queue retry` puts back the job that failed rather than a reconstruction of it.

`fail()` acknowledges and deletes the stream entry, frees and clears `unique_key`, clears
`reserved_until`, `reserved_by` and `entry`, adds two fields to the hash and adds the id to
`queue:failed` scored by the unix second it failed:

| Field | Set by `fail()` |
| --- | --- |
| `error` | the message, cut to 60000 **bytes** - `strlen()`/`substr()`, not characters, so a multi-byte trace can be cut mid-character - with `\n... truncated` appended |
| `failed_at` | `Y-m-d H:i:s`, UTC |

`error` and `last_error` are two different fields. `last_error` is what `release()` writes -
why the previous attempt did not stick, readable while a job is still retrying - and `fail()`
does not touch it. The commands read `error`.

**A payload that will not decode is failed on the spot**, the same as an unreadable row on the
database driver: `reserve()` fails the job with `Payload is not valid JSON, so no handler
could be given it.` and returns `null`, so the worker sees an empty queue rather than a job it
cannot run.

### retry and forget without a second table

`retryFailed()` runs the `REQUEUE` script per id: `ZREM` from `queue:failed`, then reset
`attempts` to 0, `max_attempts` to what was passed, `priority` to `0`, `unique_key` to `''`,
clear `error` and `failed_at`, `XADD` into `q:{queue}:s:0` and re-register level 0. The terms
are the database driver's, for the same stated reasons - a fresh attempt count "because
whatever broke was usually fixed outside the job", and no priority or unique key "because both
belonged to the push and the key may well have been reused since". The job keeps its own queue
and its own id (`testRetryingAFailedJobStartsItOverOnItsOwnQueue`).

Two rough edges here, both verified against a live server:

- **`retryFailed()` does not check that the id is in the failed set.** `ZREM` returning 0 is
  not a reason to stop; the script checks whether the hash exists and requeues it if it does.
  So `staticphp queue retry --id=N` naming a *pending* job resets its priority and attempts and
  adds a second stream entry, while the original entry stays where it was. On the database
  driver the same mistake is a no-op, because ids in the failed table are that table's own -
  redis ids never change, so the two id spaces look alike and are not.
- **`forgetFailed(null, null)` deletes every failed job here too**, with no guard at the
  driver level - see [`queue forget`](/staticphp-core/queue/queue-cli/#forget).

`forgetFailed()` removes the id from `queue:failed` and deletes the job hash. Given an id that
is not in the set, it deletes nothing and returns 0. `--before` is parsed as `Y-m-d` or
`Y-m-d H:i:s` read as UTC and compared against the set's scores; anything else throws
`QueueError` (`testForgettingRejectsADateItCannotRead`).

## The reporting half

`QueueReports` is answered from the structures above rather than from a table, and
[the shapes are the database driver's](/staticphp-core/queue/storage/#the-reporting-half)
because that is the one somebody will already have read.

`stats()` iterates `queue:queues`, sorted, and counts each queue four ways, skipping any whose
total is 0:

| Column | How it is counted |
| --- | --- |
| `pending` | `XLEN` of every level's stream, minus the group's pending count, plus the delayed entries whose score is `<= now` |
| `delayed` | `ZCARD` of the delayed set minus those due |
| `reserved` | the `XPENDING` summary count, per stream, summed |
| `total` | the three added |

`pending(?string $queue)` is the `pending` figure for one queue or for all of them.

Two things follow from `reserved` being the group's pending list. It counts **every entry a
worker was handed and has not acknowledged, including one whose claim has already run out** -
that job is claimable right now but reads as reserved until somebody reclaims it, where on the
database driver `reserved_until <= now` flips it back to pending immediately. The driver says
why it settles for that: "reading the exact split would mean asking for every pending entry's
idle time, which is a lot of work to move a job one column left in a status table." And a
stream pushed to but never worked has no group yet - `XPENDING` on a missing group is an error,
so an absent group counts as nobody holding anything, and reporting never creates one.

`failedCount()` is `ZCARD` of `queue:failed`. `failedRows($limit)` is `ZREVRANGE` over the
same set - newest first - followed by an `HGETALL` per id, with the id added back under `id`.
Rows whose hash is gone are skipped. The limit floors at 1, so `failedRows(0)` returns one row
rather than none.

## Configuration

The whole block is read only when `driver` is `'redis'`, and only by `QueueRedis::connect()`.
The general table is on [the overview](/staticphp-core/queue/overview/#configuration); what
follows is what the driver does with each key.

```php title="Application/Config/Queue.php"
<?php

$config['queue']['driver'] = 'redis';

$config['queue']['redis'] = [
    'hostname' => '127.0.0.1',
    'port' => 6379,
    'database' => 3,
    'password' => null,
    'timeout' => 2,
    'prefix' => 'queue:',
    'group' => 'workers',
];
```

| Key | Default in `connect()` | Effect |
| --- | --- | --- |
| `hostname` | `'127.0.0.1'` | passed to `Redis::connect()` |
| `port` | `6379` | passed to `Redis::connect()` |
| `timeout` | `2` | **connect** timeout in seconds, cast to float. Nothing to do with `$config['queue']['timeout']`, which is both the visibility timeout and the deadline the worker arms `pcntl_alarm()` with before running a handler - see [workers](/staticphp-core/queue/workers/) |
| `password` | `''` (no `AUTH`) | when non-empty, `AUTH`. The shipped file has `null`, which reads as absent |
| `username` | `''` | read by `connect()` but **not in the shipped config file**. When both are set, `AUTH [user, password]`; otherwise the password alone |
| `database` | `0` | `SELECT`ed only when it is not 0 |
| `prefix` | `'queue:'` | namespaces every key in the table above |
| `group` | `'workers'` | consumer group name. An empty string falls back to `'workers'` |

Any value of the wrong type falls back to the default rather than throwing: a non-numeric
`port` becomes 6379, a non-string `prefix` becomes `queue:`. A connection that cannot be made
throws `QueueError` - `Could not reach redis at {host}:{port}` - and every later command is
wrapped too, so a dead server reaches the worker as `The queue could not reach redis: ...`
rather than an uncaught `RedisException`. That matters because
[the worker treats a throw from `reserve()` as "the queue is unreadable" and backs
off](/staticphp-core/queue/workers/#reserve-failures-are-not-job-failures).

The connection is built here rather than borrowed from `Cache\CacheRedis`, "because that one
turns on the php serializer, which would rewrite every value this driver puts in a stream
field, and because a cache is a thing you are allowed to lose."

### The empty default block

`Queue::DEFAULTS` carries `'redis' => []` where the config file ships all seven keys, and the
merge that reconciles them is one level deep, so whatever the application sets for `redis`
replaces the block whole rather than filling gaps in it - a partial array survives exactly as
written, and an application that never loads the config file hands `connect()` an empty one.
[The defaults are duplicated in the facade](/staticphp-core/queue/overview/#the-defaults-are-duplicated-in-the-facade)
owns that mechanism.

What matters here is that none of it is fatal: `connect()` repeats every default in the table
above itself, so an empty block lands on `127.0.0.1:6379`, database 0, prefix `queue:`, group
`workers` and no password - confirmed against a live server. It works by two lists agreeing
rather than by design. An entry that is not an array at all - `'redis' => 'localhost:6379'` -
is discarded and reaches `connect()` as `[]`
(`testSettingArrayIgnoresAnEntryThatIsNotABlockOfSettings`). Set the block explicitly when you
use this driver.

## Requirements

`ext-redis` (phpredis), and **redis 6.2 or newer for `XAUTOCLAIM`**. It is in composer's
`suggest` rather than `require`, worded to say why:

> Required by `Utils\Models\Cache\CacheRedis`, `Utils\Models\Sessions\SessionsRedis` and the
> redis queue driver. The queue defaults to the database driver, which needs nothing extra.

Without the extension, `connect()` throws before it touches the network:

```text
StaticPHP\Utils\Models\Queue\QueueError: The redis queue driver needs ext-redis; install it
or use the database driver
```

That is the only check - `Queue::driver()` raises it on the first call, which for a web request
is the first `Queue::push()`. The driver's tests skip rather than fail when the extension or
the server is missing, because "a hand written fake would only ever prove the fake agrees with
itself"; `docker-compose.yml` runs a `queue-redis` service and sets `QUEUE_TEST_REDIS_HOST` so
they actually run. Everything on this page was exercised against redis 7.4.

**Not cluster aware.** A job's keys are spread across the queue keys, the job hash and the id
counter, which is more than one hash slot, and the scripts assume they can reach all of them.
One server, or a primary with replicas.

## Differences from the database driver

| | `QueueDatabase` | `QueueRedis` |
| --- | --- | --- |
| Push joins the caller's transaction | Yes - the insert runs in the caller's transaction on the caller's connection | No. A second write to a second system, atomic within redis only; the two can disagree in either direction |
| Durability | The database's | The server's persistence settings. The driver configures none of it and does not check |
| Claim guard | `WHERE id = ? AND (reserved_until IS NULL OR reserved_until <= ?)`, plus `FOR UPDATE SKIP LOCKED` where supported | The consumer group - `XREADGROUP` hands each entry to one reader - plus a `reserved_until` check on reclaim |
| Losing the claim race | Ordinary; retried up to `CLAIM_ROUNDS` times | Does not happen; no retry loop |
| Recovering a dead worker's job | The row is eligible again once `reserved_until` passes | `XAUTOCLAIM` on idle time, then `reserved_until` decides between claiming and parking |
| Order at equal priority | `available_at, id` | Stream insertion order; a promoted or released job joins the back |
| Delay | A column compared in the reserve query | A sorted set, promoted 50 per reserve by a worker watching that queue |
| Priority | `ORDER BY priority DESC` | One stream per level, walked highest first |
| Uniqueness | Unique index on `unique_key`, NULLs repeat freely | `u:{key}` checked and taken inside the push script |
| Failed jobs | Copied to a second table and deleted from the first; the failed row gets a **new id** | The same hash, plus `error` and `failed_at`, plus its id in `queue:failed`. The id never changes |
| `retry --id=` with an id that never failed | No-op | May requeue a live pending job, see above |
| `reserved` in `status` | `reserved_until > now`; an expired claim reads as pending | The group's pending list; an expired claim still reads as reserved |
| Schema | `staticphp queue install` writes a migration | None; keys are created on demand and per-queue keys are never removed once created |
| Concurrent workers | Yes; sqlite serialises writers | Yes; not cluster aware |
| Needs | `ext-pdo` and a connection | `ext-redis`, redis 6.2+ |

One operational note that falls out of the last two rows: nothing garbage-collects
`queue:queues`, `q:{queue}:levels` or a drained stream. A queue name used once is enumerated
by `stats()` for ever, and the empty stream and levels keys stay behind after the last job is
deleted. They are small, but they are not swept.
