-- Audit trail schema for mysql and mariadb.
--
-- One row per recorded change. `module` is a column rather than a table name, so
-- "everything this user did today" stays one query and request_id can group a change that
-- crossed several modules. Deployments that want the trail split per module point
-- config['audit']['table'] at a callable instead; the columns do not change.

CREATE TABLE audit_log (
    id          bigint unsigned NOT NULL AUTO_INCREMENT,

    -- datetime, not timestamp: timestamp is converted through the session timezone on the
    -- way in and out, and the application writes UTC. The default only covers rows
    -- inserted by something else.
    created_at  datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    request_id  varchar(32) NOT NULL DEFAULT '',
    module      varchar(64) NOT NULL DEFAULT '',
    event       varchar(32) NOT NULL,

    -- The table name and its key as text, because one application keys on bigint and the
    -- next on uuid. No foreign key: an audit row has to outlive what it describes.
    entity_type varchar(128) NOT NULL,
    entity_id   varchar(64) NOT NULL DEFAULT '',

    actor_type  varchar(32) NOT NULL DEFAULT '',
    actor_id    varchar(64) NOT NULL DEFAULT '',

    -- Denormalised on purpose. Joining a users table later means deleting or renaming a
    -- user rewrites what the log says they did. 190 rather than 255 so the column stays
    -- indexable under utf8mb4 on the older row formats.
    actor_name  varchar(190) NOT NULL DEFAULT '',

    -- Split rather than one {from,to} blob, so a value can be queried directly:
    -- WHERE new_values ->> '$.status' = 'cancelled'
    old_values  json DEFAULT NULL,
    new_values  json DEFAULT NULL,

    -- text columns take no literal default here, so the application always writes them.
    url         text NOT NULL,
    ip_address  varchar(45) NOT NULL DEFAULT '',
    user_agent  text NOT NULL,
    tags        json DEFAULT NULL,
    context     json DEFAULT NULL,

    PRIMARY KEY (id),

    -- The history of one record, which is what the trail is opened for.
    KEY idx_audit_log_entity (entity_type, entity_id, created_at DESC),

    -- The viewer's default sort, and what the prune command scans.
    KEY idx_audit_log_created (created_at DESC),

    -- What one person did.
    KEY idx_audit_log_actor (actor_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deliberately not created here, because each one is write amplification on the busiest
-- table in the database. Add them when a query needs them:
--
--   CREATE INDEX idx_audit_log_request ON audit_log (request_id);
--   CREATE INDEX idx_audit_log_module ON audit_log (module, created_at DESC);

-- Nothing edits an audit row. Granting the application only what it needs is what makes
-- that true rather than merely intended:
--
--   REVOKE UPDATE, DELETE ON audit_log FROM '<application user>'@'%';
--
-- with the retention job running as a user that does keep DELETE.
