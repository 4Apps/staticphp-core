---
title: Sessions
description: The session save handler, its five storage backends, and the table the Postgres one needs.
sidebar:
    order: 2
---

`StaticPHP\Utils\Models\Sessions\Sessions` is a `SessionHandlerInterface` implementation
that php's own session machinery drives. The base class holds the cookie and ini policy;
five subclasses supply the storage.

Nothing in the framework constructs one. The bootstrap never touches sessions, so an
application that wants them wires this up itself.

## The base class

```php
<?php

namespace StaticPHP\Utils\Models\Sessions;

class Sessions implements \SessionHandlerInterface
{
    public function __construct(
        string $sessionName = 'S',
        ?Sessions $backupHandler = null,
        int $lifetime = 86400,
        string $sameSite = 'Lax'
    );

    public function register(): void;
    public function start(): void;
    public function regenerate(bool $deleteOldSession = true): bool;
    public function id(string $id): string;

    // SessionHandlerInterface
    public function open(string $path, string $name): bool;
    public function close(): bool;
    public function read(string $id): string|false;
    public function write(string $id, string $data): bool;
    public function destroy(string $id): bool;
    public function gc(int $maxLifetime): int|false;
}
```

Used as:

```php
<?php

use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Sessions\SessionsRedis;

$sessions = new SessionsRedis(Config::get('cache')['redis'], 'MYAPP');
$sessions->register();
$sessions->start();
```

`register()` calls `session_name()`, `session_set_cookie_params()` and
`session_set_save_handler($this, true)`; `start()` is a plain `session_start()`. Keep them
separate calls - anything that has to happen between naming the handler and opening the
session goes in between.

### What the constructor sets

Four ini settings, applied immediately:

```text
session_cache_expire() (php default)         => 180
$this->expire = session_cache_expire() * 60  => 10800
after new Sessions('S'): session.gc_maxlifetime => '86400'
session.use_only_cookies                     => '1'
session.use_strict_mode                      => '1'
session.gc_probability / gc_divisor          => '1 / 100'
Sessions('S')->id('abc')                     => 'S_abc'
regenerate() with no active session          => false
```

`session.use_only_cookies` and `session.use_strict_mode` are forced on, garbage collection
runs on one request in a hundred, and `session.gc_maxlifetime` takes the `$lifetime`
argument. The cookie gets `path=/`, an empty domain, `httponly`, the `$sameSite` value, and
`secure` from `Router::requestIsSecure()`, so plain http development keeps working.

`$sameSite` is checked against `Lax`, `lax`, `None`, `none`, `Strict` and `strict`; anything
else falls back to `Lax` rather than reaching php, which would reject it with a warning.

