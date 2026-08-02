---
title: Storage
description: The Store, the audit_log table, its indexes, and how the three shipped schemas differ.
sidebar:
    order: 4
---

`StaticPHP\Utils\Models\Audit\Store` is the only thing that writes an audit row. It is a
plain object rather than a static, which is what lets a test or an application substitute one
through [`Audit::setStore()`](/staticphp-core/audit/recording/#swapping-the-store).

```php
<?php

namespace StaticPHP\Utils\Models\Audit;

class Store
{
    public function __construct(string $connection, string|callable $table);

    public function write(AuditEvent $event): void;
    public function tableFor(AuditEvent $event): string;
    public function connection(): string;

    public static function assertTableName(string $table): string;
}
```

`$connection` is an entry of `$config['db']['pdo']`. `$table` is either one table name or a
`callable(AuditEvent): string` resolving one per event, which is the whole of what makes a
split trail possible without a second column shape.

## write()

One `Db::insert()`, on the store's connection, inside whatever transaction the caller already
opened. The mapping from event to row is fixed:

| Column | From | Cut to |
| --- | --- | --- |
| `created_at` | `$event->createdAt`, or [stamped here](#the-timestamp) | |
| `request_id` | `$event->requestId` | 32 |
| `module` | `$event->module` | 64 |
| `event` | `$event->event` | 32 |
| `entity_type` | `$event->entityType` | 128 |
| `entity_id` | `$event->entityId` | 64 |
| `actor_type` | `$event->actorType` | 32 |
| `actor_id` | `$event->actorId` | 64 |
| `actor_name` | `$event->actorName` | 190 |
| `old_values` | `$event->oldValues` as json, or `null` | |
| `new_values` | `$event->newValues` as json, or `null` | |
| `url` | `$event->url` | |
| `ip_address` | `$event->ipAddress` | 45 |
| `user_agent` | `$event->userAgent` | |
| `tags` | `$event->tags` as json, `null` when the list is empty | |
| `context` | `$event->context` as json, or `null` | |

Json is encoded with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
JSON_INVALID_UTF8_SUBSTITUTE`, so a name keeps its diacritics, a url keeps its slashes, and a
value carrying broken utf-8 substitutes rather than failing the encode. An encode that fails
anyway stores `null` rather than the string `false`.

### Values are cut, not rejected

Every `varchar` column has a declared width, and `write()` cuts values to fit with
`mb_substr()`. An audit write that throws because somebody's name is long has turned the trail
into an outage, and the alternative - making every column `TEXT` - gives up the index sizes the
shape was chosen for. A 250-character actor name and a 100-character module, on postgres:

```text
actor_name length: 190
module length:     64
```

`url` and `user_agent` have no declared width, because they are `text` in every shipped
schema. A 501-character url is stored whole:

```text
url length: 501
```

Cutting is by characters, not bytes, so a multibyte name is truncated at a character boundary
and never becomes invalid utf-8.

### The timestamp

`createdAt` left at `null` is stamped by the store, in UTC, in the spelling this driver reads.
It is stamped here rather than left to the column default so that every driver agrees on the
value and a test can pin it - the defaults in the schema only cover rows inserted by something
else.

Postgres is given an explicit offset (`Y-m-d H:i:sP`) because `timestamptz` would otherwise
read a bare string in the session's timezone. The other two get `Y-m-d H:i:s` and store what
they are given.

### Which table

`tableFor()` resolves the name and validates it. Table names are concatenated into the query,
so nothing but a plain identifier - optionally schema-qualified - is accepted. A resolver is
application code, but it is application code being handed an event whose `module` may have
come from a request:

```text
"audit_log"                        -> audit_log
"logs.history"                     -> logs.history
"audit_log; DROP TABLE people"     -> Refusing to write the audit trail to "audit_log; DROP TABLE people": not a plain table name
"audit-log"                        -> Refusing to write the audit trail to "audit-log": not a plain table name
"9lives"                           -> Refusing to write the audit trail to "9lives": not a plain table name
""                                 -> Refusing to write the audit trail to "": not a plain table name
```

The check is `/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/`, which rules out hyphens,
leading digits, quoting and the empty string as well as the obvious injection. It is a public
static, so an application that builds table names itself can run the same check.

A resolver keyed on the module, with `history_catalog` created alongside `audit_log`:

```php
<?php

$config['audit']['table'] = fn (AuditEvent $event): string => 'history_' . $event->module;
```

```text
audit_log rows:       0
history_catalog rows: 1
```

A `table` setting that is neither a string nor a callable is refused when the store is built,
not when a row is written:

```text
StaticPHP\Utils\Models\Audit\AuditError: config['audit']['table'] must be a table name or a callable
```

## The audit_log table

Three schemas ship, under `src/Utils/Files/Audit/`, one per driver:
`install.pgsql.sql`, `install.mysql.sql` and `install.sqlite.sql`. Each creates **one table
and three indexes**, and [`staticphp audit install`](/staticphp-core/audit/audit-cli/#install)
copies the right one into the application's migrations directory.

| Column | postgres | mysql and mariadb | sqlite |
| --- | --- | --- | --- |
| `id` | `bigserial PRIMARY KEY` | `bigint unsigned NOT NULL AUTO_INCREMENT`, with `PRIMARY KEY (id)` in the table body | `integer PRIMARY KEY AUTOINCREMENT` |
| `created_at` | `timestamptz NOT NULL DEFAULT now()` | `datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)` | `text NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now'))` |
| `request_id` | `varchar(32) NOT NULL DEFAULT ''` | same | `text NOT NULL DEFAULT ''` |
| `module` | `varchar(64) NOT NULL DEFAULT ''` | same | `text NOT NULL DEFAULT ''` |
| `event` | `varchar(32) NOT NULL` | same | `text NOT NULL` |
| `entity_type` | `varchar(128) NOT NULL` | same | `text NOT NULL` |
| `entity_id` | `varchar(64) NOT NULL DEFAULT ''` | same | `text NOT NULL DEFAULT ''` |
| `actor_type` | `varchar(32) NOT NULL DEFAULT ''` | same | `text NOT NULL DEFAULT ''` |
| `actor_id` | `varchar(64) NOT NULL DEFAULT ''` | same | `text NOT NULL DEFAULT ''` |
| `actor_name` | `varchar(190) NOT NULL DEFAULT ''` | same | `text NOT NULL DEFAULT ''` |
| `old_values` | `jsonb` | `json DEFAULT NULL` | `text` |
| `new_values` | `jsonb` | `json DEFAULT NULL` | `text` |
| `url` | `text NOT NULL DEFAULT ''` | `text NOT NULL` | `text NOT NULL DEFAULT ''` |
| `ip_address` | `varchar(45) NOT NULL DEFAULT ''` | same | `text NOT NULL DEFAULT ''` |
| `user_agent` | `text NOT NULL DEFAULT ''` | `text NOT NULL` | `text NOT NULL DEFAULT ''` |
| `tags` | `jsonb` | `json DEFAULT NULL` | `text` |
| `context` | `jsonb` | `json DEFAULT NULL` | `text` |

The differences worth knowing:

- **`datetime(3)`, not `timestamp`, on mysql.** `timestamp` is converted through the session
  timezone on the way in and out, and the application writes UTC.
- **`actor_name` is 190, not 255.** That keeps the column indexable under `utf8mb4` on the
  older mysql row formats, and the two other schemas match it so the shape stays one shape.
- **mysql's `text` columns take no literal default.** The application always writes `url` and
  `user_agent`, so nothing depends on one.
- **`ip_address` is 45**, the conventional maximum for an IPv6 address with an IPv4 tail.
- **sqlite has no json type**, so the four json columns are `text`. They hold exactly the
  bytes `json_encode()` produced.
- **The mysql table is `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.**

The same recorded change, read straight back out of each driver:

```text
===== sqlite =====
created_at   2026-08-02 01:03:21
old_values   {"city":"Dobele"}
new_values   {"city":"Riga"}
tags         ["gdpr"]
context      {"batch":7}

===== pgsql =====
created_at   2026-08-02 01:03:21+00
old_values   {"city": "Dobele"}
new_values   {"city": "Riga"}
tags         ["gdpr"]
context      {"batch": 7}

===== mysql =====
created_at   2026-08-02 01:03:22.000
old_values   {"city":"Dobele"}
new_values   {"city":"Riga"}
tags         ["gdpr"]
context      {"batch":7}
```

Two things to read off that. `created_at` comes back in the driver's own spelling - postgres
appends the offset it was given, mysql keeps the millisecond precision the column declares,
sqlite returns the string it was handed. And postgres `jsonb` **normalises**: the document is
reparsed and re-emitted, so the spacing differs from what was written. Key order is not
preserved either, which a single-key document cannot show; a longer one is on
[the audit table page](/staticphp-core/audit/audit-table/#a-complete-worked-example). The
other two return the bytes that were stored.

## The indexes

Three, on every driver. Postgres and sqlite create them as separate statements; mysql
declares them inside the `CREATE TABLE` as `KEY` clauses, which is the same three indexes:

| Index | Columns | What it is for |
| --- | --- | --- |
| `idx_audit_log_entity` | `entity_type, entity_id, created_at DESC` | the history of one record, which is what the trail is opened for |
| `idx_audit_log_created` | `created_at DESC` | the viewer's default sort, and what `prune` scans |
| `idx_audit_log_actor` | `actor_id, created_at DESC` | what one person did |

Read back from each driver after applying the shipped schema, with the primary key alongside:

```text
===== sqlite =====
  idx_audit_log_actor
  idx_audit_log_created
  idx_audit_log_entity
===== pgsql =====
  audit_log_pkey
  idx_audit_log_actor
  idx_audit_log_created
  idx_audit_log_entity
===== mysql =====
  PRIMARY
  idx_audit_log_entity
  idx_audit_log_created
  idx_audit_log_actor
```

Index names are derived from the table name, so
[renaming the table renames them too](/staticphp-core/audit/audit-cli/#renaming-the-table) and
two trails can coexist in one schema.

### The ones deliberately not created

Each `.sql` file carries these commented out, because every index is write amplification on
what will be the busiest table in the database. Add them when a query needs them, not before:

```sql
CREATE INDEX idx_audit_log_request ON audit_log (request_id);
CREATE INDEX idx_audit_log_module ON audit_log (module, created_at DESC);
```

Postgres carries a third suggestion, for searching inside a change rather than for it:

```sql
CREATE INDEX idx_audit_log_new_values ON audit_log USING gin (new_values);
```

Its own comment puts the trade plainly: the gin index makes searching inside a change
possible and roughly doubles insert cost. **None of the three is created by
`staticphp audit install`**, and none was benchmarked here.

## Operational notes

:::note[Not exercised here]
Both recommendations below are quoted from the shipped `.sql` comments and were **not run**
while writing this page. Everything else on it was exercised against PostgreSQL 18,
MariaDB 11.8 and sqlite 3.
:::

The `.sql` files carry two further recommendations, neither of which the installer applies.

**Nothing edits an audit row.** Granting the application only what it needs is what makes that
true rather than merely intended:

```sql
-- postgres
REVOKE UPDATE, DELETE ON audit_log FROM <application role>;

-- mysql
REVOKE UPDATE, DELETE ON audit_log FROM '<application user>'@'%';
```

with the retention job running as a role that does keep `DELETE`.
[`staticphp audit prune`](/staticphp-core/audit/audit-cli/#prune) would then fail under the
application's own credentials, which is the point rather than a side effect.

**Retention is the application's decision.** `prune` deletes in batches. On a large postgres
installation, declare the table partitioned by month instead and drop whole partitions, which
is instant where a delete is not:

```sql
CREATE TABLE audit_log (...) PARTITION BY RANGE (created_at);
```

Partitioning requires the partition key in the primary key, so `PRIMARY KEY (id)` becomes
`PRIMARY KEY (id, created_at)`. The shipped file leaves that as a comment rather than doing
it, because a partitioned table is a decision about operations, not about the schema. `prune`
notices a partitioned parent and says so.
