---
title: Sorting
description: The Sort object, the default column requirement, and what SortNulls is for.
sidebar:
    order: 4
---

`Sort` holds exactly one piece of state: which column the table is currently sorted by, and
in which direction. There is no multi-column sort - the parser takes the first key it
recognises and stops.

## The interface

```php
<?php

interface SortInterface
{
    public function __construct(TableInterface &$tableInstance, string $urlPrefix = '', ?string $sortData = null);

    public function url(): string;
    public function setUrl(?string $setUrl = null): void;

    public function currentColumn(): ?ColumnInterface;
    public function currentDirection(): SortDirection;

    public function parse(string $sortData): void;

    public function sortData(): string;

    public function sortBy(): string;
    public function sortDirection(): SortDirection;
}
```

`Sort` implements it and adds nothing public beyond it.

## Every table needs a default sort column

The constructor scans the columns for the first one with `$sortDefaultColumn: true`, makes
it both the default and the current column, takes its `$sortDefaultDirection` as the current
direction, and throws if there is none:

```php
<?php

foreach ($this->tableInstance->columns as $column) {
    if ($column->sortDefaultColumn === true) {
        $this->defaultColumn = &$column;
        $this->currentColumn = &$column;
        $this->currentDirection = $this->currentColumn->sortDefaultDirection;
        break;
    }
}

if (empty($this->defaultColumn)) {
    throw new \Exception('No default column was found');
}
```

```text
Exception: No default column was found
```

Note the `break`. The requirement is *at least* one such column, not exactly one: a second
column with `$sortDefaultColumn: true` is silently ignored, and it is the first in
declaration order that wins.

This runs before the sort string is parsed, so the check is unconditional: a table that
passes anything other than `null` as the second argument of `initData()` must have a
default sort column. It fires from `initData()`, well before any output generator is
involved.

## The wire format

```text
name=desc
```

`parse()` splits the string with `Table::parseQueryString($sortData, ';')` and walks the
pairs, taking the first key that matches a column id:

```php
<?php

foreach ($sort as $key => $value) {
    if (isset($this->tableInstance->columns[$key])) {
        $this->currentColumn = &$this->tableInstance->columns[$key];
        $this->currentDirection = (strtolower($value) == 'desc' ? SortDirection::DESC : SortDirection::ASC);
        break;
    }
}
```

Only the exact string `desc`, case-insensitively, means descending; everything else is
ascending. An unrecognised key leaves the default column in place. An empty string leaves
`parse()` unentered - the constructor guards it with `if (!empty($sortData))`.

:::caution[The docblock is wrong about the separator]
The docblock above `parse()` says the format is
`"[field]=[direction],[field]=[direction],.., e.g. name=asc,created=desc"`. The code passes
`;` to `parseQueryString()`, not `,`, and it stops at the first match, so only one field is
ever honoured. Comma-separated input parses as a single pair whose value is
`asc,created=desc`, which is not `desc` and therefore sorts ascending.
:::

Four sort strings against a table whose columns are `name` (the default, `SortDirection::ASC`)
and `age`:

```text
sortData ''             currentColumn name  currentDirection 'ASC'  sortBy 'u.name' sortDirection 'ASC'
sortData 'age=desc'     currentColumn age   currentDirection 'DESC' sortBy 'u.age' sortDirection 'DESC'
sortData 'age=asc'      currentColumn age   currentDirection 'ASC'  sortBy 'u.age' sortDirection 'ASC'
sortData 'unknown=desc' currentColumn name  currentDirection 'ASC'  sortBy 'u.name' sortDirection 'ASC'
```

## Reading the state back

| Method | Returns |
| --- | --- |
| `sortData()` | The raw string that was parsed, `''` when nothing was |
| `currentColumn()` | The active `Column` object |
| `currentDirection()` | The active `SortDirection`, as chosen by the user |
| `sortBy()` | The database expression to order by |
| `sortDirection()` | The direction to put in the query, which is not always the same thing |

