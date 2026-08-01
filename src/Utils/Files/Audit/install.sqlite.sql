-- Audit trail schema for sqlite.
--
-- One row per recorded change. `module` is a column rather than a table name, so
-- "everything this user did today" stays one query and request_id can group a change that
-- crossed several modules. Deployments that want the trail split per module point
-- config['audit']['table'] at a callable instead; the columns do not change.

CREATE TABLE audit_log (
    id          integer PRIMARY KEY AUTOINCREMENT,

    -- Written by the application in UTC rather than left to this default, so every driver
    -- agrees on the value. The default only covers rows inserted by something else.
    created_at  text NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now')),

    request_id  text NOT NULL DEFAULT '',
    module      text NOT NULL DEFAULT '',
    event       text NOT NULL,

    -- The table name and its key as text, because one application keys on bigint and the
    -- next on uuid. No foreign key: an audit row has to outlive what it describes.
    entity_type text NOT NULL,
    entity_id   text NOT NULL DEFAULT '',

    actor_type  text NOT NULL DEFAULT '',
    actor_id    text NOT NULL DEFAULT '',

    -- Denormalised on purpose. Joining a users table later means deleting or renaming a
    -- user rewrites what the log says they did.
    actor_name  text NOT NULL DEFAULT '',

    -- Split rather than one {from,to} blob, so a value can be queried directly.
    old_values  text,
    new_values  text,

    url         text NOT NULL DEFAULT '',
    ip_address  text NOT NULL DEFAULT '',
    user_agent  text NOT NULL DEFAULT '',
    tags        text,
    context     text
);

-- The history of one record, which is what the trail is opened for.
CREATE INDEX idx_audit_log_entity ON audit_log (entity_type, entity_id, created_at DESC);

-- The viewer's default sort, and what the prune command scans.
CREATE INDEX idx_audit_log_created ON audit_log (created_at DESC);

-- What one person did.
CREATE INDEX idx_audit_log_actor ON audit_log (actor_id, created_at DESC);

-- Deliberately not created here, because each one is write amplification on the busiest
-- table in the database. Add them when a query needs them:
--
--   CREATE INDEX idx_audit_log_request ON audit_log (request_id);
--   CREATE INDEX idx_audit_log_module ON audit_log (module, created_at DESC);
