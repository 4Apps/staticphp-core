---
title: Workers
description: How the worker process reserves and runs one job at a time, what it does with a thrown handler, and how it stops.
sidebar:
    order: 5
---

`StaticPHP\Utils\Models\Queue\Worker` is the process that runs jobs. Nothing else in the
subsystem executes anything: [`Queue::push()`](/staticphp-core/queue/enqueueing/) writes a
row, the [driver](/staticphp-core/queue/storage/) claims one, and a
[handler](/staticphp-core/queue/handlers/) does the work - but a worker is what joins them,
in a process that is not serving a request and has no request timeout over it.

The class doc is blunt about where the interest lies:

> The loop is deliberately dull: reserve one job, run it, record what happened, repeat.
> Everything interesting is about stopping - on a signal, on a limit, or because the
> database went away - because a worker is a long-lived process and the ways it ends badly
> are what make a queue untrustworthy.

So most of this page is about stopping. The running part is four lines.

## Constructing one

```php
<?php

public function __construct(
    QueueInterface $queue,
    ?callable $out = null,
    ?callable $resolver = null,
    ?string $id = null
)
```

| Parameter | Default | What it is |
| --- | --- | --- |
| `$queue` | required | The backend. Anything implementing `QueueInterface`. |
| `$out` | `null` | `callable(string): void`, receives one line at a time **without** a trailing newline. `null` makes the worker silent. |
| `$resolver` | `null` | `callable(string): Handler`, builds a handler from a job name. `null` uses `Queue::handler(...)`. |
| `$id` | `null` | Identifies this worker in the table. `null` derives one, see [the worker id](#the-worker-id). |

`staticphp queue work` constructs it as `new Worker($this->queue, $this->out)` - driver and
output only - so in the ordinary case the resolver and the id are both the derived defaults.
The other two exist for tests and for an application that wants handlers built its own way.

## run() and runNext()

```php
<?php

public function run(
    array $queues,
    int $timeout = 300,
    int $sleep = 1,
    int $maxJobs = 0,
    int $maxTime = 0,
    int $memoryLimit = 0,
    bool $stopWhenEmpty = false
): int;

public function runNext(array $queues, int $timeout = 300): bool;

public function id(): string;
```

`$queues` is a list of queue names **in precedence order** - the driver tries each in turn
and takes the first job it finds, so `['high', 'default']` empties `high` before it looks at
`default`. An empty list becomes `['default']` in both methods. `$timeout` is clamped with
`max(1, $timeout)`, again in both.

`run()` returns a process exit code. `runNext()` reserves and runs at most one job and
returns whether there was anything to run; it installs no signal handlers, catches no
reserve failure, and applies no limits. It is the single-shot primitive the tests drive.
The cli's `--once` is not this method - it is `run()` with a job limit of one and
`--stop-when-empty` implied.

## The loop

Each iteration of `run()`, in order:

1. **Check the quit flag.** If a signal has set it, print `Stopping: asked to shut down` and
   leave the loop.
2. **Reserve one job**, by calling `$queue->reserve($queues, $timeout, $this->id)`. Success
   resets the consecutive-failure counter to zero. A throw goes down
   [the reserve-failure path](#reserve-failures-are-not-job-failures) instead.
3. **Nothing due?** With `$stopWhenEmpty` the loop prints `Nothing left to do` and ends.
   Otherwise it checks [the stop conditions](#stop-conditions), then rests for `$sleep`
   seconds and starts over.
4. **Run the one job**, then increment the counter of jobs done.
5. **Check the stop conditions again**, and break with `Stopping: <reason>` if one is met.

Then, on the way out, `Ran N job` / `Ran N jobs`.

One reserve and one job per iteration. There is no bulk claim, no prefetch and no
concurrency inside a worker - if you want two jobs running at once, run two workers. The
driver's guarded claim is what makes that safe; see
[storage](/staticphp-core/queue/storage/).

Running a job prints a header line and, on success, a duration:

```text
Worker web-01:2841 watching high, default
-> Application\Jobs\SendInvoice #1201 (attempt 1/3)
   done in 214ms
```

A handler that called `$job->release()` instead of finishing takes the release branch -
`release()` on the driver with the handler's own delay and an **empty** error string, then
`   released, back in 45s`. A release is not a failure, but it does write `last_error`: the
empty string is written unconditionally, so it clears whatever a previous failed attempt left
there. A job that fails once, then releases on a later attempt, loses the record of why it
failed. See [handlers](/staticphp-core/queue/handlers/).

`$sleep` of `0` means no pause at all between empty polls, which is a busy loop against the
database. The cli defaults it from `config['queue']['sleep']`, which ships as `1`.

## When a handler throws

Anything thrown out of `$handler->handle()` - including the failure to build the handler in
the first place, and including the timeout alarm - is caught, and the worker builds a detail
string of the exception class, its message, the file and line, and the full trace. What
happens next depends on one question only: was that the last attempt?

**Not the last attempt.** The job is released back onto the queue with
`Queue::backoff($job->attempts)` seconds of delay and the detail string as `last_error`:

```php
<?php

$delay = Queue::backoff($job->attempts);
$this->queue->release($job, $delay, $detail);
$this->line("   failed, retrying in {$delay}s: {$exception->getMessage()}");
```

`Queue::backoff()` reads `config['queue']['backoff']`, and the attempt number is the count
*already made*, so with the shipped `[10, 60, 300]` a first failure comes back in ten
seconds, a second in a minute, a third and everything after in five. `WorkerTest` pins that
against a one-element `[90]`: the row stays on the queue, `attempts` is `1`, `last_error`
contains the message, `available_at` is at least ninety seconds out, and the output contains
`retrying in 90s`.

**The last attempt.** The job moves to the failed table, the output says so, and the message
*also* goes to PHP's `error_log`:

```php
<?php

$this->queue->fail($job, $detail);
$this->line("   failed for good: {$exception->getMessage()}");

// Also to the error log, because the trail a supervisor keeps is the process
// output, and nobody reads that until they already know something is wrong.
error_log("Queue job {$job->name} #{$job->id} failed permanently: {$exception->getMessage()}");
```

That second write is the point of the branch. Process output goes wherever the supervisor
was pointed, which is a file nobody opens on a good day; the error log is where the rest of
the application's problems already surface. `WorkerTest` asserts both halves - it redirects
`error_log` to a scratch file for the duration and checks that the printed output contains
`failed for good` while the log contains `failed permanently`.

The driver's `fail()` moves the row to `queue_failed_jobs` in one transaction, so a
permanently failed job leaves nothing behind on the main table. `run()` does not stop
because a job failed. A failing job is a normal outcome of running a queue; the worker
records it and reserves the next one.

## Reserve failures are not job failures

A `reserve()` that throws says nothing about any job - it says the queue itself could not be
read. The worker counts those separately:

```php
<?php

// A database that has gone away comes back, usually. One that has not come
// back after five tries is not going to while this process waits for it,
// and exiting non-zero is how a supervisor is told to start a fresh one.
$this->line("error: could not reserve a job: {$exception->getMessage()}");

if ($failures >= 5) {
    $this->line('Stopping: the queue has been unreadable five times running');

    return 1;
}
```

Every failure prints, increments the counter and rests for `$sleep` seconds. **Any
successful reserve resets the counter to zero**, so five *consecutive* failures are needed,
not five in total. The fifth returns exit code `1` immediately - no `Ran N jobs` line, no
graceful drain.

`WorkerTest` proves both directions. A driver whose `reserve()` always throws produces exit
code `1` after exactly five calls, with `unreadable five times running` in the output, and
the test comments that "a supervisor should see this as a crash". A driver that throws three
times and then delegates to the real one runs its job and exits `0` - the counter had gone
back to zero.

This is the behaviour a supervisor config depends on, so be exact about what it is not: a
worker does not exit on the first database hiccup, and it does not stay up forever against a
database that is gone. It gives the connection five tries spaced `$sleep` apart, then hands
the problem to whatever restarts it.

One consequence worth knowing: the limit checks live on the empty-queue and job-done paths,
not on the failure path. While `reserve()` is failing, `--max-time` and the rest are not
consulted, so a cron-bounded worker that hits a dead database ends via the five-failure exit
rather than via its time limit.

## Signals

`run()` installs handlers before the first iteration. `SIGTERM`, `SIGINT` and `SIGQUIT` all
do the same thing - set a flag:

```php
<?php

foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
    pcntl_signal($signal, function (): void {
        $this->shouldQuit = true;
    });
}
```

The flag is read at the top of the loop and inside the sleep, which wakes in one-second
slices and returns early. Nothing interrupts a job in progress. From the class:

> Without this a deploy that restarts workers kills whatever was running, and the job only
> comes back once its visibility timeout expires - so a restart looks like minutes of
> nothing happening. With it, SIGTERM sets a flag and the loop stops at the next clean
> point.

`SIGALRM` is the one signal that does throw, and only while a handler is actually running -
the handler checks an `inJob` flag first, so an alarm arriving between jobs is ignored. What
it throws is `QueueError('The job ran past the timeout')`, which lands in the same catch as
any other exception: a timed-out job is released or failed by the ordinary rules and has
consumed an attempt.

When `ext-pcntl` is not loaded, `run()` prints

```text
note: ext-pcntl is not loaded, so this worker cannot shut down gracefully
```

and installs nothing. There is then no graceful shutdown and no per-job time limit: `TERM`
kills the process mid-job, and the job comes back only when its visibility timeout expires.
The worker still runs jobs correctly - it just has no say in how it ends.

## The timeout is best effort

`$timeout` is one number doing two jobs, and only one of them is reliable.

The **visibility timeout** is passed to `reserve()`, which stamps `reserved_until` that far
ahead. It is what gets a job back if this process dies: once the stamp is in the past, any
worker may claim the row again. That mechanism does not depend on this process being alive
or on any extension being loaded.

The **job time limit** is `pcntl_alarm()`, set before the handler and cleared after it. The
source is honest about the limits of that:

> Best effort, and honestly so: the signal is only delivered when control is back in PHP, so
> a job blocked in a long database query runs until the query returns. What it does catch is
> the runaway loop and the retry that never gives up, which is most of what makes a worker
> stop working.

So a job stuck in a five-minute query with a sixty-second timeout is not killed at sixty
seconds; PHP is inside the driver, the signal waits, and the exception is raised when the
query returns. The alarm is also silently absent when `pcntl_alarm()` is not available.

Set `$timeout` above the slowest job you expect. Too low and a slow-but-healthy job is
killed, burns an attempt and eventually dead-letters; too low relative to real run time also
means the visibility stamp expires while the job is still running, and a second worker
picks the same job up.

## Stop conditions

Checked after each job completes and each time the queue turns out to be empty:

| Condition | Cli flag | Disabled by | Reason printed |
| --- | --- | --- | --- |
| `$maxJobs` jobs done | `--max-jobs=N` | `0` | `ran 12 jobs` |
| `$maxTime` seconds since start | `--max-time=N` | `0` | `been going for 55s` |
| Process past `$memoryLimit` MB | `--memory=N` | `0` | `using 192MB` |
| Queue empty | `--stop-when-empty` | default `false` | `Nothing left to do` |

The first three are disabled by `0`, which is the default for all of them. Memory is
`memory_get_usage(true)` - real allocated memory, not the smaller `emalloc` figure - divided
by 1048576 and rounded to whole megabytes, so the number in the message is what was compared.
Time is wall clock from the moment `run()` was entered, so it includes idle sleeping.

Each of the first three prints `Stopping: <reason>` and then `Ran N jobs`;
`--stop-when-empty` prints `Nothing left to do` first. All four exit `0`.

Note the ordering on an empty queue: `--stop-when-empty` is checked *before* the limits, so
a run with both stops on the drain and reports that rather than the limit.

`--timeout` is not one of these, and it does not follow their convention. `run()` clamps it
with `max(1, $timeout)`, so `--timeout=0` is one second rather than "no limit" - every
reservation expires almost as soon as it is taken and every job is alarmed a second in.
Leave it at the configured default unless you have a reason to move it, and never set it to
zero expecting the behaviour the other three flags give.

## Exit codes

| Code | When |
| --- | --- |
| `0` | Any ordinary end: a limit reached, the queue drained under `--stop-when-empty`, or a signal. |
| `1` | Five consecutive `reserve()` failures. |

A failed job never affects the exit code. That split is what makes an `autorestart=true`
supervisor sane: `0` means the worker did what it was told and can be restarted at leisure,
`1` means the process could not read the queue and a fresh one should be started now.
`staticphp queue work` returns this same code as its own process exit status - nothing sits
between `Worker::run()` and the shell - which is what the supervisor and cron guidance below
depend on.

## Deployment

The class doc describes two ways to run it, and supports both because "which one is
available is a hosting question rather than a design one":

> - Supervised: `staticphp queue work` under systemd or supervisord, restarted when it
>   exits. Jobs start within `sleep` seconds of being queued.
> - Cron: `staticphp queue work --max-time=55` every minute. No daemon to supervise, nothing
>   to restart on deploy, at the cost of up to a minute of latency.
>
> Neither is a fallback for the other. Pick by what the host will let you run.

### Supervised

```ini title="/etc/supervisor/conf.d/queue.conf"
[program:queue]
command=php /srv/app/staticphp queue work --queue=high,default --max-time=3600
directory=/srv/app
user=appuser
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=330
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
```

`stopsignal=TERM` is what the graceful-shutdown flag is for, and `stopwaitsecs` needs to be
comfortably above the job timeout - here 330 against the default 300 - or supervisor will
`SIGKILL` the process part-way through the job it was politely finishing. `--max-time=3600`
is not a limit so much as a recycle: the process exits `0` every hour, supervisor starts a
fresh one, and a leaking handler cannot accumulate for a week. `numprocs=2` runs two workers
against the same tables, which the driver's guarded claim makes safe.

The skeleton's `develop` container already runs supervisord, with its programs defined in
`docker/app/conf/supervisord.services.conf` - see
[the dev environment](/staticphp-core/skeleton/dev-environment/) for how that file is
rendered and what it already contains. A queue program for development goes in beside them.

### Cron

```text title="crontab"
* * * * * cd /srv/app && php staticphp queue work --max-time=55 >> /var/log/queue.log 2>&1
```

The bound wants to sit just under the interval. At `--max-time=55` on a once-a-minute
schedule, each run finishes with a few seconds of headroom before the next one starts, and
you keep one worker at a time. Give it `--max-time=90` and runs overlap; give it `20` and
the queue sits unattended for forty seconds of every minute. The limit is also only checked
between jobs, so a run can overshoot by the length of whatever job was in hand - leave more
headroom if your jobs are slow, or use `--stop-when-empty` and accept the overlap risk.

Cron gives up to a minute of latency for a job queued just after a run started. If that
matters, you wanted the supervised form.

Every flag named here belongs to `staticphp queue work` and is documented with the rest of
the command in [the queue cli](/staticphp-core/queue/queue-cli/).

## The worker id

Unless one is passed in, the id is the hostname and the process id, cut to 64 characters:

```php
<?php

$host = gethostname();
$this->id = substr(($id ?? (is_string($host) ? $host : 'worker') . ':' . getmypid()), 0, 64);
```

A hostname PHP cannot determine falls back to the literal `worker`. The 64 matches the
`reserved_by` column, which is `varchar(64)` in the mysql and postgres schemas; the driver
truncates to the same width again when it claims, so an id supplied by hand cannot overflow
the column either.

Nothing in the framework ever reads `reserved_by` back. No claim, release, retry or report
consults it - the guarded `UPDATE` on `reserved_until` is what makes claiming correct, and it
would work identically with the column blank. It exists for the person who runs `SELECT * FROM
queue_jobs WHERE reserved_until > now()` at nine on a Monday and needs to know which of six
boxes is holding the job that has not finished. That is worth a column. See
[storage](/staticphp-core/queue/storage/) for the rest of the table and
[redis](/staticphp-core/queue/redis/) for the same field on that driver.

`id()` returns it, which is mostly useful for a test asserting on output - the first line a
worker prints is `Worker <id> watching <queues>`.