`sortBy()` returns the current column's `$sortBy`, falling back to the column **id** when
that property was never set - `return $column->sortBy ?? $column->id;`. So a column with no
`$sortBy` orders by a bare identifier of its own name, which is right when the query selects
that column unaliased and wrong the moment it does not. Set `$sortBy` explicitly on anything
you sort on.

The method is declared `: string` and starts by resolving the current column or throwing:

```php
<?php

$column = $this->currentColumn
    ?? throw new \LogicException('Sort has no current column');
```

The constructor refuses to build a `Sort` with no default column, so within the framework's
own path that `LogicException` is unreachable; it is there for a `Sort` whose state was
tampered with afterwards.

The returned value is used unescaped in an `ORDER BY`, so it must be a literal you wrote,
never anything derived from the request. The id fallback is covered by that too: a column id
built from a request parameter reaches the `ORDER BY` the same way a `$sortBy` would.

### A closure for sortBy

When `$sortBy` is callable it is invoked with the current `SortDirection` and must return the
whole expression, direction included:

```php
<?php

use StaticPHP\Presentation\Models\Tables\Enums\SortDirection;

new Column(
    'name',
    title: 'Name',
    dataKey: 'name',
    sortDefaultColumn: true,
    sortBy: fn(SortDirection $direction) => "lower(u.name) {$direction->value}, u.id ASC",
);
```

Because the expression now carries its own direction, `sortDirection()` returns
`SortDirection::NONE` for a callable `$sortBy` so the SQL builder does not append a second
one:

```text
sort->sortBy()       -> 'lower(u.name) DESC, u.id ASC'
sort->sortDirection() -> SortDirection::NONE
sqlSort->sortQuery() -> ORDER BY lower(u.name) DESC, u.id ASC  NULLS LAST 
```

The double space in the generated clause is `NONE`'s empty value being interpolated. It is
harmless, but note that the `NULLS LAST` suffix is still appended after your expression, and
in PostgreSQL that binds to the *last* ordering term.

## The url

`url()` returns the prefix verbatim - unlike `Filters::url()` it does not append the
placeholder if it is missing. `initData()` supplies
`"{$urlPrefix}{$filterData}/%sort"`, so for `/users/` with the filter `name=an` that is
`/users/name=an/%sort`.

`Html::sortUrl()` is what turns that into a link. It picks the new direction - descending
only when the column is already the current column and already ascending - and replaces the
placeholder:

```php
<?php

$sort = $this->tableInstance->sort ?? throw new Exception('Sort is not initialized');
$current = $sort->currentColumn();

$newDirection = ($forColumn->id === $current?->id
    && $sort->currentDirection() === SortDirection::ASC
    ? 'desc' : 'asc'
);
$sortData = "{$forColumn->id}={$newDirection}";
$url = $sort->url();
$url = str_replace('%sort', $sortData, $url);
```

So clicking a column that is not the current one always sorts ascending first, and clicking
the current descending column returns it to ascending rather than clearing the sort.

## SortDirection

`Enums/SortDirection.php`, a backed string enum with **3** cases:

| Case | Value | Meaning |
| --- | --- | --- |
| `NONE` | `''` | The expression carries its own direction |
| `ASC` | `ASC` | Ascending |
| `DESC` | `DESC` | Descending |

The values are the SQL keywords, uppercase, and `SQLSort` interpolates
`sortDirection()->value` straight into the `ORDER BY`. `NONE` exists only for the callable
`$sortBy` case above; nothing sets it as a column default.

## SortNulls

`Enums/SortNulls.php`, a backed string enum with **3** cases:

| Case | Value | Emitted |
| --- | --- | --- |
| `NONE` | `NONE` | Nothing - null placement is left to the database |
| `FIRST` | `FIRST` | `NULLS FIRST` |
| `LAST` | `LAST` | `NULLS LAST` |

