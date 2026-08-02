---
title: Diffing
description: How Diff decides what changed, why the comparison is not strict, and what redaction leaves behind.
sidebar:
    order: 3
---

`StaticPHP\Utils\Models\Audit\Diff` works out what actually changed between two rows. It is
pure - no database, no configuration, no state - so the comparison rules, which are the fiddly
part, are tested without a database anywhere near them.

```php
<?php

namespace StaticPHP\Utils\Models\Audit;

class Diff
{
    public const REDACTED = '***';

    public static function between(?array $before, ?array $after, array $exclude = []): array;
    public static function mask(array $values, array $exclude): array;
    public static function same(mixed $a, mixed $b): bool;
}
```

`between()` returns a two-element list, `[$oldValues, $newValues]`, which is what goes into
the `old_values` and `new_values` columns.
[`Audit::diff()`](/staticphp-core/audit/recording/#diff) is a passthrough to it.

## The three shapes

| `$before` | `$after` | Meaning | Result |
| --- | --- | --- | --- |
| `null` | an array | an insert | `[null, the whole row]` |
| an array | `null` | a delete | `[the whole row, null]` |
| an array | an array | an update | only what differs, or `[null, null]` |

`$before` and `$after` both `null` is the degenerate case: there is nothing to compare, and the
result is `[null, null]` too.

Every case below was produced by calling the code:

```text
Diff::between(null, ['name' => 'Anna', 'active' => true])
  old: null
  new: {"name":"Anna","active":true}
Diff::between(['id' => 5, 'name' => 'Anna'], null)
  old: {"id":5,"name":"Anna"}
  new: null
Diff::between(['name' => 'Anna', 'city' => 'Dobele'], ['name' => 'Anna Berzina', 'city' => 'Dobele'])
  old: {"name":"Anna"}
  new: {"name":"Anna Berzina"}
Diff::between(['id' => 5, 'name' => 'Anna', 'notes' => 'x'], ['name' => 'Anna Berzina'])
  old: {"name":"Anna"}
  new: {"name":"Anna Berzina"}
Diff::between(['name' => 'Anna'], ['approved_at' => '2026-08-02'])
  old: {"approved_at":null}
  new: {"approved_at":"2026-08-02"}
Diff::between(['note' => null], ['note' => ''])
  old: {"note":null}
  new: {"note":""}
Diff::between(['qty' => '42'], ['qty' => 42])
  old: null
  new: null
Diff::between(['active' => 't'], ['active' => true])
  old: null
  new: null
Diff::between(['active' => 'f'], ['active' => true])
  old: {"active":"f"}
  new: {"active":true}
Diff::between(['answer' => 'true'], ['answer' => '1'])
  old: {"answer":"true"}
  new: {"answer":"1"}
Diff::between(['meta' => ['a' => 1]], ['meta' => ['a' => 2]])
  old: {"meta":{"a":1}}
  new: {"meta":{"a":2}}
Diff::between(['counter' => 4], ['!counter' => 'counter + 1'])
  old: {"counter":4}
  new: {"counter":"counter + 1"}
Diff::between(['name' => 'Anna', 'password' => 'old'], ['name' => 'Anna', 'password' => 'new'], ['password'])
  old: {"password":"***"}
  new: {"password":"***"}
Diff::between(['password' => 'same'], ['password' => 'same'], ['password'])
  old: null
  new: null
Diff::between(null, null)
  old: null
  new: null
```

## Only what `$after` mentions

For an update, **only keys present in `$after` are considered.** That is the rule that keeps
the log readable: [`Db::update()`](/staticphp-core/database/db/#update) is routinely handed
three columns against a row that has thirty, and the twenty-seven it does not mention have
not changed. Reporting them as changes to `null` is how an audit log becomes something nobody
opens.

The mirror of that rule is the case a naive implementation drops. A key in `$after` with no
counterpart in `$before` is recorded as a change **from `null`**, which is what
`array_diff_assoc()` silently loses - it returns entries of the old array, so a column that
was never selected, or was null and got a value, never appears at all:

```text
Diff::between(['name' => 'Anna'], ['approved_at' => '2026-08-02'])
  old: {"approved_at":null}
  new: {"approved_at":"2026-08-02"}
```

`null` and the empty string are different values and both are kept as they are.

When nothing survives the comparison, `between()` returns `[null, null]` rather than a pair
of empty arrays, and `Audit::update()` reads that as "record nothing".

## Why the comparison is not strict

`same()` compares **string forms**, not identity. PDO hands most column types back as
strings, so the integer `1` an application writes and the `"1"` that comes back out are the
same stored value; a strict comparison would report every untouched column as changed on
every update.

`null` is special-cased first: it is equal only to `null`. Non-scalars are compared through
`json_encode()`, so two arrays with the same content are the same value. Every line below is
a real call:

```text
Diff::same('42', 42)                  -> true
Diff::same('10.5', 10.5)              -> true
Diff::same(null, '')                  -> false
Diff::same(null, null)                -> true
Diff::same(['a' => 1], ['a' => 1])    -> true
Diff::same('t', true)                 -> true
Diff::same('TRUE', true)              -> true
Diff::same('', false)                 -> true
Diff::same('no', false)               -> true
Diff::same('yes', true)               -> true
Diff::same('f', true)                 -> false
Diff::same('true', '1')               -> false
Diff::same('maybe', true)             -> false
```

### Booleans

Booleans get their own rule, and they need one.
[`Db::insert()`](/staticphp-core/database/db/#insert) and `Db::update()` send php booleans as
the literals `'true'` and `'false'`, and the drivers hand them back as `t`/`f`, `1`/`0`, `''`
or a php bool depending on driver and version. Without normalisation an untouched flag reads
as changed on every single update.

`boolish()` maps the whole family to `"1"` or `"0"`:

| Reads as `1` | Reads as `0` |
| --- | --- |
| `true`, `1`, `'1'`, `'t'`, `'true'`, `'y'`, `'yes'` | `false`, `0`, `'0'`, `'f'`, `'false'`, `'n'`, `'no'`, `''` |

Matching is case-insensitive, and anything unrecognised is returned as it is, so it can still
fail the comparison.

The rule only fires **when at least one side really is a php bool**. Two strings stay a string
comparison, so a text column holding the literal `true` is never quietly equal to `1`:

```text
Diff::between(['answer' => 'true'], ['answer' => '1'])
  old: {"answer":"true"}
  new: {"answer":"1"}
```

That is the trade-off the rule exists to avoid, and it is why blanket normalisation would be
wrong.

## Raw sql values

`Db::insert()` and `Db::update()` treat a leading `!` on a key as "this value is raw sql". The
column name is what belongs in the log, so the `!` is stripped, and the expression itself is
recorded as the new value:

```text
Diff::between(['counter' => 4], ['!counter' => 'counter + 1'])
  old: {"counter":4}
  new: {"counter":"counter + 1"}
```

The result of `counter + 1` is not knowable without another select, and the expression is a
more honest record of a counter bump than the `null` there would otherwise be.

## Redaction

`$exclude` is a list of column names whose **values** must never reach the trail. The key
stays, so the log still shows that a password changed, with both sides replaced by
`Diff::REDACTED` - the string `***`:

```text
Diff::between(['name' => 'Anna', 'password' => 'old'], ['name' => 'Anna', 'password' => 'new'], ['password'])
  old: {"password":"***"}
  new: {"password":"***"}
```

An excluded column that did **not** change is not recorded at all - the comparison happens
first, and only a real change is masked:

```text
Diff::between(['password' => 'same'], ['password' => 'same'], ['password'])
  old: null
  new: null
```

For an insert or a delete there is nothing to compare, so `mask()` walks the whole row and
replaces the excluded keys wherever they appear. `mask()` is public and returns the array
unchanged when `$exclude` is empty.

The list comes from `$config['audit']['exclude']`, keyed by table:

```php
<?php

$config['audit']['exclude'] = [
    'users' => ['password', 'remember_token'],
];
```

`Audit` looks the table up on every call and quietly returns an empty list for a table with no
entry, a value that is not an array, or entries that are not strings. Nothing warns about a
misspelled table name here, so a typo is a silent leak of the values it was meant to redact -
worth a test in the application.

## What this does not do

`Diff` compares one flat row against another. It has no notion of nested structure: a json
column whose contents changed is recorded as the whole old document and the whole new one,
because `same()` only asks whether the two encode identically. Recording a path-level diff
inside a document is left to the application, which can compute it and hand the result to
[`Audit::record()`](/staticphp-core/audit/recording/#record-and-auditevent).
