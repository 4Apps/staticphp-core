---
title: Cache
description: The cache interface, the four backends behind it, and what each one needs installed.
sidebar:
    order: 1
---

`StaticPHP\Utils\Models\Cache\Cache` is two things at once: a base class the four backends
extend, and a static registry that fans a key out across whichever of them an application
registered.

[Configuration](/staticphp-core/getting-started/configuration/#cache) covers the shape of
`$config['cache']`. This page is about what the classes do with it.

## The interface

```php
<?php

namespace StaticPHP\Utils\Models\Cache;

interface CacheInterface
{
    public function __construct(?array $config = null);
    public function prefix(string $key): string;
    public function setValue(string $key, mixed $value, ?int $ttl = null): bool;
    public function getValue(string $key): mixed;
    public function removeKey(string $key): bool;
}
```

Five members, and that is the whole contract. `Cache` implements it with `prefix()` doing
real work - it prepends `$config['prefix']` when that key is non-empty, and returns the key
unchanged otherwise - while `setValue()`, `getValue()` and `removeKey()` throw
`Exception('Not implemented')` so that a backend which forgets one fails loudly.

`Cache` also declares two methods that are **not** on the interface:

```php
<?php

public function doesItemExist(string $key): bool;
public function getTTL(string $key): int;
```

Both throw `Not implemented` on the base class, and `CacheRedis` is the only backend that
overrides them. Everything routed through them is therefore redis-only in practice.

## Nothing selects a backend for you

`src/Utils/Config/Cache.php` is a plain config file. It assigns four sections under
`$config['cache']` - `redis`, `memcached`, `apcu` and `files` - and that is all it does.
There is no driver key, no factory, and nothing anywhere in `src/` reads `$config['cache']`.
The application chooses by naming a class:

```php
<?php

use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Cache\CacheFiles;

$cache = new CacheFiles(Config::get('cache')['files']);

$cache->setValue('user:7', ['id' => 7, 'name' => 'Anna'], 300);
$value = $cache->getValue('user:7');
```

To load the shipped defaults in the first place, add
`$config['autoload_configs'][] = 'staticphp/Utils/Cache';` to the application's own
`Config.php`, or copy the file into `APP_PATH/Config/` and edit it there.

## The registry

Register one or more constructed backends under a name and the static half of `Cache` takes
over:

```php
<?php

use StaticPHP\Utils\Models\Cache\Cache;

public static function register(CacheInterface $cacheBackend, string $name): void;

public static function set(string $key, mixed $value, ?int $ttl = null, ?string $name = null): bool;
public static function get(string $key, ?string $name = null): mixed;
public static function remove(string $key, ?string $name = null): bool;
public static function exists(string $key, ?string $name = null): bool;
public static function getTimeToLive(string $key, ?string $name = null): int;
```

Without a `$name`, `set()` and `remove()` write to **every** registered backend, while
`get()` and `getTimeToLive()` read from the **first** one - `reset()` on the backend array,
so registration order decides. `exists()` returns true as soon as any backend says yes.
With a `$name`, every call goes to that backend alone. Registering the same name twice
throws, and so does naming a backend that was never registered.

Do not read a fan-out return value as "it worked everywhere". `set()` discards what each
backend returned and hands back a hardcoded `true`; `remove()` keeps only the **last**
backend's status and returns that. A single-backend call - one with a `$name` - does return
that backend's own result.

Captured against one registered `CacheFiles` backend:

```text
Cache::set('k', ['v' => 1])        => true
Cache::get('k')                    => array (
                                        'v' => 1,
                                      )
Cache::get('k', 'files')           => array (
                                        'v' => 1,
                                      )
Cache::remove('k')                 => true
Cache::register($files, 'files')   => Exception: Cache backend already registerd by "files"
Cache::get('k', 'no-such-backend') => Exception: Backend does not exist
Cache::exists('k')                 => Exception: Not implemented
Cache::getTimeToLive('k')          => Exception: Not implemented
```

`exists()` and `getTimeToLive()` fail there because the file backend does not implement
`doesItemExist()` or `getTTL()`, not because the key is missing.

:::caution[The registry misbehaves when it is empty]
With no backend registered, `Cache::get()` and `Cache::getTimeToLive()` call a method on
`false` - `reset([])` returns `false` - and `Cache::remove()` returns `$status`, a variable
its loop never assigned, which is a `TypeError` against the `: bool` return type. Only
`set()` and `exists()` degrade quietly. Register a backend before using the static methods.
:::

## The four backends

| Backend   | Class            | Needs installed                         | Config section                  |
| --------- | ---------------- | --------------------------------------- | ------------------------------- |
| Files     | `CacheFiles`     | nothing - a writable directory          | `$config['cache']['files']`     |
| APCu      | `CacheApcu`      | `ext-apcu`                              | `$config['cache']['apcu']`      |
| Memcached | `CacheMemcached` | `ext-memcached` (the `Memcached` class) | `$config['cache']['memcached']` |
| Redis     | `CacheRedis`     | `ext-redis` (the `Redis` class)         | `$config['cache']['redis']`     |

All three extensions are listed in `composer.json` under `suggest`, each entry naming the
cache and session classes that need it, and none of them under `require`. `suggest` is
advisory - composer prints it and installs nothing - so composer will install the package on
a host that has none of them, and the failure surfaces the moment you construct the backend.
In a container with only the framework's own required extensions:

```text
new CacheRedis($config['cache']['redis'])            => Error: Class "Redis" not found
new CacheMemcached($config['cache']['memcached'])    => Error: Class "Memcached" not found
(new CacheApcu($config['cache']['apcu']))->getValue('k') => Error: Call to undefined function StaticPHP\Utils\Models\Cache\apcu_fetch()
```

APCu is the odd one out: `CacheApcu` has no constructor, so it builds fine and fails on
first use instead.

### Files

Reads `prefix`, `path`, `ext`, `levels` and `sub_path_length`. The constructor defaults
`ext` to `cache`, `levels` to `0` and `sub_path_length` to `2` when the key is empty, and
creates `path` with mode `0770` if it does not exist. `path` itself has no default and is
read unconditionally, so it must be set.

A key is hashed with `md5()` first, and the hash is then split into `levels` directories of
`sub_path_length` characters each, taken from the **end** of the hash inwards. The prefix is
applied to the hash rather than to the original key:

```text
$c->prefix('user:7')               => 'app_user:7'
$c->setValue('user:7', ['id' => 7]) => true
$c->getValue('user:7')             => array (
                                        'id' => 7,
                                      )
$c->setValue('hits', 42)           => true
$c->getValue('hits')               => 42
$c->getValue('never-written')      => false
$c->setValue('o', new stdClass())  => Exception: Data type is not yet supported
setValue('short', 'x', 1); sleep 2 => 'x'
$c->removeKey('user:7')            => true
$c->removeKey('user:7') again      => false
$c->doesItemExist('hits')          => Exception: Not implemented
$c->getTTL('hits')                 => Exception: Not implemented

  md5('hits') = 46ab2997ca006d78b35db8f6f9de7662
  files on disk under $config['cache']['files']['path']:
    /62/76/app_46ab2997ca006d78b35db8f6f9de7662.cache
    /be/ba/app_4f09daa9d95bcb166a302407a0e0babe.cache
```

Two things to know before choosing this backend:

-   **`$ttl` is accepted and ignored.** `setValue()` never writes an expiry and `getValue()`
    never checks one, so a cached value lives until something deletes the file. Nothing in
    the framework sweeps the directory.
-   **Only some types round-trip.** Arrays are `json_encode`d as they are; `bool`, numeric
    and `string` values are wrapped as `{"cacher___encoded": ...}` and unwrapped on read.
    Anything else - an object, `null` - throws
    `Exception('Data type is not yet supported')`. Because arrays go through json, an array
    of objects comes back as an array of arrays.

A miss returns `false`, which is indistinguishable from a cached `false`.

:::note[Described rather than exercised]
The file backend above is the one whose behaviour is shown as observed. The three sections
below are described from the source and from the client libraries' documented behaviour, so
a statement about what a store does with what it was handed is the documented meaning of the
call the class makes rather than a result seen here.
:::

### APCu

Reads `prefix` only, and maps straight onto `apcu_store()`, `apcu_fetch()` and
`apcu_delete()`. A `null` `$ttl` becomes `0`, which is APCu's "no expiry". The cache lives
in the php process pool, so it does not survive a restart and is not shared between servers.

### Memcached

Reads `prefix`, `servers`, `persistent_id` and `timeout`. `servers` is the array of
`[host, port]` pairs `Memcached::addServers()` expects. `timeout` is in **seconds** and is
multiplied by 1000 before being used three ways: the `memcached.default_connect_timeout` ini
setting, `Memcached::OPT_RECV_TIMEOUT` and `Memcached::OPT_SEND_TIMEOUT`.
`Memcached::OPT_LIBKETAMA_COMPATIBLE` is always set, so keys hash consistently across the
server list. Servers are only added when the existing server list is empty, which is what
makes `persistent_id` worth setting - a persistent connection keeps its server list between
requests.

### Redis

Reads `prefix`, `hostname`, `port`, `timeout` and `database`. The constructor connects
eagerly, sets `Redis::OPT_SERIALIZER` to `Redis::SERIALIZER_PHP` - so the extension
serializes and unserializes values on the way through, and structured values round-trip -
and selects `database`, defaulting to `2` when the key is absent. `timeout` is read
unconditionally and passed as the connect timeout; `0` means no limit.

`setValue()` issues a `SET` and then a separate `EXPIRE` when `$ttl` is not null, so a value
briefly exists without an expiry. `removeKey()` returns the `DEL` reply count coerced to
`bool`. This is also the only backend implementing `doesItemExist()` (`EXISTS`) and
`getTTL()` (`TTL`).
