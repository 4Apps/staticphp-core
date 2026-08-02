-- Session storage for StaticPHP\Utils\Models\Sessions\SessionsPgsql.
--
-- The table name is not configurable: the handler writes these four statements against
-- "sessions" directly, because a session read happens on every request and is not worth an
-- indirection.
--
-- No schema qualifier, so this lands wherever search_path points. Put it on its own
-- connection (config['db']['pdo']['sessions']) if sessions live in a different database
-- from the application's data - that is what SessionsPgsql expects by default.

CREATE TABLE sessions (
    id varchar(120) NOT NULL,

    -- sha256 of the user agent, truncated to 40 characters by Sessions::__construct().
    -- Widening the column alone does nothing; the truncation is in php.
    salt varchar(40) NOT NULL DEFAULT '',

    -- timestamptz rather than timestamp: gc() compares against CURRENT_TIMESTAMP, and on a
    -- server whose timezone is not utc a naive column makes that comparison wrong twice a
    -- year for the length of the dst shift.
    "timestamp" timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Serialized session payload. text rather than bytea because php hands the handler a
    -- string and postgres stores it out of line past ~2kb anyway.
    data text NOT NULL DEFAULT '',

    CONSTRAINT sessions_pk PRIMARY KEY (id)
);

-- gc() deletes by age, and without this it seq scans the whole table on roughly one request
-- in a hundred (session.gc_probability 1, gc_divisor 100).
CREATE INDEX idx_sessions_timestamp ON sessions ("timestamp");