:::caution[Behind a tls-terminating proxy the `Secure` flag needs `trust_proxy_headers`]
`Router::requestIsSecure()` reads `X-Forwarded-Proto` only when
`$config['trust_proxy_headers']` is on, and it defaults to off. Its remaining tests are
`$_SERVER['HTTPS']` set to anything other than `off`, then `SERVER_PORT === '443'` - and
behind tls termination the connection this process sees is plain http on the internal port,
so neither fires. The session cookie then goes out without `Secure` on exactly the
deployment where it matters most. Turn `trust_proxy_headers` on, and see
[proxy headers](/staticphp-core/core/request/#proxy-headers) for what else it governs.
:::

`$this->expire` is `session_cache_expire() * 60`, which on a stock php is 10800 seconds. It
is unrelated to `$lifetime`, and it is what the APCu, Memcached and Redis backends pass as
their store TTL. So with the defaults the cookie and `gc_maxlifetime` say one day while the
stored record says three hours.

`$this->salt` is the first 40 hex characters of `sha256` of the request's `User-Agent`. The
Postgres and MongoDB backends match on it as well as on the id. The comment in the source is
honest about what that is worth: the header is client controlled, so an attacker replaying a
stolen cookie can replay the header with it. It is a speed bump. 40 characters is the width
of the `salt` column in the shipped schema.

### Session fixation

`regenerate()` is the fixation defence, and calling it is the application's job:

```php
<?php

// immediately after a successful login
$sessions->regenerate();
```

It returns `false` without doing anything when no session is active. Nothing calls it for
you.

### Chaining a backup handler

Every subclass takes a `?Sessions $backupHandler` and every base-class method delegates to
it. The pattern is read-through: a subclass looks in its own store first and calls
`parent::read()` - and so the backup - only when it comes back empty. Writes and destroys go
to both. `Sessions` used on its own stores nothing: `read()` returns `''`, `write()` and
`destroy()` return `true`, `gc()` returns `false`.

`id()` is the storage key the key-value backends use, `"{$sessionName}_{$id}"`. The Postgres
backend does not use it; it stores the raw session id.

## The five backends

| Backend   | Class               | Needs installed                        |
| --------- | ------------------- | -------------------------------------- |
| APCu      | `SessionsApcu`      | `ext-apcu`                             |
| Memcached | `SessionsMemcached` | `ext-memcached`                        |
| MongoDB   | `SessionsMongoDb`   | `ext-mongodb` **and** `mongodb/mongodb` (the `MongoDB\Client` class) |
| Postgres  | `SessionsPgsql`     | a pdo postgres driver (inferred - see below), plus the table below |
| Redis     | `SessionsRedis`     | `ext-redis`                            |

Four of those five are what the class references by name - `Redis`, `Memcached`,
`MongoDB\Client`, `apcu_fetch()`. The Postgres row is an inference: `SessionsPgsql` goes
through `Db`, which is PDO, and its sql is postgres, so it needs whatever pdo driver that
implies. Nothing in `src/` names `pdo_pgsql`; `composer.json` does, under `suggest`, giving
`SessionsPgsql` and the postgres migration driver as the reasons.

None of the five is a hard dependency. `require` is still php plus `ext-intl`,
`ext-mbstring` and the generic `ext-pdo`; all five appear under `suggest`, each entry naming
the classes that need it. `suggest` is advisory - composer prints it and installs nothing -
so the package installs on a host that has none of them and the failure surfaces at
construction. `ext-mongodb` is not named there: the entry is the `mongodb/mongodb` library,
which requires the extension in turn.

On a host with only the framework's own required extensions:

```text
new SessionsRedis(['hostname' => 'redisdb', 'port' => 6379]) => Error: Class "Redis" not found
new SessionsMemcached([['127.0.0.1', 11211]])        => Error: Class "Memcached" not found
new SessionsMongoDb('mongodb://localhost:27017')     => Error: Class "MongoDB\Client" not found
(new SessionsApcu())->read('abc')                    => Error: Call to undefined function StaticPHP\Utils\Models\Sessions\apcu_fetch()
```

Constructor signatures, which differ from each other more than you would expect:

```php
<?php

new SessionsApcu(string $sessionName = 'SAPC', ?Sessions $backupHandler = null);

new SessionsMemcached(
    array $servers,
    ?string $persistentId = null,
    string $sessionName = 'SMC',
    ?Sessions $backupHandler = null
);

new SessionsMongoDb(
    string $connectionString,
    string $databaseName = 'sessions',
    string $sessionName = 'SMDB',
    ?Sessions $backupHandler = null
);

new SessionsPgsql(array $dbConfig, string $sessionName = 'SMC', ?Sessions $backupHandler = null);

new SessionsRedis(array $dbConfig, string $sessionName = 'SMDB', ?Sessions $backupHandler = null);
```

:::caution[`$lifetime` and `$sameSite` are unreachable through a subclass]
All five pass only `$sessionName` and `$backupHandler` up to `parent::__construct()`, so the
`86400` lifetime and `Lax` same-site defaults cannot be changed through any of them. Only a
bare `new Sessions(...)` accepts them.
:::

:::note[Four of these five are described rather than exercised]
The four sections below are described from the source and from the client libraries'
documented behaviour; the only thing shown as run against them is the constructor failure
above. A claim about what a store then does with what it was handed - that redis records
expire on their own, that libketama hashes keys consistently across a memcached server list -
is the documented meaning of the call the class makes rather than a result seen here.

The Postgres section is different: its shipped schema and its garbage collection statement
were run against a real postgres, and that capture is shown. The handler driving through
php's session machinery was not.
:::

### APCu

`apcu_store()`, `apcu_fetch()` and `apcu_delete()` against `id()`, with `$this->expire` as
the TTL. It does not override `gc()`, so expiry is APCu's alone. `write()` and `destroy()`
each contain an `if (!empty($this->db_link))` branch, but `db_link` is not a property of
`Sessions` - it is private to `SessionsPgsql` - so the branch never runs. It is dead code,
and harmless because the line it guards is the same `parent::` call made immediately after.

### Memcached

`Memcached::set()`, `get()` and `delete()` against `id()`, again with `$this->expire`.
`OPT_LIBKETAMA_COMPATIBLE` is set and the server list is only populated when it is empty.
Takes the `[host, port]` pair array directly rather than a config section. No `gc()`
override.

### MongoDB

Stores documents in the `php_sessions` collection of the named database, keyed on `id` plus
`salt`, upserted on write. It is the only backend besides Postgres that overrides `gc()`,
deleting everything whose `timestamp` field is older than `time() - $maxLifetime`.
`destroy()` matches on `id` alone.

### Redis

Connects in the constructor from a config array with `hostname`, `port` and `database`, all
three optional - they fall back to `127.0.0.1`, `6379` and `1`, and that last is not the `2`
the cache backend defaults to - and sets
`Redis::OPT_SERIALIZER` to `SERIALIZER_PHP`. `write()` passes `$this->expire` as the third
argument to `Redis::set()`, so records expire on their own. No `gc()` override.

### Postgres

The only backend that goes through the framework's own database layer. The constructor calls
`Db::init($this->dbConfigName, $dbConfig)` with `$dbConfigName = 'sessions'`, so it opens a
connection under that name rather than reusing `default` - see
[the database layer](/staticphp-core/database/db/). Pass it a `$config['db']['pdo']` style
array.

Four statements, all against a table literally named `sessions`, which is not configurable:

```sql
SELECT data FROM sessions WHERE id = ? AND salt = ?
UPDATE sessions SET data = ?, timestamp = CURRENT_TIMESTAMP WHERE id = ? AND salt = ?
INSERT INTO sessions (id, salt, data) VALUES (?, ?, ?)
DELETE FROM "sessions" WHERE "id" = ?
```

`write()` tries the `UPDATE` first and falls back to the `INSERT` when it affected no rows.
`destroy()` matches on `id` alone, while `read()` and `write()` also match on `salt` - so a
session whose `User-Agent` changed reads as empty but is destroyed correctly.

`gc()` runs

```sql
DELETE FROM sessions WHERE timestamp <= CURRENT_TIMESTAMP - INTERVAL '{$maxLifetime}' SECOND
```

with `$maxLifetime` interpolated, not bound. It comes from php's `session.gc_maxlifetime`
rather than from user input, so it is not an injection route, but it is the one statement
here that concatenates.

#### The table

`src/Utils/Files/table_sessions_postgres.sql`:

```sql
CREATE TABLE public.sessions (
    id varchar(120) NOT NULL,
    salt varchar(40) NOT NULL DEFAULT '',
    "timestamp" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "data" text NOT NULL DEFAULT '',
    CONSTRAINT sessions_pk PRIMARY KEY (id)
);
CREATE INDEX sessions_timestamp_idx ON public.sessions ("timestamp");
```

That schema and the `gc()` statement, run against postgres 18:

```text
CREATE TABLE
CREATE INDEX
INSERT 0 1
INSERT 0 1
 gc_interval 
-------------
 01:00:00
(1 row)

DELETE 1
  id   
-------
 fresh
(1 row)
```

`INTERVAL '3600' SECOND` is the sql-standard qualifier form and postgres accepts it, so the
garbage collection query does work despite looking like MySQL syntax. The stale row went and
the fresh one stayed.

The package also ships `src/Utils/Files/table_sessions_mysql.sql`, with `timestamp` as an
`int(11)` and `data` as a `blob`. There is no MySQL session handler in `src/` for it to
belong to - `SessionsPgsql` writes `CURRENT_TIMESTAMP` into `timestamp`, which does not fit
that column. Treat the MySQL file as a leftover.