This one is worth stopping on, because it is the only part of sorting that has no
counterpart in the url or in the user interface. It is a per-column property,
`$sortNulls`, defaulting to `SortNulls::LAST`, and it decides where rows whose sort value is
`NULL` end up.

It matters because the default placement of nulls flips with the direction. PostgreSQL
treats `NULL` as larger than any value, so an unqualified `ASC` puts nulls last and `DESC`
puts them first; other engines pick their own convention. Pinning it per column means a
nullable column sorts the same way whichever direction the user clicks, which is almost
always what is wanted from a "sort by due date" that has undated rows in it.

`SQLSort` is the only reader:

```php
<?php

private function sort(): Sort
{
    return $this->tableInstance->sort
        ?? throw new \LogicException('SQLSort needs a table whose sorting was initialised');
}

public function sortNulls(): SortNulls
{
    $column = $this->sort()->currentColumn();
    return $column->sortNulls ?? SortNulls::FIRST;
}

public function sortQuery(): string
{
    $column = $this->sort()->sortBy();
    $direction = $this->sort()->sortDirection()->value;
    $nulls = match ($this->sortNulls()) {
        SortNulls::NONE => '',
        SortNulls::FIRST => 'NULLS FIRST',
        SortNulls::LAST => 'NULLS LAST',
    };

    return " ORDER BY {$column} {$direction} {$nulls} ";
}
```

Both public methods reach the table's `Sort` through that private accessor, so calling
either on an `SQLSort` whose table never had `initData()` run is a `LogicException` with a
message that names the cause rather than a null property access.

### An explicit example

Two columns, differing only in `$sortNulls`. `age` is nullable and we want the rows with no
age at the top whichever way the column is sorted; `name` keeps the default:

```php
<?php

use StaticPHP\Presentation\Models\Tables\Column;
use StaticPHP\Presentation\Models\Tables\Enums\ColumnType;
use StaticPHP\Presentation\Models\Tables\Enums\SortNulls;

$columns = [
    new Column(
        'name',
        title: 'Name',
        type: ColumnType::TEXT,
        dataKey: 'name',
        sortBy: 'u.name',
        filterBy: 'u.name',
        sortDefaultColumn: true,
    ),
    new Column(
        'age',
        title: 'Age',
        type: ColumnType::INT,
        dataKey: 'age',
        sortBy: 'u.age',
        filterBy: 'u.age',
        sortNulls: SortNulls::FIRST,
    ),
];
```

Sorting by `age=desc` and then by `name=asc` produces:

```text
column 'age' sortNulls = SortNulls::FIRST
sqlSort->sortNulls()  -> SortNulls::FIRST
sqlSort->sortQuery()  -> ORDER BY u.age DESC NULLS FIRST 

column 'name' sortNulls = SortNulls::LAST (the default)
sqlSort->sortNulls()  -> SortNulls::LAST
sqlSort->sortQuery()  -> ORDER BY u.name ASC NULLS LAST 
```

The nulls clause follows the column, not the direction: `age` keeps `NULLS FIRST` whether it
is sorted up or down.

Three caveats:

- The `?? SortNulls::FIRST` fallback in `sortNulls()` is unreachable. `$sortNulls` is a
  non-nullable typed property with a default, so it is never `null`. The effective default
  is the property's - `SortNulls::LAST`.
- `NULLS FIRST` / `NULLS LAST` is standard SQL that not every engine implements. PostgreSQL
  accepts it, and so does the SQLite 3.46 build these docs were checked against. MySQL and
  MariaDB do not - there the clause is a syntax error rather than a hint they ignore.
- `SortNulls::NONE` is the way to switch it off. Its `match` arm emits the empty string, so
  the clause disappears and null placement is left to the database. Set it on every column
  of a table you sort on mysql or mariadb; the property default is `LAST`, so leaving a
  column alone is what produces the unusable clause.
