---
title: RecordObject
description: A wrapper around one database row that exposes it as an object, an array and an iterator at once.
sidebar:
    order: 2
---

`StaticPHP\Utils\Models\RecordObject` wraps a single result row so it can be read as an
object, as an array and as an iterator, and encoded to json directly. It implements
`Iterator`, `JsonSerializable` and `ArrayAccess`.

Two things to get clear before the API, because neither is what the name suggests:

- **Nothing in the framework returns one.** `Db` hands back plain arrays or `stdClass`
  objects, exactly as PDO produced them. A `RecordObject` only exists because application
  code constructed one.
- **It is not a persistence layer.** There is no connection inside it, no table name, and
  no query. `save()` does not write to a database - see below.

The source file opens with `// TODO: Needs revision`, which is fair warning: several of the
behaviours below are sharp edges rather than design.

## Constructing one

```php
<?php

public function __construct($record, $skip_format = false);
```

`$record` is expected to be an associative array - a row fetched with the default
`fetch_mode_objects => false`. Nothing enforces it, but a `stdClass` breaks at the first
array operation, so wrap the array form.

Unless `$skip_format` is true the constructor runs `format()` immediately, then copies the
resulting record into an "original" copy that is never touched again except by `save()` and
`reload()`.

```php
<?php

use StaticPHP\Utils\Models\Db;
use StaticPHP\Utils\Models\RecordObject;

$post = new RecordObject(Db::fetch('SELECT * FROM posts WHERE id = ?', [$id]));
```

## The two records

Internally there are three arrays: `record`, `formatted_record` and `original_record`.

`format()` is the only thing that fills `formatted_record`. It walks `record` looking for
keys containing `additional_fields_`, moves each one across under the key with that
substring removed, unsets it from `record`, and `json_decode($value, true)`s the value when
it is not empty.

```php
<?php

$row = [
    'id' => 7,
    'title' => 'Hello',
    'additional_fields_meta' => '{"a":1,"b":[2,3]}',
];

$record = new RecordObject($row);

$record->record();       // ['id' => 7, 'title' => 'Hello']
$record->meta;           // ['a' => 1, 'b' => [2, 3]]
json_encode($record);    // {"id":7,"title":"Hello","meta":{"a":1,"b":[2,3]}}
```

The convention is a database one: a query that aggregates related rows into a json column
aliases it `additional_fields_something`, and the wrapper turns it back into a php array on
the way out.

Details that matter:

- The test is `strpos($key, 'additional_fields_') !== false`, not a prefix check, and the
  removal is `str_replace()`. A column named `x_additional_fields_y` therefore becomes
  `x_y`.
- A value that is not valid json decodes to `null`. An empty string is skipped by the
  `!empty()` guard and stays an empty string.
- If `record` already holds a key that a formatted field would occupy, the formatted value
  becomes unreachable: reads, `jsonSerialize()` and iteration all prefer `record`.
- `format()` is safe to call twice; the second pass finds nothing left to move.

With `$skip_format` true, none of that happens and `additional_fields_meta` stays in
`record` as a raw json string.

## Reading

```php
<?php

public function __get(string $name);
public function get(string $name, ?int $from = null);
public function offsetGet(mixed $offset): mixed;
public function record();
public function originalRecord();
```

`__get()` and `offsetGet()` look in `record` first, then `formatted_record`. `__get()` falls
through to `$this[$name]`, so both end in the same place - and that place **throws** an
`\Exception` reading `"name" not found.` rather than returning null.

The lookups use `isset()`. A column whose value is `null` is therefore indistinguishable
from a missing one:

```php
<?php

$record = new RecordObject(['id' => 7, 'deleted_at' => null]);

$record->record();            // ['id' => 7, 'deleted_at' => null]
isset($record['deleted_at']); // false
$record->deleted_at;          // throws: "deleted_at" not found.
```

Nullable columns are common, so this is the trap to remember. Read them through `record()`,
or guard with `array_key_exists()` on `record()`.

`get()` is the non-throwing form: it returns `false` when the key is not found. Its `$from`
argument selects which array to look in, using the two class constants:

```php
<?php

public const DATA_RECORD = 0;
public const DATA_FORMATTED_RECORD = 1;

$record->get('title');                                   // from record
$record->get('meta', RecordObject::DATA_FORMATTED_RECORD); // from formatted_record
$record->get('meta', RecordObject::DATA_RECORD);         // false - meta lives in formatted_record
```

`null`, the default, means either. `false` is the sole failure signal, so it cannot be told
apart from a column that genuinely holds `false`.

`record()` and `originalRecord()` return the raw arrays, which is the way to see null
values and to iterate without the `isset()` semantics.

## Writing

```php
<?php

public function __set(string $name, mixed $value);
public function offsetSet(mixed $offset, mixed $value): void;
public function offsetUnset(mixed $offset): void;
```

The two setters do not behave the same way, and the difference is easy to trip over:

- `__set()` assigns **only if the key already exists** in `record` or `formatted_record`,
  again by `isset()`. Setting an unknown key is a silent no-op, and so is setting a key
  whose current value is null.
- `offsetSet()` always writes into `record`. A null `$offset` - the `$record[] = $value`
  form - throws `Adding empty array entries is not allowed`.

```php
<?php

$record->title = 'Changed';   // works, the key exists
$record->brand_new = 'x';     // silently does nothing
$record['brand_new'] = 'x';   // works
```

`offsetUnset()` removes the key from both arrays.

## save(), reload() and originalRecord()

```php
<?php

public function save();
public function reload();
```

Neither touches a database.

`save()` copies `record` over `original_record`, marking the current state as the accepted
one. `reload()` re-runs `format()` unless the object was built with `$skip_format`, then
does the same copy.

So `originalRecord()` answers "what did this row look like when it was loaded, or when it
was last accepted?" - it is a change-tracking aid for application code that then writes the
row back with `Db::update()` itself. Nothing in the class compares the two arrays for you.

## Iteration and json

`Iterator` is implemented over `record` alone, using php's internal array pointer
(`reset()`, `current()`, `key()`, `next()`). Formatted fields are not iterated:

```php
<?php

foreach ($record as $column => $value) {
    // id, title - not meta
}
```

`jsonSerialize()` returns `$this->record + $this->formatted_record`, so json output *does*
include the formatted fields, with `record` winning any key collision. `__toString()` is
`json_encode($this)`, which means casting the object to a string gives the same json.

`__debugInfo()` exposes `record` and `formatted_record`, so `var_dump()` and `print_r()`
show both arrays and hide `original_record`.
