---
title: Db
description: Connecting through PDO, running queries, and exactly which parts of a statement are bound and which are concatenated.
sidebar:
    order: 1
---

`StaticPHP\Utils\Models\Db` is a static wrapper around PDO. It holds one PDO instance per
connection name, opens connections on first use, and adds a small set of query helpers on
top. It is not an ORM and there is no query builder object: every method takes a table or a
query string and returns a `PDOStatement` or plain rows.

Everything is static. There is no instance to inject and no state beyond the open
connections, the configuration each was opened with, and the last statement executed.

## Connections

```php
<?php

public static function init(string $name = 'default', ?array $config = null): PDO;
public static function &dbLink(string $name = 'default'): PDO;
public static function close(string $name = 'default'): void;
```

`init()` returns the open connection if there already is one under `$name` - the check
happens before the configuration is consulted, so a connection opened by passing `$config`
directly can afterwards be reached by name alone. Otherwise it reads
`Config::$items['db']['pdo'][$name]`, and throws `\Exception('Db configuration not found')`
when there is no such entry.

The keys of a connection entry are covered in
[configuration](/staticphp-core/getting-started/configuration/#db). What `init()` does with
them:

| Key                  | Effect                                                                                          |
| -------------------- | ----------------------------------------------------------------------------------------------- |
| `string`             | The DSN, passed to `new PDO()` as is - except for the charset rule below                         |
| `username`, `password` | Passed straight through                                                                        |
| `fetch_mode_objects` | `PDO::ATTR_DEFAULT_FETCH_MODE` becomes `PDO::FETCH_OBJ` when truthy, `PDO::FETCH_ASSOC` otherwise |
| `persistent`         | Sets `PDO::ATTR_PERSISTENT`, but only when the key is present at all (`isset`, not `empty`)      |
| `emulate_prepares`   | Sets `PDO::ATTR_EMULATE_PREPARES` to `(bool) ($config['emulate_prepares'] ?? false)` - always set, never left to the driver default |
| `charset`            | Appended to the DSN, see below                                                                    |
| `wrap_column`        | Not a PDO option. Used later, when identifiers are quoted                                        |
| `debug`              | Not a PDO option. Turns on query timing                                                          |

Two options are set unconditionally and cannot be configured: `PDO::ATTR_ERRMODE` is
`PDO::ERRMODE_EXCEPTION`, so a failing statement throws rather than returning false, and
`PDO::ATTR_CASE` is `PDO::CASE_NATURAL`, so column names arrive as the driver spells them.

### The charset goes in the DSN

`charset` is validated against `/^[A-Za-z0-9_]+$/` and an `\InvalidArgumentException` is
thrown if it does not match. It is then appended to the DSN - but only when the DSN starts
with `mysql:` and does not already contain `charset=`. For a pgsql or sqlite DSN the value
is validated and then unused.

Setting the charset with a `SET NAMES` query after connecting would change the connection
without changing what PDO believes about it, and with emulated prepares its client-side
quoting would keep using the DSN charset - which is the multi-byte injection hole. It also
costs a round trip on every connect.

### Connections open on first use

`query()` resolves the connection through a private `link($name, true)`, which opens a
configured-but-unopened connection itself. A request that never touches the database never
pays for a connect, so calling `init()` during bootstrap is optional.

A name that is neither open nor configured produces
`No connection to database "x" (open connections: default)`. `beginTransaction()`,
`commit()`, `rollBack()` and `inTransaction()` resolve through the same helper with
`$connect` left false, so they only ever work on an already-open connection.

`dbLink()` bypasses that helper entirely and returns `self::$dbLinks[$name]` **by
reference**. For an unknown name that creates a null entry and then fails the `PDO` return
type with a `TypeError`; the null entry stays behind and shows up in the list of open
connections in later error messages. Use it only for a connection you know is open.

`close()` unsets the entry, dropping the last reference to the PDO object. The name can be
opened again afterwards, provided it exists in the configuration - an inline `$config` is not
remembered across a close.

## Running queries

```php
<?php

public static function query(string $query, array $data = [], string $name = 'default'): PDOStatement;
public static function fetch(string $query, array $data = [], string $name = 'default'): mixed;
public static function fetchAll(string $query, array $data = [], string $name = 'default'): array;
public static function select(string $table, mixed $where, string $columns = '*', string $name = 'default'): array;
```

`query()` throws on an empty query string, prepares the statement, executes it with
`(array) $data`, stores it as the last statement and returns it. `fetch()` is a one-line
wrapper that calls `->fetch()` on the result. `fetchAll()` puts its result through
`array_values()`, so the returned list is renumbered from zero whatever the driver's own
keys were.

A `PDOStatement` is traversable, so `query()` doubles as the iterator form for large result
sets:

```php
<?php

use StaticPHP\Utils\Models\Db;

$rows = Db::fetchAll('SELECT id, title FROM posts WHERE published = ?', [1]);
$row = Db::fetch('SELECT * FROM posts WHERE id = ?', [$id]);

foreach (Db::query('SELECT * FROM posts') as $post) {
    // one row at a time, nothing buffered by this class
}
```

`fetch()` returns whatever PDO returns, so a query that matched nothing yields `false`, not
`null`. The row shape follows `fetch_mode_objects`: an associative array by default, a
`stdClass` when the connection sets it.

Neither shape is a framework type, and nothing here wraps one.
[`RecordObject`](/staticphp-core/database/record-object/) will wrap a row so it can be read
as an object, an array and an iterator at once, and encoded to json directly - but the
application has to construct it from what `fetch()` handed back.

## Parameter binding

This is the part worth reading closely. Some of what you pass is bound as a parameter and
some of it is concatenated into the SQL string, and the difference is not always where you
would expect.

### Bound

Everything in `$data` of `query()`, `fetch()` and `fetchAll()` is handed to
`PDOStatement::execute()` and never touched by this class. Both placeholder styles work,
because it is PDO doing the work:

```php
<?php

Db::fetch('SELECT * FROM posts WHERE id = ?', [$id]);
Db::fetch('SELECT * FROM posts WHERE id = :id', ['id' => $id]);
```

With the shipped `emulate_prepares => false` these are real server-side prepared
statements: values go to the server separately from the SQL, and typed columns come back
typed rather than as strings.

Values in the `$data` array of `insert()` and `update()`, and values in the conditions
array of `select()`, `update()` and `delete()`, are bound too - including every element of an
array value, which expands to one `?` per element.

### Concatenated

Everything else. In particular:

- **The table name.** `select()`, `insert()`, `update()` and `delete()` interpolate `$table`
  into the query verbatim. It is not validated, not quoted, and not checked against
  anything. Never build it from request data.
- **Column names.** They cannot be bound in SQL, so they are validated and quoted instead -
  see below.
- **Any key prefixed with `!`** in the `$data` array of `insert()` or `update()`. The value
  is written into the query as SQL.
- **A `$where` passed as a string** to `delete()` or `select()`. It is appended after `WHERE`
  with no escaping at all. `update()` types its `$where` as `array`, so it cannot reach this.
- **The `$columns` and `$table` arguments** of `select()`, both written into the statement as
  given.
- **The `$returning` argument** of `insert()`, appended to the statement as written.

The `!` prefix and the string form of `$where` are documented in the source as deliberate
escape hatches, each with an explicit warning never to build one from request data. The
table name, `$columns` and `$returning` carry no such warning and need exactly the same
care. Every one of them is only safe for literals the application controls.

### Identifiers

Every column name in `insert()`, `update()` and the conditions array goes through a private
`wrapColumn()`, which validates it against
`/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/` and throws
`\InvalidArgumentException: Invalid column name: "..."` on anything else. A matching name is
then wrapped in the connection's `wrap_column` character; a qualified `t.col` has each half
wrapped separately, giving `` `t`.`col` ``.

Rejecting rather than escaping is the point: this is the only thing standing between a
caller-supplied column name and SQL injection.

One consequence of the lazy connect is worth knowing. `wrapColumn()` reads `wrap_column`
out of the per-connection configuration cache that `init()` fills, and `select()`,
`insert()`, `update()` and `delete()` build their SQL *before* calling `query()`, which is
what opens the connection. On the very first statement of a connection that was never
explicitly opened, that cache is still empty and the fallback is an empty string. With
`wrap_column` set to `"`:

```text
insert #1 on a lazily opened connection: INSERT INTO t (name, age) VALUES (?, ?)
insert #2 and every one after it:        INSERT INTO t ("name", "age") VALUES (?, ?)
```

It only matters when a column name needs quoting to be legal - a reserved word, say. Call
`Db::init($name)` once at boot if that is a risk.

## select()

```php
<?php

public static function select(string $table, mixed $where, string $columns = '*', string $name = 'default'): array;
```

```php
<?php

Db::select('posts', ['id' => $id]);
// SELECT * FROM posts WHERE `id` = ?;   then fetchAll()

Db::select('posts', ['author_id' => [3, 4]], 'id, title');
// SELECT id, title FROM posts WHERE `author_id` IN (?, ?);
```

It exists so that [the audit trail](/staticphp-core/audit/overview/) can read the rows an
update is about to change while resolving the condition exactly as the update will - a second
implementation of the condition builder would audit different rows than it wrote.
`Audit::update()` and `Audit::delete()` are the only callers in the package; see
[recording changes](/staticphp-core/audit/recording/). `$where` therefore takes the same
shapes as [the conditions](/staticphp-core/database/db/#conditions) below, the raw string
form included. `$table` and `$columns` are concatenated as given, so neither may come from
request data.

There is no empty-condition guard here, unlike in `delete()`: `Db::select('posts', [])`
builds no `WHERE` at all and reads the whole table.

## insert()

```php
<?php

public static function insert(
    string $table,
    array $data,
    string $name = 'default',
    ?string $returning = null
): mixed;
```

```php
<?php

Db::insert('posts', ['title' => 'New post', 'author_id' => 3]);
// INSERT INTO posts (`title`, `author_id`) VALUES (?, ?)

Db::insert('posts', ['title' => 'New post', '!created_at' => 'NOW()']);
// INSERT INTO posts (`title`, `created_at`) VALUES (?, NOW())

$row = Db::insert('posts', ['title' => 'New post'], 'default', 'RETURNING id');
// INSERT INTO posts (`title`) VALUES (?) RETURNING id, then ->fetch()
```

The return type depends on `$returning`: with it empty the `PDOStatement` comes back, with
it set the statement is fetched and the row comes back instead. The docblock says
`@return PDOStatement` and does not list `$returning` among its parameters at all; the code
is what is described here.

Boolean values in `$data` are converted to the strings `'true'` and `'false'` before
binding, in both `insert()` and `update()`. On a database with a real boolean type that
still works; on SQLite the column ends up holding the text `true`.

## update()

```php
<?php

public static function update(string $table, array $data, array $where, string $name = 'default'): PDOStatement;
```

```php
<?php

Db::update('posts', ['title' => 'Changed', '!views' => 'views + 1'], ['id' => $id]);
// UPDATE posts SET `title` = ?, `views` = views + 1 WHERE `id` = ?;
```

`$where` is typed `array`, so the raw-string escape hatch described below is not reachable
here.

## delete()

```php
<?php

public static function delete($table, $where, $name = 'default');
```

The one method with no parameter or return types - the docblock claims `\PDOStatement`,
which is what it returns. An empty `$where` throws:

```text
Db::delete() requires a condition. Pass an explicit "1 = 1" to truncate a table.
```

Because `$where` is untyped, a string is accepted and appended verbatim:
`Db::delete('posts', 'created_at < NOW() - INTERVAL 1 YEAR')` becomes
`DELETE FROM posts WHERE created_at < NOW() - INTERVAL 1 YEAR;`. Nothing is escaped.

## Conditions

`select()`, `update()` and `delete()` share one condition builder. A key is a column name
optionally followed by whitespace and an operator; the operator is upper-cased and must be
one of:

```text
=  !=  <>  <  <=  >  >=  LIKE  NOT LIKE  ILIKE  NOT ILIKE  IS  IS NOT  IN  NOT IN
```

Anything else throws
`\InvalidArgumentException: Unsupported comparison operator: "..."`. Conditions are joined
with `AND`; there is no way to express `OR` other than the raw string form.

What each shape produces, with `wrap_column` set to `"`:

| Conditions                     | SQL                              |
| ------------------------------ | -------------------------------- |
| `['id' => 1]`                  | `WHERE "id" = ?`                 |
| `['id !=' => 1]`               | `WHERE "id" != ?`                |
| `['name like' => '%a%']`       | `WHERE "name" LIKE ?`            |
| `['age IS' => null]`           | `WHERE "age" IS ?`               |
| `['id' => [1, 2]]`             | `WHERE "id" IN (?, ?)`           |
| `['!id' => [1, 2]]`            | `WHERE "id" NOT IN (?, ?)`       |
| `['id' => []]`                 | `WHERE 1 = 0`                    |
| `['!id' => []]`                | `WHERE 1 = 1`                    |
| `['id' => [1, 2], 'x' => 'a']` | `WHERE "id" IN (?, ?) AND "x" = ?` |
| `['!name' => 'a']`             | `WHERE "name" = ?`               |
| `['id NOT IN' => [1, 2]]`      | `WHERE "id" IN (?, ?)`           |

The last two rows are the traps, and they are the mirror image of each other:

- **For a scalar value, the `!` prefix is stripped and otherwise ignored.** The source says
  so explicitly - it matches the behaviour of the code this replaced. Write
  `['id !=' => $x]`, not `['!id' => $x]`.
- **For an array value, the operator in the key is discarded** and replaced by `IN` or
  `NOT IN` depending on the `!` prefix. Write `['!id' => [1, 2]]`, not
  `['id NOT IN' => [1, 2]]` - the latter silently produces `IN`.

An empty array is collapsed to a constant with the same truth value, because `IN ()` is not
valid SQL anywhere. Note what `['!id' => []]` therefore means for `delete()`: `WHERE 1 = 1`,
which deletes the table. The empty-condition guard does not catch it, because the
conditions array was not empty.

`IS` and `IS NOT` still bind their value as a parameter, producing `"age" IS ?` rather than
`IS NULL`. Whether that is legal depends on the database - SQLite accepts it, others do
not. For a null test, prefer the raw string form or a hand-written query.

## Transactions

```php
<?php

public static function beginTransaction(string $name = 'default'): bool;
public static function inTransaction(string $name = 'default'): bool;
public static function commit(string $name = 'default'): bool;
public static function rollBack(string $name = 'default'): bool;
```

Thin pass-throughs to the PDO methods of the same names, on the connection named by
`$name`. They do not open a connection, so a transaction can only be started on one that is
already open - by an earlier query, or by an explicit `Db::init()`.

There is no nesting and no savepoint support in these three; that is PDO's behaviour,
unchanged. `Db::transaction()`, below, is where nesting is handled.

## Db::transaction()

```php
<?php

public static function transaction(callable $work, string $name = 'default'): mixed;
```

Runs `$work` inside a transaction on the connection named by `$name`, committing it if
`$work` returns and rolling it back if it throws. `$work` receives the `PDO` instance for
that connection, and whatever `$work` returns is passed straight back through - the usual
shape reads as an assignment:

```php
<?php

$id = Db::transaction(function () {
    $id = Db::insert('orders', $order, returning: 'id');
    Db::insert('order_lines', ['order_id' => $id] + $line);

    return $id;
});
```

Written out by hand this is four lines, and two of them are easy to get wrong: catching
`Exception` rather than `Throwable` leaves a `TypeError` or an assertion failure to end the
request with the transaction still open, and calling `beginTransaction()` while a
transaction is already running would commit that outer one on the inner call's behalf.

`Db::transaction()` avoids the second problem by nesting. If the connection is already in a
transaction when it is called, it opens a savepoint instead of a new transaction, so only
the inner `$work` is discarded on failure - the caller who opened the outer transaction
still decides its fate:

```php
<?php

$depth = (self::$savepoints[$name] ?? 0) + 1;
$savepoint = "staticphp_sp{$depth}";
$db_link->exec("SAVEPOINT {$savepoint}");
```

The savepoint name is built from a per-connection depth counter rather than taken from the
caller, so it cannot carry anything that needs quoting into a statement that cannot be
prepared. On success, an outermost call commits and a nested call releases its savepoint -
but only if the connection is still in a transaction, since `$work` is free to have
committed or rolled back on its own; asking PDO rather than assuming keeps that from
surfacing as "there is no active transaction" thrown from inside `transaction()` instead of
from whatever code actually did it. On a throw, an outermost call rolls back the whole
transaction and a nested call rolls back to its savepoint and releases it, then the original
exception is rethrown unchanged. Failures during that unwind are swallowed on purpose - the
caller is already unwinding with a real error, and a connection that has dropped
mid-transaction will fail the rollback too, so surfacing that would replace the cause with
its consequence.

Beware of ddl on mysql: `CREATE`, `ALTER` and `DROP` commit the open transaction implicitly,
so a rollback afterwards has nothing left to undo. Postgres and sqlite are transactional
over ddl and do not have this problem.

For a worked example of nested transactions in practice, see
[enqueueing a job inside one](/staticphp-core/queue/enqueueing/).

## Introspection

```php
<?php

public static function &lastStatement(): ?PDOStatement;
public static function lastQuery(): ?string;
public static function lastInsertId(string $sequence_name = '', bool $sql = false, string $name = 'default'): ?int;
```

`lastStatement()` is the statement from the most recent `query()` call on **any**
connection - it is a single static slot, not one per name. `lastQuery()` is that
statement's `queryString`, or null when nothing has run yet. Both are useful in debugging
and in error reporting; neither is a substitute for logging.

`lastInsertId()` has two modes. With `$sql` false, the default, it calls PDO's own
`lastInsertId($sequence_name)` on the named connection - note that it reads `$dbLinks`
directly, so the connection must already be open. With `$sql` true it issues a query
instead: `SELECT LAST_INSERT_ID() as id` with no sequence name, or
`SELECT currval(?) as id` with one. The result is cast to `int`, and a falsy result comes
back as `null`.

## Debug timing

When the connection's configuration has a truthy `debug`, `query()` wraps each execution in
`Timers::startTimer()` and `Timers::stopTimer($log)`, where `$log` is the query with each
`?` replaced by the corresponding value - integers bare, everything else in single quotes.
See [timers](/staticphp-core/core/timers/#starttimer-and-stoptimer).

```text
SELECT * FROM migrations WHERE name = 'a' AND duration_ms = 0
```

That string is a label only. It is never executed, the quoting is not escaping, and it is
not what went to the server. Two points to be aware of:

- The substitution counts every `?` in the query, including one inside a string literal. If
  the count exceeds the number of values, PHP emits an undefined-key warning from inside
  `query()`.
- The timer is keyed by the interpolated string, so identical queries overwrite each other
  in the timers table rather than accumulating.

Leave `debug` off outside development. The shipped configuration derives it from
`$config['debug']`, which is the right default.
