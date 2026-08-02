---
title: Handlers
description: What runs when a job is picked up, how its name resolves to a class, and what a handler can rely on about delivery.
sidebar:
    order: 3
---

A handler is the code that actually does the work a job was queued for. Everything on
[enqueueing](/staticphp-core/queue/enqueueing/) exists to get a name and a payload into a
row; a handler is what turns that row back into an invoice sent, a report generated, an
import applied. It implements one interface, `StaticPHP\Utils\Models\Queue\Handler`, and
that is the entire contract between an application and the worker that runs it.

The payload a handler receives is the array that was pushed, decoded from JSON - scalars and
arrays, nothing else. That is deliberate: a job row outlives the deploy that wrote it, and a
handler expecting a live object would break the moment the class it was serialised from
changed shape. Push identifiers, not objects, and load the records inside `handle()`.

## The interface

```php
<?php

namespace StaticPHP\Utils\Models\Queue;

interface Handler
{
    public function handle(array $payload, Job $job): void;
}
```

One method. `$payload` is what was pushed; `$job` is the reservation this attempt is running
under, described in full below. Returning normally from `handle()` means the job is done -
the worker deletes its row. Throwing, or calling `$job->release()`, are the other two ways
out, both covered further down.

## A worked handler

Something an application would plausibly queue: sending an invoice email, keyed by id
rather than by the invoice's own data, because the row backing that id might have changed by
the time the job runs.

```php title="Application/Jobs/SendInvoiceEmail.php"
<?php

namespace Application\Jobs;

use StaticPHP\Utils\Models\Db;
use StaticPHP\Utils\Models\Queue\Handler;
use StaticPHP\Utils\Models\Queue\Job;

class SendInvoiceEmail implements Handler
{
    public function handle(array $payload, Job $job): void
    {
        $rows = Db::select('invoices', ['id' => $payload['invoice_id']]);
        $invoice = $rows[0] ?? null;

        if ($invoice === null) {
            // Deleted or voided before the mail ever went out - nothing left to send,
            // and retrying will never find it either.
            return;
        }

        if ($invoice['emailed_at'] !== null) {
            // Already sent by an earlier attempt that crashed after mailing but before
            // the worker could mark it done. See "idempotence" below.
            return;
        }

        Mailer::send($invoice['billing_email'], 'invoice', $invoice);

        Db::update('invoices', ['emailed_at' => date('Y-m-d H:i:s')], ['id' => $invoice['id']]);
    }
}
```

Nothing here is queue-specific past the two arguments: load the record by the id the payload
carried, do the work, record that it happened. The `emailed_at` guard is what makes a second
delivery of the same job harmless rather than a duplicate email.

## How a name becomes a handler

`Queue::push()` takes a name, and a worker later has to turn that same name back into an
object with a `handle()` method. `Queue::handler(string $name): Handler` is what does that,
and it tries three things in order:

1. **A key of `config['queue']['handlers']` mapping to a callable.** The callable is called
   with no arguments and must return a `Handler`; if it returns anything else, the job fails
   with `config['queue']['handlers']['{$name}'] did not return a StaticPHP\Utils\Models\Queue\Handler`.
2. **A key of `config['queue']['handlers']` mapping to a class name.** That class is used in
   place of `$name` for the next two checks.
3. **`$name` itself, taken as a class name**, when neither of the above applies.

For the class-name path, two things can go wrong. If the class does not exist:
`No handler "{$name}": {$class} does not exist`. `QueueTest` pins only the `No handler
"nothing"` half of that, calling `Queue::handler()` directly; the `does not exist` half is
what `WorkerTest` asserts on instead, against a job the worker fails because its name
resolves to a class that isn't there. If the class exists but does not implement `Handler`:
`{$class} does not implement StaticPHP\Utils\Models\Queue\Handler`. No test pins that
message as `Queue::handler()` raises it - `QueueTest`'s own `does not implement` assertion is
against a different message, `Queue::push()`'s `Cannot queue "{$name}": it does not
implement …`, raised by the call-site check described above and never reaching
`Queue::handler()` at all.

:::note[No constructor arguments]
When resolution lands on a class name, the handler is built with `new $class()` - no
arguments, ever. Anything a handler needs beyond the payload - a mailer, a repository, a
config value that is not a queue setting - cannot come from a constructor unless that handler
is registered as a callable in `config['queue']['handlers']`, where the callable builds it
and closes over whatever it needs. This is the single most practical fact on this page: a
handler with dependencies is a callable entry, not a plain class name.
:::

```php title="Application/Config/Queue.php"
<?php

