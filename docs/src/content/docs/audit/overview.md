---
title: Overview
description: What the audit trail records, how the pieces fit together, and the schema decisions behind them.
sidebar:
    order: 1
---

`StaticPHP\Utils\Models\Audit\Audit` is the facade for the audit trail. Everything on it is
static, and it delegates to three small classes in the same namespace - `Diff`, `AuditEvent`
and `Store` - and throws a fourth, `AuditError`.

The design decision that shapes the rest: **nothing happens on its own.** There is no hook
in [`Db`](/staticphp-core/database/db/), no observer and no listener. Every row in the trail
is the result of a call the application wrote. What the module supplies is that
`insert()`, `update()` and `delete()` read the affected rows themselves, so the
before-and-after select is written once here rather than at every call site - which is where
it goes wrong, because a hand-written select only covers the columns somebody remembered.

## The moving parts

```text
Audit::insert/update/delete   read the affected rows, then write them
        |
    Diff::between()           what actually changed, old and new
        |
    AuditEvent                one row, readonly, still in memory
        |
    Store::write()  ->  Db::insert()  ->  audit_log
```

1. **The wrappers read first.** `Audit::update()` and `Audit::delete()` select the rows the
   condition matches before touching them, through
   [`Db::select()`](/staticphp-core/database/db/#select) - the same condition builder the
   write itself uses, so the rows audited are the rows changed. See
   [recording changes](/staticphp-core/audit/recording/).
2. **`Diff` works out what moved.** Only the columns present in the new data are considered,
   and only where the value actually differs. It is pure, with no database anywhere near it.
   See [diffing](/staticphp-core/audit/diffing/).
3. **`AuditEvent` is the row before it is a row.** A `readonly class`, because an audit entry
   that can be edited after the fact is not an audit entry. `Audit::record()` fills in the
   actor, the request context and the request id through `withResolved()`.
4. **`Store` writes it.** One `Db::insert()` on the caller's connection, inside the caller's
   transaction. See [storage](/staticphp-core/audit/storage/).

Beside those sit two tools. `staticphp audit` generates the schema and prunes old rows - see
[the audit cli](/staticphp-core/audit/audit-cli/) - and
`StaticPHP\Presentation\Models\Audit\AuditTable` builds a table over the trail for an
application to render, covered under [the audit table](/staticphp-core/audit/audit-table/).

## What one recorded change looks like

Every column of one row, after `Audit::insert('people', ['name' => 'Anna', 'city' =>
'Dobele'], module: 'catalog')` against sqlite:

```text
id            1
created_at    2026-08-02 01:03:10
request_id    61b2fb4290c4a53100ee5f6c8db97939
module        catalog
event         created
entity_type   people
entity_id     1
actor_type    user
actor_id      42
actor_name    Anna Berzina
old_values    NULL
new_values    {"name":"Anna","city":"Dobele"}
url           /people/edit
ip_address    10.0.0.9
user_agent    Mozilla/5.0
tags          NULL
context       NULL
```

## The schema decisions

The shape of `audit_log` is the part that is hard to change later, and each of these is
a deliberate trade rather than an accident. The reasoning is carried in the shipped `.sql`
files themselves; the summary is here and the columns are on
[storage](/staticphp-core/audit/storage/#the-audit_log-table).

**`module` is a column, not a table name.** Splitting the trail per module is the obvious
first design and it makes the two questions people actually ask expensive: "everything this
user did today" becomes a union across every module's table, and a change that crossed
several modules can no longer be grouped by `request_id` in one query. One table keeps both
as a single index scan. A deployment that does want the split points
`$config['audit']['table']` at a callable, and the columns do not change either way, so
splitting now and consolidating later is a data move rather than a rewrite.

**The actor is denormalised on purpose.** `actor_name` is stored on the row rather than
joined from a users table when the trail is read. Joining means that deleting or renaming a
user rewrites what the log says they did, years after the fact. The cost is a stale name on
old rows, which is the correct answer: the log records who did it *at the time*.

**`old_values` and `new_values` are split, not one blob.** A single `{from, to}` document
per change would be smaller, and it would make every question about a value unanswerable
without decoding every row. Split, a value is queryable directly:

```sql
-- postgres
SELECT * FROM audit_log WHERE new_values ->> 'status' = 'cancelled';
```

:::caution[`->>` is not portable to MariaDB]
`install.mysql.sql` names itself the schema "for mysql and mariadb" and its comment
advertises `WHERE new_values ->> '$.status' = 'cancelled'`. That operator is a MySQL
extension; MariaDB 11.8 rejects it, with or without the spaces, and needs the function form
instead. Both were run against the same data, one row of which had `city` changed to
`cancelled`:

```text
pgsql: SELECT entity_id FROM audit_log WHERE new_values ->> 'city' = 'cancelled'
  entity_id=1
mysql: SELECT entity_id FROM audit_log WHERE new_values ->> '$.city' = 'cancelled'
  PDOException: SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near '>> '$.city' = 'cancelled'' at line 1
mysql: SELECT entity_id FROM audit_log WHERE new_values->>'$.city' = 'cancelled'
  PDOException: SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near '>>'$.city' = 'cancelled'' at line 1
mysql: SELECT entity_id FROM audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.city')) = 'cancelled'
  entity_id=1

server: 11.8.8-MariaDB-ubu2404
```

Exercised against PostgreSQL 18 and MariaDB 11.8. **A real MySQL server was not available
here**, so the claim that `->>` works there is from its documentation rather than from a
run. `JSON_UNQUOTE(JSON_EXTRACT(...))` is accepted by both and is the safe spelling if you
do not know which of the two you are on.
:::

**There is no foreign key on `entity_type` / `entity_id`.** An audit row has to outlive what
it describes; a foreign key would delete the record of a deletion. The pair is stored as
text for the same reason one application keys on `bigint` and the next on `uuid`.

**The audit row is written on the caller's connection, inside the caller's transaction.**
A rolled back change takes its audit row with it, so the trail cannot claim something
happened that did not.

## Configuration

The shipped defaults live in `src/Utils/Config/Audit.php` and populate `$config['audit']`.
[Configuration](/staticphp-core/getting-started/configuration/#loading-more-config-files)
covers how to load one of this package's config files; each key is explained on the page for
the part that reads it.

| Key          | Default       | Read by                                                        |
| ------------ | ------------- | -------------------------------------------------------------- |
| `connection` | `'default'`   | every write and every read, as the entry of `$config['db']['pdo']` |
| `table`      | `'audit_log'` | `Audit::store()`; a string, or a `callable(AuditEvent): string` |
| `strict`     | `true`        | `Audit`, on a failed audit write                                |
| `max_rows`   | `1000`        | `Audit::update()` and `Audit::delete()`, before the write       |
| `id_key`     | `'id'`        | `Audit`, to fill `entity_id` from the affected row              |
| `actor`      | `null`        | `Audit::record()`; a `callable(): array` naming who did it      |
| `context`    | `null`        | `Audit::record()`; a `callable(): array` for url, ip and agent  |
| `exclude`    | `[]`          | `Diff`, as `table => [column, ...]`                             |

The same list is the `Audit::DEFAULTS` constant, so the trail works before an application
has written a config file for it. Settings are merged key by key over those defaults and
memoised; `Audit::reset()` forgets the merge, which is what a test or a long-running process
that changes configuration needs.

A minimal application override:

```php
<?php

$config['audit'] = [
    'actor' => fn (): array => [
        'type' => 'user',
        'id' => (string) ($_SESSION['user']['id'] ?? ''),
        'name' => (string) ($_SESSION['user']['name'] ?? ''),
    ],
    'exclude' => ['users' => ['password', 'remember_token']],
];
```

Core has no notion of authentication, so naming the actor is the application's job. Leaving
`actor` at `null` is legal and records an empty actor on every row.

`context` left at `null` reads the request itself: `$_SERVER['REQUEST_URI']`,
`Router::clientIp()` and `$_SERVER['HTTP_USER_AGENT']`. Going through `clientIp()` rather
than `$_SERVER['REMOTE_ADDR']` means a proxied deployment records the client rather than the
proxy, and cannot be told otherwise by a forged `X-Forwarded-For`; see
[reading the request behind a proxy](/staticphp-core/core/router/#reading-the-request-behind-a-proxy).

## Starting it

The table comes first, and nothing creates it for you:

```bash
./staticphp audit install
./staticphp migrate apply
```

`audit install` copies the schema for the configured driver into the migrations directory as
an ordinary migration and prints where it put it; `migrate apply` runs it. Both are covered
under [the audit cli](/staticphp-core/audit/audit-cli/#install) and
[the migrations cli](/staticphp-core/database/migrations-cli/#apply).

:::caution[Nothing checks that the table exists]
There is no probe and no lazy create. With `strict` left on - the default - the first
`Audit::insert()` against a missing table throws `AuditError`, and the change it was
recording has already been written. With `strict` off the same failure goes to `error_log()`
and the call continues. See
[when the trail cannot be written](/staticphp-core/audit/recording/#when-the-trail-cannot-be-written).
:::

Then, at a call site:

```php
<?php

use StaticPHP\Utils\Models\Audit\Audit;

Audit::update('people', $data, ['id' => $id], module: 'catalog');
```

That is the whole integration. There is no `init()`, no service to register and no state to
carry between requests beyond the memoised settings, store and request id.

## Reading it back

`AuditTable` builds the column set and the sort and filter state; the application runs its
own query and renders. Reading the trail needs authorisation and the framework has no notion
of who is allowed to, so it stops at a builder rather than shipping a controller. See
[the audit table](/staticphp-core/audit/audit-table/).
