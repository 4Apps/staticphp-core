-- Job queue schema for postgres.
--
-- Two tables rather than one with a status column. A finished job is deleted, so
-- `queue_jobs` stays roughly the size of the backlog rather than the size of history,
-- which is what keeps the reserve query cheap without any index tuning. Jobs that ran out
-- of attempts move to `queue_failed_jobs`, where they can be read, retried or forgotten
-- without ever being in the way of a worker.
--
-- Both names are prefixed because `jobs` is a word an application wants for its own work.

CREATE TABLE queue_jobs (
    id             bigserial PRIMARY KEY,

    -- A name rather than a separate table per queue, so one worker can watch several and
    -- `--queue=high,default` is an argument instead of a deployment.
    queue          varchar(64) NOT NULL DEFAULT 'default',

    -- The handler: a class implementing StaticPHP\Utils\Models\Queue\Handler, or a key of
    -- config['queue']['handlers'] when the class has been renamed since the row was
    -- written. 190 characters because that is what MySQL can index under utf8mb4, and a
    -- queue that only works on postgres is not worth the extra room.
    name           varchar(190) NOT NULL,

    -- JSON, not serialize(). It survives a deploy that changes the class, it can be read
    -- in a SELECT while somebody is asking why a job did not run, and it cannot be turned
    -- into an object-injection gadget by anything that manages to write to this table.
    payload        text NOT NULL,

    attempts       integer NOT NULL DEFAULT 0,
    max_attempts   integer NOT NULL DEFAULT 3,

    -- Higher runs first. Same-priority jobs stay FIFO through the available_at, id sort.
    priority       integer NOT NULL DEFAULT 0,

    -- Set to make a job idempotent at the point of queueing: pushing the same key twice
    -- while the first is still pending returns the existing job instead of a second one.
    -- NULL repeats freely, which every one of the three treats as "not a duplicate".
    unique_key     varchar(190),

    -- When the job becomes eligible. Now for an ordinary push, later for a delayed one,
    -- and pushed forward by the backoff after a failed attempt.
    available_at   timestamptz NOT NULL,

    -- The visibility timeout. NULL means nobody holds it; a value in the future means a
    -- worker does; a value in the past means a worker did and died, so the row is eligible
    -- again. That last case is the whole reason this is a deadline rather than a flag - a
    -- worker killed mid-job cannot come back to clean up after itself.
    reserved_until timestamptz,

    -- Which worker, for reading the table while something is stuck. Never read by code.
    reserved_by    varchar(64),

    -- Kept on the row rather than only in queue_failed_jobs, so a job that is retrying can be
    -- asked why without waiting for it to exhaust its attempts.
    last_error     text NOT NULL DEFAULT '',

    created_at     timestamptz NOT NULL
);

-- The reserve query, in its column order: pick the queue, drop what is not due, sort.
-- The reserved_until check is left out on purpose - it discards very few rows, and
-- including a nullable column here would only stop the index from serving the sort.
CREATE INDEX idx_queue_jobs_reserve ON queue_jobs (queue, priority DESC, available_at, id);

-- Multiple NULLs are permitted in a unique index, which is what makes one index cover both
-- "at most one pending job with this key" and "most jobs have no key".
CREATE UNIQUE INDEX idx_queue_jobs_unique_key ON queue_jobs (unique_key);

CREATE TABLE queue_failed_jobs (
    id        bigserial PRIMARY KEY,
    queue     varchar(64) NOT NULL DEFAULT '',
    name      varchar(190) NOT NULL,
    payload   text NOT NULL,
    attempts  integer NOT NULL DEFAULT 0,

    -- Message and trace. The trace is what makes this table worth keeping; without it a
    -- failed job is a note saying something went wrong somewhere.
    error     text NOT NULL DEFAULT '',

    failed_at timestamptz NOT NULL
);

CREATE INDEX idx_queue_failed_jobs_failed_at ON queue_failed_jobs (failed_at DESC);

-- Retention is the application's decision. `staticphp queue forget --before=YYYY-MM-DD`
-- deletes in batches.
