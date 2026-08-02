---
title: Recording changes
description: The Audit facade, the AuditEvent it writes, and what each of the three wrappers actually records.
sidebar:
    order: 2
---

`StaticPHP\Utils\Models\Audit\Audit` has nine public methods and no instance:

```php
<?php

namespace StaticPHP\Utils\Models\Audit;

class Audit
{
    public static function requestId(): string;
    public static function reset(): void;

    public static function store(): Store;
    public static function setStore(?Store $store): void;

    public static function record(AuditEvent $event): void;

    public static function insert(
        string $table,
        array $data,
        string $module = '',
        ?string $entityId = null,
        ?string $returning = null,
        array $tags = [],
        ?array $context = null,
        ?string $connection = null
    ): mixed;

    public static function update(
        string $table,
        array $data,
        array $where,
        string $module = '',
        array $tags = [],
        ?array $context = null,
        ?string $connection = null
    ): PDOStatement;

    public static function delete(
        string $table,
        mixed $where,
        string $module = '',
        array $tags = [],
        ?array $context = null,
        ?string $connection = null
    ): PDOStatement;

    public static function diff(?array $before, ?array $after, array $exclude = []): array;
}
```

The three wrappers each perform the write themselves, through
[`Db::insert()`](/staticphp-core/database/db/#insert),
[`Db::update()`](/staticphp-core/database/db/#update) and
[`Db::delete()`](/staticphp-core/database/db/#delete), and return exactly what those return -
so a call site can be switched over without changing what it does with the result. The
argument lists are not the same, though: `$module` sits where `Db`'s `$name` does, and the
connection has moved to the end. Only `$module` is worth passing positionally; everything
after it is a named argument in practice.

`$connection` only ever moves the change: it reaches `Db::insert()` / `Db::update()` /
`Db::delete()` and `self::rows()`, nothing else. The audit row goes through
[`Audit::store()`](#swapping-the-store), which is built once from
`$config['audit']['connection']` and memoised, so it never sees the per-call argument.
Leaving `$connection` at `null` puts the change on that same configured connection, which is
why the change and its audit row usually land together - but passing one explicitly splits
them: the change moves, the trail does not follow it. Two sqlite connections, `default`
configured as `$config['audit']['connection']` and `reporting` passed to `insert()`:

```php
<?php

Audit::insert('people', ['name' => 'Anna'], connection: 'reporting');
```

```text
people on reporting:    1
audit_log on reporting: 0
people on default:      0
audit_log on default:   1
```

[The audit row living in the caller's transaction](#transactions) is only true when the two
coincide. Wrapping that call in a transaction on `reporting` and rolling it back removes the
`people` row but leaves the audit row on `default` committed:

```php
<?php

Db::beginTransaction('reporting');
Audit::insert('people', ['name' => 'Anna'], connection: 'reporting');
Db::rollBack('reporting');
```

```text
people on reporting:    0
audit_log on reporting: 0
audit_log on default:   1
```

## insert()

```php
<?php

Audit::insert('people', ['name' => 'Anna', 'city' => 'Dobele'], module: 'catalog');
```

The whole of `$data` goes into `new_values`; `old_values` stays `null`, because there was
nothing there. Excluded columns are masked rather than dropped, so the log still shows that a
password was set. Every column of the resulting row:

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

The return value is whatever `Db::insert()` returned, which depends on `$returning`: the
`PDOStatement` without it, the fetched row with it.

### What fills entity_id

`insertedId()` tries three sources in order, and the order matters because the last one
cannot always answer:

1. `$data[$config['audit']['id_key']]`, when the application supplied the key itself.
2. The same key on the `$returning` result, whether that came back as an array or an object.
3. `Db::lastInsertId('', false, $connection)`, wrapped so that a driver which refuses returns
   the empty string rather than failing the insert over a missing audit detail.

Passing `entityId:` skips all three. The same three `Audit::insert()` calls on each driver:

```text
===== sqlite =====
  {"name":"Anna"}        entity_id="1"
  {"name":"Girts"}       entity_id="2"
  {"name":"Ilze"}        entity_id="uuid-8f2c"
===== pgsql =====
  {"name": "Anna"}       entity_id=""
  {"name": "Girts"}      entity_id="2"
  {"name": "Ilze"}       entity_id="uuid-8f2c"
===== mysql =====
  {"name":"Anna"}        entity_id="1"
  {"name":"Girts"}       entity_id="2"
  {"name":"Ilze"}        entity_id="uuid-8f2c"
```

:::caution[On PostgreSQL a plain insert records no entity_id]
`lastInsertId()` on postgres needs the sequence name, and the trail does not know it, so step
3 returns `''` and the row is written with an empty `entity_id`. The change itself is
recorded correctly - only the link back to the record is missing, which is exactly the column
the `idx_audit_log_entity` index exists for.

Either pass the key you already have, or ask the database for it:

```php
<?php

Audit::insert('people', $data, entityId: (string) $id);
Audit::insert('people', $data, returning: 'RETURNING id');
```

SQLite and MySQL answer step 3 and need neither. Verified on PostgreSQL 18, MariaDB 11.8 and
sqlite 3.
:::

## update()

```php
<?php

Audit::update('people', ['name' => 'Anna Berzina', 'city' => 'Dobele'], ['id' => 1], module: 'catalog');
```

The rows are read **before** the update runs, so what is recorded is the change that
happened rather than the change that was requested. `city` was already `Dobele` and does not
appear:

```text
module        catalog
event         updated
entity_type   people
entity_id     1
old_values    {"name":"Anna"}
new_values    {"name":"Anna Berzina"}
```

`$where` takes the same shapes as
[`Db::update()`](/staticphp-core/database/db/#conditions) - it is typed `array`, so the raw
string escape hatch is not reachable here.

**One event per row actually changed.** A condition matching three rows of which two change
writes two rows, each with its own `entity_id`:

```text
rows updated: 2

event         updated
entity_id     1
old_values    {"city":"Dobele"}
new_values    {"city":"Riga"}

event         updated
entity_id     2
old_values    {"city":"Dobele"}
new_values    {"city":"Riga"}
```

**An update that changes nothing records nothing.** `Diff::between()` returns a `null` new
value and the loop skips it. The statement still ran, and sqlite still reports the row as
matched:

```text
rows updated: 1
(no rows)
```

That is the common case, not an error: writing the values a row already holds is legitimate
and happens constantly on a form that submits every field.

## delete()

```php
<?php

Audit::delete('people', ['id' => 1], module: 'catalog');
```

Unlike an update there is no second chance to find out what was there, so the whole row goes
into `old_values` - minus anything the exclude list covers - and `new_values` is `null`:

```text
event         deleted
entity_type   people
entity_id     1
old_values    {"id":1,"name":"Anna","city":"Dobele","active":1,"password":"***"}
new_values    NULL
```

`$where` is `mixed` here, matching `Db::delete()`, so the raw string form is accepted and is
concatenated unescaped. `Db::select()` resolves it identically, so the rows audited are the
rows deleted - here `Audit::delete('people', "city = 'Dobele'")` against a table holding one
matching row and one that does not:

```text
event         deleted
entity_id     1
old_values    {"id":1,"name":"Anna","city":"Dobele","active":1,"password":"***"}
people left: 1
```

Nothing is escaped in that form, so it must never be built from request data.

## Conditions that match nothing, or everything

A condition matching nothing is not an error - but it is also exactly what a mistyped
condition looks like, and silence is what lets that ship. `Audit::update()` and
`Audit::delete()` say so, through `error_log()`, and only when `$config['debug']` is on,
because on a busy application the benign reading of this happens constantly:

```text
Audit trail: update on "people" matched no rows
Audit trail: delete on "people" matched no rows
```

With debug off, nothing is logged at all. The check is `Config::getBool('debug')` rather than
[`resolveDebug()`](/staticphp-core/core/config/#resolvedebug), which may call into the
application's own gate and is far too heavy to run on every write.

The opposite mistake is guarded differently. `$config['audit']['max_rows']` - 1000 by default
- is checked **before** the update runs, so a condition that matches the whole table does not
become a write plus half a million audit rows:

```text
StaticPHP\Utils\Models\Audit\AuditError: Refusing to audit 3 rows of "people" in one call; config['audit']['max_rows'] is 2. Narrow the condition, or raise the limit.
people after the refusal: [{"id":1,"city":"Dobele"},{"id":2,"city":"Dobele"},{"id":3,"city":"Dobele"}]
trail rows: 0
```

The refusal goes through the same failure path as a broken audit write, so `strict` decides
whether it throws or is logged. Setting `max_rows` below 1 disables the check - three rows
audited against a limit of `0`:

```text
trail rows with max_rows = 0: 3
```

The value has to be a real `int`. Anything else falls back to 1000, so a limit written as a
string is silently not the limit:

```text
trail rows with max_rows = '2': 3
```

:::caution[An empty condition is not caught here]
`Audit::update('people', $data, [])` builds no `WHERE` at all. Two rows in the table, two
rows in the trail, and the whole table updated:

```text
trail rows: 2
```

`Audit::delete('people', [])` is saved only by `Db::delete()`'s own guard, which throws
before anything is written - so the row survives and nothing is recorded:

```text
InvalidArgumentException: Db::delete() requires a condition. Pass an explicit "1 = 1" to truncate a table.
people rows: 1
trail rows:  0
```

`max_rows` only helps once the table is bigger than the limit. There is no empty-condition
guard on the audit side; see
[`Db::select()`](/staticphp-core/database/db/#select), which has none either.
:::

## record() and AuditEvent

`record()` is the layer underneath the three wrappers, and the way to log something they do
not cover - an export, a login, an approval, a state machine transition:

```php
<?php

use StaticPHP\Utils\Models\Audit\Audit;
use StaticPHP\Utils\Models\Audit\AuditEvent;

Audit::record(new AuditEvent(
    event: 'exported',
    entityType: 'people',
    entityId: '42',
    module: 'catalog',
    actorType: 'cron',
    actorId: 'nightly-sync',
    actorName: 'Nightly sync',
    tags: ['gdpr'],
    context: ['batch' => 7]
));
```

```text
module        catalog
event         exported
entity_type   people
entity_id     42
actor_type    cron
actor_id      nightly-sync
actor_name    Nightly sync
old_values    NULL
new_values    NULL
url           /people/edit
ip_address    10.0.0.9
tags          ["gdpr"]
context       {"batch":7}
```

Note what happened to the actor and the context. The explicit actor was kept; the url and ip
address, which this call did not name, were filled in from the resolvers. Nothing performs a
diff, so `old_values` and `new_values` are both `null` unless the caller supplies them.

`AuditEvent` is a `readonly class` with sixteen promoted properties and one method:

```php
<?php

readonly class AuditEvent
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const DELETED = 'deleted';

    public function __construct(
        public string $event,
        public string $entityType,
        public string $entityId = '',
        public string $module = '',
        public ?array $oldValues = null,
        public ?array $newValues = null,
        public string $actorType = '',
        public string $actorId = '',
        public string $actorName = '',
        public string $requestId = '',
        public string $url = '',
        public string $ipAddress = '',
        public string $userAgent = '',
        public array $tags = [],
        public ?array $context = null,
        public ?string $createdAt = null,
    );

    public function withResolved(array $actor, array $context, string $requestId): self;
}
```

Only `$event` and `$entityType` are required. Readonly is the point: an audit entry that can
be edited after the fact is not an audit entry.

`event` is **free text**. The three constants are what the wrappers write, and
`record()` takes anything - a `smallint` column or a postgres enum would turn adding an event
type into a migration, which is the wrong tax on a log. `entityType` is a table name, not a
class name, and `entityId` is text so that `bigint` and `uuid` keys both fit.

### withResolved()

`record()` calls it, with the actor, the request context and the request id. It returns a
copy in which **only the empty fields** have been replaced, so a caller that named the actor
itself keeps what it passed:

```php
<?php

$event = new AuditEvent(event: 'created', entityType: 'people', actorName: 'Nightly sync');

$resolved = $event->withResolved(
    ['type' => 'user', 'id' => '42', 'name' => 'Anna Berzina'],
    ['url' => '/people/edit', 'ip_address' => '10.0.0.9', 'user_agent' => 'Mozilla/5.0'],
    'a1b2c3'
);
```

```text
actorType: "user"
actorId:   "42"
actorName: "Nightly sync"
requestId: "a1b2c3"
url:       "/people/edit"
the original is untouched: ""
```

A field left empty by both the caller and the resolvers stays empty. With no `actor` resolver
configured, every row records an empty actor rather than failing:

```text
actor_type    
actor_id      
actor_name    
```

`createdAt` left at `null` lets the store stamp the row; supplying one keeps it, which is how
a backfill records when the change happened rather than when it was imported:

```text
created_at    2019-01-01 00:00:00
event         imported
```

## requestId()

```php
<?php

Audit::requestId(); // 32 hex characters
```

Generated once per process from `random_bytes(16)`, so a request that touches five tables
leaves five rows that can be read back as one action. Under the cli it covers the whole
command run, which is the useful reading for a nightly import:

```text
Audit::requestId() = ba23c9d35c4a82656d2c8601da13f14f

request_id    ba23c9d35c4a82656d2c8601da13f14f
module        catalog
event         updated
entity_id     1

request_id    ba23c9d35c4a82656d2c8601da13f14f
module        import
event         created
entity_id     2
```

There is no index on `request_id` in the shipped schema; one is suggested in the `.sql`
comments and left for a deployment that queries by it. See
[the indexes](/staticphp-core/audit/storage/#the-indexes).

## reset()

```php
<?php

Audit::reset();
```

Forgets the memoised settings, the store and the request id - all three, not one. A test that
changes `$config['audit']` needs it, and so does anything that serves more than one logical
request in a process:

```php
<?php

$first = Audit::requestId();
Audit::reset();
$second = Audit::requestId();
```

```text
same request id after reset(): no
```

## Tags, context and module

`$module` is a plain string naming which part of the application made the change. It is
stored in a column rather than deciding a table name; the reasoning is on
[the overview](/staticphp-core/audit/overview/#the-schema-decisions). It also reaches a
`table` resolver, which is how a deployment that does split the trail splits it.

`$tags` is a `list<string>`, stored as a json array, or `null` when the list is empty.
`$context` is whatever else the application wants to keep, stored as json. Neither is
indexed and neither is read by anything in the framework - they are there so an application
does not have to fork the schema to record one extra field.

## When the trail cannot be written

`$config['audit']['strict']` decides. **On, the default**, the failure is rethrown as
`AuditError`:

```text
StaticPHP\Utils\Models\Audit\AuditError: Audit trail: SQLSTATE[HY000]: General error: 1 no such table: no_such_table
people rows: 1
```

Read the second line: the `people` row is still there. Strict does not undo the change, it
surfaces the failure - and outside a transaction the change has already committed. On
postgres rethrowing is the honest option anyway, because the failed insert has already
aborted the surrounding transaction, so swallowing it would not rescue the change, it would
only hide why the commit fails later.

**Off**, the same failure goes to `error_log()` and the call continues:

```text
people rows: 1
Audit trail: SQLSTATE[HY000]: General error: 1 no such table: no_such_table
```

That is availability over completeness, which is rarely the trade an audit trail wants to
make. It is a choice, not a silence: the line is always logged.

`AuditError` extends `\RuntimeException` and carries the original exception as its previous,
except when the thing that failed was already an `AuditError` - a refused table name or a
`max_rows` refusal - in which case it is rethrown unchanged and has no previous at all:

```text
previous: PDOException
previous on a max_rows refusal: NULL
```

## Transactions

`Store::write()` inserts on the caller's connection, so it is inside the caller's transaction
without doing anything to arrange it. A rolled back change takes its audit row with it:

```php
<?php

Db::beginTransaction();
Audit::insert('people', ['name' => 'Anna']);
Db::rollBack();
```

```text
people rows: 0
trail rows:  0
```

That holds here because `$connection` was left at `null`, so the change and the trail were on
the same connection to begin with. The trail itself has no connection of its own to move - it
is always `Audit::store()`'s, fixed at `$config['audit']['connection']`. Pass `$connection`
explicitly, as above, and it does not move the trail with the change; it splits the two, and
this guarantee holds no further than the connection the change happened to share with it. See
[transactions](/staticphp-core/database/db/#transactions).

## Swapping the store

```php
<?php

Audit::setStore(new Store('reporting', 'audit_log'));
Audit::setStore(null); // back to the configured one, rebuilt on next use
```

`store()` builds one from `$config['audit']` on first use and memoises it; `setStore()`
replaces it, and `null` clears the memo so the next call rebuilds from configuration. The
third line below is `(new Store('default', 'audit_log'))->connection()`, which is the only
thing `connection()` does:

```text
class:      StaticPHP\Utils\Models\Audit\Store
tableFor(): audit_log
connection: default
after setStore(): history_catalog
after setStore(null): audit_log
```

This is for tests and for an application that assembles its own store. Everything about
`Store` itself is on [storage](/staticphp-core/audit/storage/).

## diff()

```php
<?php

public static function diff(?array $before, ?array $after, array $exclude = []): array;
```

A passthrough to `Diff::between()`, returning the `[old, new]` pair. It is here so that an
application computing a change itself - one that did not go through `Db` at all - can apply
the same rules before handing the result to `record()`:

```php
<?php

Audit::diff(
    ['name' => 'Anna', 'password' => 'a'],
    ['name' => 'Anna Berzina', 'password' => 'b'],
    ['password']
);
```

```text
[{"name":"Anna","password":"***"},{"name":"Anna Berzina","password":"***"}]
```

See [diffing](/staticphp-core/audit/diffing/).
