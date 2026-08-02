---
title: Queue CLI
description: Every staticphp queue subcommand, its flags, its real printed output and its exit codes.
sidebar:
    order: 7
---

`StaticPHP\Utils\Models\Queue\Cli` is the `staticphp queue` command. This package provides
the class; the `staticphp` executable that dispatches to it ships with the
[application skeleton](/staticphp-core/skeleton/cli/), and the map that connects the two is
`StaticPHP\Core\Cli` - see
[the command registry](/staticphp-core/database/migrations-cli/#the-command-registry). Like
`migrate` and `audit` it is reached before `src/Core/Helpers/Bootstrap.php` runs and never
touches the router: `work` is a process that runs for hours, and a route that could reach it
would be a route that never returns.

The usage text opens with a line worth reading before anything else on this page:

> Which backend these talk to is `config['queue']['driver']`, not an option here: a worker
> reading one queue while a push writes another is worse than either on its own.

There is no `--driver` flag. Both drivers share the same six verbs and the same `Commands`
class, and `Cli::dispatch()` picks between them by reading
`Queue::settingString('driver', 'database')` before it looks at a single argument - a queue
is only trustworthy if push and work agree where the jobs are, and a per-invocation override
would make it easy to point a worker at a queue nothing is writing to.

## Usage

```text
Usage: staticphp queue <command> [options]

Which backend these talk to is config['queue']['driver'], not an option here: a worker
reading one queue while a push writes another is worse than either on its own.

Commands:
  install             Write the queue schema into the migrations directory
  work                Run jobs until told to stop
  status              What is waiting, scheduled or held by a worker
  failed              List recent failures
  retry               Put failed jobs back on the queue
  forget              Delete failed jobs

Work options:
  --queue=A,B         Queues to watch, in precedence order (default: config)
  --once              Run at most one job, then exit
  --stop-when-empty   Exit when the queue drains rather than waiting
  --max-jobs=N        Exit after N jobs
  --max-time=N        Exit after N seconds. For cron: --max-time=55
  --memory=N          Exit once the process is using N megabytes
  --sleep=N           Seconds to wait when there is nothing to do (default: config)
  --timeout=N         Visibility timeout, in seconds (default: config)

Other options:
  --id=N              retry, forget: one job
  --all               retry, forget: every failed job
  --before=DATE       forget: failures older than YYYY-MM-DD
  --limit=N           failed: how many to list (default: 20)
  --dir=PATH          install: migrations directory
  --connection=NAME   Entry of config['db']['pdo'] to use (default: config)
  --project=NAME      Application under src/ to load config from (default: Application)
  --help              This text
```

That text is `Cli::USAGE`. It is printed by `--help` - and by `-h`, which the text does not
mention - anywhere in the arguments, exiting 0. An empty command line prints the same text
but exits **2**, not 0 - it is a missing argument, not a request for help. An unknown
command prints an `error:` line to stderr, then the usage text to stdout, and also exits 2.

Options are order-insensitive and may appear before or after the command. `--once`,
`--stop-when-empty` and `--all` are flags; everything else takes a value in `--name=value`
form. An unrecognised option, or one given without a value, is a hard error before anything
is bootstrapped:

```text
error: unknown option --force
error: --before needs a value, as --before=something
```

Both exit 2.

## install

```bash
staticphp queue install
staticphp queue install --dir=/srv/app/src/Application/Migrations
```

Writes the schema into the application's migrations directory as an ordinary migration
file - it never touches the database itself. Core ships no migration of its own, for the
same reason [`audit install`](/staticphp-core/audit/audit-cli/#install) does not:
[`Discovery`](/staticphp-core/database/migrations/) sorts by filename and `Tracker`
checksums every file it applied, so a framework-owned migration would sort by release date
and turn every `composer update` into checksum drift on a file the application cannot edit.

```text
Wrote /srv/app/src/Application/Migrations/2026-08-03-000000-create-queue-tables.sql
Review it, then: staticphp migrate apply
```

The filename comes from `Discovery::newFilename('create queue tables', $now)`, not from
anything spelled out in `Commands`, so it is guaranteed to satisfy the pattern
[`migrate`](/staticphp-core/database/writing-migrations/#let-the-tool-name-it) globs for.

The template is picked by the connection's PDO driver name - `install.pgsql.sql`,
`install.mysql.sql` or `install.sqlite.sql` under `Utils/Files/Queue` - and the configured
table names are substituted into it before it is written:

```php
<?php

// The failed table first: replacing "queue_jobs" first would leave the index names
// derived from "queue_failed_jobs" half rewritten.
if ($this->queue->failedTable() !== 'queue_failed_jobs') {
    $sql = str_replace('queue_failed_jobs', $this->queue->failedTable(), $sql);
}
```

That comment is the whole trick: `queue_jobs` is a substring of `queue_failed_jobs`, and
every index name derived from the failed table carries `queue_jobs` inside it. Substituting
the jobs table first would rewrite that substring out from under the failed table's own
replacement, leaving the derived index names half-rewritten -
`CommandsTest::testInstallRenamesBothTablesWhenTheyAreConfigured` pins the failed-first order
by asserting `idx_bg_dead_jobs_failed_at` appears intact in the output.

If the driver in use is `redis`, there is no schema to write and `install` says so and
exits 0 rather than looking for a template:

```text
Nothing to install: this queue is not on a database.
```

### install failures

An unsupported driver and a missing migrations directory both exit 2:

```text
error: no install template for oracle
error: no migrations directory at /srv/app/nope
```

An unreadable template, a failed write, and a target file that already exists all exit 1.
The last of these is the one to expect on a second run, since `install` never overwrites:

```text
error: /srv/app/src/Application/Migrations/2026-08-03-000000-create-queue-tables.sql already exists
```

Unlike `audit install`, there is no `--table` option here; a configured table name that is
not a plain identifier is caught earlier, in `Cli::dispatch()` when it constructs
`QueueDatabase` - see [bootstrapping](#bootstrapping-and-what-can-stop-it) below.

## work

```bash
staticphp queue work
staticphp queue work --queue=high,default
staticphp queue work --once
staticphp queue work --max-time=55
```

Hands the parsed options to a `Worker` and runs its loop. The loop itself - reserving,
running, signals, the six ways it stops, deployment as a daemon versus from cron - is
covered on [workers](/staticphp-core/queue/workers/); this page only owns the flags.

| Flag | Default | Meaning |
| --- | --- | --- |
| `--queue=A,B` | `config['queue']['queue']` (`default`) | Queues to watch, comma-separated, in precedence order. Blank entries are dropped; an empty result falls back to `['default']`. |
| `--timeout=N` | `config['queue']['timeout']` (`300`) | Visibility timeout, in seconds - **and the per-job time limit**. |
| `--sleep=N` | `config['queue']['sleep']` (`1`) | Seconds to wait when there is nothing to do. |
| `--max-jobs=N` | `0` (no limit) | Exit after N jobs. |
| `--max-time=N` | `0` (no limit) | Exit after N seconds. |
| `--memory=N` | `0` (no limit) | Exit once the process is using N megabytes. |
| `--stop-when-empty` | off | Exit as soon as the queue drains rather than waiting. |
| `--once` | off | Run at most one job, then exit. |

`--timeout` is one number doing two jobs: it is the visibility timeout the driver writes into
`reserved_until`, and it is also what the worker hands `pcntl_alarm()` before calling a
handler, so raising it here moves the deadline at which a job that will not finish is killed
by exactly as much. There is no separate flag for the second. Both effects, and why the alarm
is best-effort, are on [workers](/staticphp-core/queue/workers/).

`work`'s exit code is the `Worker`'s own, returned straight through `Commands::work()` - `0`
for every ordinary stop, be that a signal, a limit or an empty queue under
`--stop-when-empty`, and `1` when the queue was unreadable five reserves running. That is
what a supervisor sees, and what the restart guidance on
[workers](/staticphp-core/queue/workers/) is built on.

`--once` is not an independent flag once it is set. `Cli::work()` builds the call to
`Commands::work()` with `max-jobs` forced to `1` whenever `once` is true - overriding
whatever `--max-jobs` was also given - and `stop-when-empty` forced to true under the same
condition, regardless of whether `--stop-when-empty` was written out. So
`staticphp queue work --once --max-jobs=50` runs exactly one job, not fifty, and there is no
way to combine `--once` with waiting for a job that has not arrived yet.

## status

```bash
staticphp queue status
```

Prints a table of what each queue is holding, then the failed count:

```text
queue                      pending   delayed      held     total
default                          3         1         0         4
mail                             2         0         1         3

failed: 2
Read them with: staticphp queue failed
```

Only queues holding something appear. `QueueDatabase::stats()` is a `GROUP BY queue` over the
jobs table, so a queue with no rows produces no group and no line, and
[`QueueRedis::stats()`](/staticphp-core/queue/redis/#the-reporting-half) skips any queue whose
total came out zero. Every printed row has `total` of at least 1; a drained queue does not
appear as `0 0 0 0`, it simply stops appearing.

The `held` column is the array key `reserved` under a different name - `Commands::status()`
prints the header `held` but reads `$row['reserved']`, which is what `QueueReports` and every
other page call it. One column, two spellings, and this is the only place the printed one is
used.

An empty backlog prints `No jobs queued.` instead of an empty table, but the failed line
still follows it. The "Read them with" hint is only printed when `failed` is greater than
zero. A backend that cannot be read - a dropped connection, a missing table - exits 1 with
`error: cannot read the queue: <the underlying error>`. Otherwise `status` always exits 0.

## failed

```bash
staticphp queue failed
staticphp queue failed --limit=50
```

Lists the most recent failures, newest first up to `--limit` (default `20`), one line per
job plus one line of error:

```text
#2      2026-08-02 06:06:50  reports          BuildLedger (3 attempts)
        RuntimeException: it broke
#1      2026-08-02 06:06:50  reports          SendInvoice (3 attempts)
        RuntimeException: it broke

Requeue one with: staticphp queue retry --id=N
```

Only the **first line** of the stored error is printed - `strtok($error, "\n")` - and the
comment above it says why: "The first line of the error only. The trace is in the table for
whoever wants it, and printing it here would bury the list it is meant to summarise."
`CommandsTest::testFailedListsWhatBrokeAndHowToPutItBack` asserts the trace line
(`#0 somewhere`) does **not** appear in the output. Get the full trace by reading the failed
table's `error` column directly - see [storage](/staticphp-core/queue/storage/).

Nothing failed prints `Nothing has failed.` and exits 0 - that is success, not an empty
result to complain about. `--limit=0` or a negative limit is refused before anything is
queried:

```text
error: --limit must be at least 1
```

That exits 2. A read failure exits 1 with `error: cannot read the failed jobs: <the
underlying error>`.

## retry

```bash
staticphp queue retry --id=7
staticphp queue retry --all
```

Puts one failed job, or every failed job, back on the queue. The requeued job keeps its
queue, its handler name and its payload byte for byte, and loses three things: `attempts`
goes back to `0`, `priority` is reset to `0` whatever it was pushed with, and `unique_key`
is dropped, because the key belonged to the pending job and may well have been reused since
- [storage](/staticphp-core/queue/storage/#retryfailed-starts-the-job-over) has the full
reasoning. So a job pushed at priority 10 comes back behind everything ahead of it, and one
pushed with a unique key comes back without one, leaving that key free for a later push to
take. `max_attempts` is its own problem, below.

Neither `--id` nor `--all` given is an invocation error, exit 2:

```text
error: retry needs --id=N or --all
```

Success reports the count:

```text
Requeued 1 job.
```

`Requeued 2 jobs.` for more than one - the pluralisation is exact, not a trailing `(s)`.

:::caution[`--all` finding nothing and `--id` finding nothing exit differently]
Both print the identical line, `Nothing to requeue.`, but the exit code depends on which
argument was used - `return ($id === null ? 0 : 1);` right where that line is printed.
`--all` on an empty failed table is not a problem - nothing to do, exit 0. `--id` naming a
row that is not there - wrong id, or already retried - is treated as a failure to find what
was asked for, exit 1. `CommandsTest::testRetryOfAnIdThatIsNotThereSaysSo` pins the `--id`
case at 1. A script checking `retry`'s exit code across both forms will see 0 from one kind
of "nothing happened" and 1 from the other.
:::

`max_attempts` is read from the **current** `config['queue']['tries']` at the moment `retry`
runs - `Cli::execute()` passes `Queue::settingInt('tries', 3)` into `Commands::retry()`, not
anything read off the job row. A job originally pushed with `Queue::push(..., tries: 1)` and
retried after `tries` has since been raised to `5` in config gets five attempts on the retry,
not one. There is no `--tries` override on this command and no way to ask for the job's
original budget back.

A backend failure while requeueing exits 1 with `error: could not requeue: <the underlying
error>`.

## forget

```bash
staticphp queue forget --id=7
staticphp queue forget --all
staticphp queue forget --before=2026-01-01
```

Deletes failed jobs rather than putting them back. Exactly one of `--id`, `--all` or
`--before` is required; none given is exit 2:

```text
error: forget needs --id=N, --before=YYYY-MM-DD or --all
```

`--before` is matched against `^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$` - a date, or a date
and a time, nothing relative:

```text
error: --before must be YYYY-MM-DD or "YYYY-MM-DD HH:MM:SS", got "last tuesday"
```

That also exits 2. Success reports the count and exits 0 regardless of whether it was
nonzero:

```text
Deleted 1 row.
Deleted 0 rows.
```

Unlike `retry`, `forget` does not distinguish an empty result from a real one in its exit
code - `0` either way, whichever of the three argument forms was used. The asymmetry flagged
above under `retry` is specific to that command.

:::caution[`--all` really means all]
`Commands::forget()` calls `$this->queue->forgetFailed($id, $before)` with `$all` itself
never passed through - it exists only to satisfy the argument guard above. When `--all` is
the argument used, both `$id` and `$before` are `null`, and `forgetFailed(null, null)` builds
a `DELETE` with no `WHERE` clause at all and empties the failed table
([the statement is on storage](/staticphp-core/queue/storage/#retryfailed-starts-the-job-over);
the redis driver does the same thing to `queue:failed`).

`forgetFailed()` itself has no guard against a null/null call, on either driver - it is
`forget`'s own argument check, requiring at least one of `--id`, `--all` or `--before`, that
stands between an accidental invocation and an unconditional delete. Code calling the driver
directly, with an id that came out of a request parameter and could be `null`, has no such
check in front of it.
:::

A backend failure while deleting exits 1 with `error: could not delete: <the underlying
error>`.

## The global options

`--connection=NAME` and `--project=NAME` apply to every subcommand; `--help` (and `-h`)
short-circuits before either is read. `--connection` names an entry of
`config['db']['pdo']` and only matters on the `database` driver - it is silently irrelevant
on `redis`, which never asks for it. `--project` selects which application under `src/` to
boot, for a codebase running more than one application out of the same repository; see
[running several applications](/staticphp-core/guides/multiple-applications/). It defaults
to `Application` and is read before anything else, since it decides which `Public` directory
- and therefore which config - the rest of the command loads.

## Bootstrapping, and what can stop it

`Cli::run()` does the part of the framework's bootstrap a schema-and-worker tool needs and
none of the part it does not - no router, no session, no view engine - and it runs before
`src/Core/Helpers/Bootstrap.php` ever would. It checks that
`{$basePath}/src/{$project}/Public` exists, defines `PUBLIC_PATH` as that directory,
registers the autoloader, loads `Config`, then everything listed in
`$config['autoload_configs']`. It then checks three keys independently and, for each one the
application left empty, loads this package's own shipped config in its place: `db.pdo` from
`Utils/Config/Db.php` when no pdo entries were configured at all, `queue` from
`Utils/Config/Queue.php` when empty, `migrations` from `Utils/Config/Migrations.php` when
empty. An application with its own queue or migrations config keeps exactly that.

A project with no `Public` directory is caught first, before any config is loaded, exit 2:

```text
error: project "Nope" not found at /srv/app/src/Nope/Public
```

Everything after that depends on `config['queue']['driver']`. A value that is neither
`database` nor `redis` exits 2 - checked once, in `Cli::dispatch()`, before any connection is
attempted, so every command shares one error instead of six differently-worded ones:

```text
error: config['queue']['driver'] is "oracle"; it has to be "database" or "redis"
```

On `database`, an unknown `--connection` (or configured `connection`) is reported with the
ones that do exist, and exits 2:

```text
error: no database connection "reporting" in config['db']['pdo'] (configured: default)
```

A connection that resolves but fails to open exits 1:

```text
error: could not connect to "default": <the underlying error>
```

A resolvable connection whose configured table name is not a plain identifier - caught
constructing `QueueDatabase`, which validates both `table` and `failed_table` before doing
anything else - exits 2:

```text
error: "queue jobs" is not a plain table name
```

On `redis`, there is no database connection to open at all - deliberately, so "a worker host
that only runs jobs" is never handed database credentials it never uses. Anything that stops
`QueueRedis::connect()` - `ext-redis` missing, the server unreachable - exits **1**, not 2,
unlike the equivalent database-side failures:

```text
error: The redis queue driver needs ext-redis; install it or use the database driver
error: Could not reach redis at 127.0.0.1:6379: <the underlying error>
```

There is no catch-all around dispatch, so an exception that none of the above accounts for
is an uncaught PHP fatal and exits 255 rather than a tidy `error:` line - the same shape
[`audit`](/staticphp-core/audit/audit-cli/#install-failures) has and `migrate` does not.

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Success, including `--help`, `status`/`failed` finding nothing, `retry --all` finding nothing, and `install` on the redis driver |
| 1 | The command refused or failed: a target file that exists, an unreadable template, a failed write, a connection or redis link that could not be opened, a backend read/write failure, `retry --id` finding nothing, or not running under the cli SAPI |
| 2 | The invocation was wrong: an empty command line, unknown command or option, missing option value, an unsupported driver, a configured table name that is not a plain identifier, a bad `--before` date, `--limit` below 1, neither `--id` nor `--all` given to `retry`, none of `--id`/`--all`/`--before` given to `forget`, an unknown project or an unknown connection |
| 255 | An exception nothing above catches - uncaught PHP fatal |

The rule is which class printed the line, not what the line says. `Cli` writes to `STDERR`
in nine places, and all nine are `error:` lines it emits itself: an unknown option, an option
given without a value, an unknown `--project`, an unknown command, a `driver` that is neither
`database` nor `redis`, an unknown `--connection`, a connection that would not open, a
configured table name that is not a plain identifier, and a redis link that could not be
made. Everything `Commands` prints - success lines and its own `error:` lines alike - goes
through the injected `$out` callable, which the real entry point wires to plain `echo`, so
all of it lands on **stdout**. So does the usage text, in all three cases that print it.

Put plainly: `error:` on stderr means the invocation or the connection was wrong and nothing
ran; `error:` on stdout means a command ran and failed. A shell that only captures stderr for
logging will miss every `error:` line this page quotes from inside `Commands` - the read
failures, the write failures, `--limit must be at least 1`, and every `install` diagnostic.