$config['queue']['handlers'] = [
    'send-invoice-email' => \Application\Jobs\SendInvoiceEmail::class,
    'send-statement' => fn (): \StaticPHP\Utils\Models\Queue\Handler
        => new \Application\Jobs\SendStatement($someRepository),
];
```

`Queue::push()` runs a version of this same check at the call site - a name that resolves to
nothing is refused immediately, with its own messages, rather than left to fail on every
worker attempt and land in the failed table hours later with no stack trace worth reading.
See [enqueueing](/staticphp-core/queue/enqueueing/) for that check and its own wording.

## Aliases

When `$name` matched an entry in `config['queue']['handlers']`, what gets written to the row
is the alias - `send-invoice-email`, not `Application\Jobs\SendInvoiceEmail` - because that is
what `Queue::push()` was given. `QueueTest` confirms this directly: pushing under an alias
leaves the alias in the `name` column, not the class it resolved to.

That is what makes the config entry worth having. A row queued today and run next week
resolves through whatever `config['queue']['handlers']['send-invoice-email']` points at next
week - so a handler class can be renamed, or rebuilt in a different namespace, without
rewriting anything already sitting in the table. Push under the class name directly and that
guarantee is gone: the row is the class name, and renaming the class strands every row
already queued under the old one.

## The `Job` object

Everything a handler is told about the attempt it is running, beyond the payload itself:

| Property | Type | Meaning |
| --- | --- | --- |
| `id` | `int` | The row's own id. |
| `queue` | `string` | The queue this attempt was reserved from. |
| `name` | `string` | What was pushed - a handler class, or a configured alias. Never the resolved class when the two differ. |
| `payload` | `array<string, mixed>` | The pushed data, decoded. Same array `handle()` receives as its first argument. |
| `payloadJson` | `string` | The row as written - the original bytes, before decoding. Kept so that failing a job records exactly what it was given, not a re-encoding of it. |
| `attempts` | `int` | Including this one. Reserving a job is what increments it, so the first attempt already reads `1`. |
| `maxAttempts` | `int` | The `tries` the job was pushed with. |
| `handle` | `string` | A driver-private bookmark, meaningless outside the driver that set it. |

`attempts` including the current attempt is worth being exact about: a job pushed with
`tries: 3` reads `attempts === 1` the first time `handle()` runs, not `0`. Reserving *is* the
attempt, whether or not `handle()` gets to finish it.

`payloadJson` and `payload` can, in principle, disagree - `payload` is decoded once at
reservation time, `payloadJson` is the bytes that produced it - but a handler has no reason
to reach for the JSON form; it exists so a failed job's record shows what was actually
stored, independent of anything `json_decode()` might have done to it.

`handle` is honest about being a hack that had to be public: the database driver leaves it
empty and finds its row by `id` alone, while the redis driver puts the stream entry id there,
because acknowledging a stream entry needs the id the stream assigned it rather than the
job's own. A handler has no reason to read this field; it is what the worker passes back to
the driver when the job finishes.

## Releasing instead of finishing

```php
<?php

public function handle(array $payload, Job $job): void
{
    if ($this->renderStillPending($payload['document_id'])) {
        $job->release(30);

        return;
    }

    // ... attach the finished render and continue
}
```

`$job->release($delay)` is the deliberate "not now" exit - for work that has not failed but
also is not ready: a document still being generated upstream, an external rate limit that
says come back later. The worker learns a handler took this path rather than finishing by
calling `$job->wasReleased()` after `handle()` returns. It is not a failure, but it does
touch `last_error`: the worker calls the driver's `release()` with an empty string, and
`QueueDatabase::release()` writes that unconditionally, with no check for what was there
before. `WorkerTest` only ever observes `last_error` read `''` after a release because in
that test the job never failed first - it says nothing about what happens when it did. A job
that threw on an earlier attempt, leaving a message in `last_error`, and then releases on a
later attempt has that message overwritten with the empty string; the record of why it
failed does not survive the release. No attempt is consumed *in the failure sense*: reserving
the job again still increments `attempts` the same way any reservation does, but a release
never compares that count to `maxAttempts` the way `jobFailed()` does after a throw.
`isLastAttempt()` is simply never asked. The worker's own output says `released, back in
{n}s` rather than logging a failure.

Because a release is never checked against `maxAttempts`, a handler that releases
unconditionally releases forever - `attempts` climbs with every reservation, but nothing
ever moves the job to the failed table. If that matters, carry a deadline in the payload
itself and give up past it, rather than trusting `maxAttempts` to stop it.

## Doing something different on the last try

```php
<?php

public function handle(array $payload, Job $job): void
{
    try {
        $this->publish($payload);
    } catch (PublishFailed $exception) {
        if ($job->isLastAttempt()) {
            Notify::onCallEngineer("publish failed for good: {$exception->getMessage()}");
        }

        throw $exception;
    }
}
```

`$job->isLastAttempt()` is `$job->attempts >= $job->maxAttempts` - true on the attempt that,
if it throws, has nowhere left to retry to. The pattern above still throws either way, so the
job still lands in the failed table on that last attempt; what changes is that somebody gets
told about it instead of the failure going quiet until someone happens to look at
`queue_failed_jobs`.

## What throwing does

A handler that throws - any `\Throwable`, not just a declared exception type - is caught by
the worker around the call to `handle()`. What happens next depends on `isLastAttempt()`:
released with a backoff delay if there are attempts left, moved to the failed table if not.
That full path, along with what a worker prints and logs at each step, belongs to
[workers](/staticphp-core/queue/workers/).

One thing worth knowing here rather than there: `Queue::handler()` runs *inside* the same
try block as `handle()` itself. A handler that cannot be built - an unresolvable name, a
class that stopped implementing `Handler`, a factory that returned the wrong thing - fails
the job through the exact same path as a handler that built fine and then threw. There is no
separate "couldn't even start" outcome to handle differently.

## Idempotence

The queue guarantees at-least-once delivery, not exactly-once. A worker holds a job by
writing `reserved_until` - a deadline, not a lock a process can be trusted to release,
because a worker that is killed cannot come back and release what it was holding. Once that
deadline passes, another worker is free to reserve the same row, and does so with `attempts`
incremented, exactly as if the first attempt had thrown. `QueueDatabaseTest` proves this: a
job reserved and then left untouched past its deadline is reserved again by a second worker,
with `attempts` now `2`.

That means a `handle()` that got most of the way through - sent the email, wrote half the
report - can run again from the top with no record that it already ran that far, if the
process died at the wrong moment rather than throwing cleanly. Nothing in the framework
papers over that; it is the shape of the guarantee, not a bug in it. A handler that does
anything externally visible and not naturally repeatable - sending mail, charging a card,
calling someone else's API - has to make that step idempotent itself, the way
`SendInvoiceEmail` above checks `emailed_at` before sending rather than trusting that this is
the only attempt that will ever run.
