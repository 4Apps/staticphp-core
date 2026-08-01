---
title: SQL tables
description: The database-backed table, and how filter values become a WHERE clause and bound parameters.
sidebar:
    order: 8
---

`src/Presentation/Models/Tables/SQL/` holds the database half of the table subsystem: one
subclass of `Table` and three small classes that turn its filter, sort and pagination state
into query fragments.

Nothing here opens a connection or runs a query. They return strings and parameter arrays;
handing them to [`Db`](/staticphp-core/database/db/) is the controller's job.

## SQLTable

`SQLTable extends Table` and adds three properties and an `initData()` override:

```php
<?php

class SQLTable extends Table
{
    public ?SQLSort $sqlSort = null;
    public ?SQLFilters $sqlFilter = null;
    public ?SQLPagination $sqlPagination = null;

    public function initData(?string $filterData = null, ?string $sortData = null, ?int $page = null): void
    {
        parent::initData($filterData, $sortData, $page);

        if ($filterData !== null) {
            $this->sqlFilter = new SQLFilters($this);
        }
        if ($sortData !== null) {
            $this->sqlSort = new SQLSort($this);
        }
        if ($page !== null) {
            $this->sqlPagination = new SQLPagination($this);
        }
    }
}
```

Same three-way `null` test as the parent, so `$sqlFilter` exists exactly when `$filter`
does. Everything else - columns, rows, output generator, the meta rows - is inherited
unchanged; see [tables](/staticphp-core/presentation/tables/).

All three helpers use the
[`TableInstance` trait](/staticphp-core/presentation/tables/#the-tableinstance-trait) and
implement `TableInstanceInterface`, not `OutputInterface`.

:::danger[Column expressions are concatenated, values are bound]
`$sortBy` and `$filterBy` are interpolated into SQL as written. Filter *values* are always
replaced by `?` placeholders and returned separately, so user input never reaches the
query text - but the column expressions themselves must be literals you wrote. Never build
one from a request parameter.
:::

## SQLFilters

The whole point of this class is `prepareQueries()`, which walks the parsed filter state and
fills three protected arrays. Everything else either feeds it or reads it back.

### Reading the result

| Method | Returns |
| --- | --- |
| `hasQuery(): bool` | Whether any query fragment was collected |
| `queries(): array` | The fragments, one per filtered column |
| `params(?string $key = null): ?array` | All bound parameters in order, or one column's |
| `querySql(string $prefix = 'WHERE'): string` | The fragments joined with ` AND `, prefixed |

`querySql()` returns `''` when there is nothing to filter on, so it concatenates safely onto
a query that has no `WHERE` of its own. Pass `'AND'` when the query already has one.

```php
<?php

$table->sqlFilter->prepareQueries();

$where = $table->sqlFilter->querySql();
$params = $table->sqlFilter->params();

$rows = Db::fetchAll("SELECT * FROM tickets u{$where}", $params);
```

With a `name` column filtered by `an` and a `tag` column whose `$filterBy` is a closure:

<!-- captured:sql-prepare -->
```text
hasQuery()      -> true
queries()       -> [
    "u.name::TEXT ILIKE ?",
    "u.id IN (SELECT ticket_id FROM tags WHERE name = ?)"
]
params()        -> ["%an%","bug"]
params('tag')   -> ["bug"]
querySql()      -> ' WHERE u.name::TEXT ILIKE ? AND u.id IN (SELECT ticket_id FROM tags WHERE name = ?)'
querySql('AND') -> ' AND u.name::TEXT ILIKE ? AND u.id IN (SELECT ticket_id FROM tags WHERE name = ?)'
filter->parsedData()['tag'] -> {"title":"BUG","value":"bug"}
```
<!-- /captured:sql-prepare -->

`params()` returns the parameters in the order the fragments were collected, which is the
order of `parsedData()`, which is the order of the filter string followed by any defaults.
That order matters for positional `?` binding, so use `querySql()` and `params()` from the
same object without reordering either.

### prepareQueries()

```php
<?php

public function prepareQueries(?\Closure $formatter = null)
```

For every entry in `$table->filter->parsedData()` whose key matches a column id:

1. If the column's `$filterBy` is **callable**, it is called with the value and must return
   the result array itself.
2. Otherwise, if `$filterBy` is a non-empty string, `runFilter()` builds the result from the
   column's `ColumnType`.
3. Otherwise, if a `$formatter` closure was passed to `prepareQueries()`, it is called with
   `($column, $value)`.
4. A column with no `$filterBy` and no formatter contributes nothing.

The result array has three keys, all optional:

| Key | Effect |
| --- | --- |
| `query` | Appended to `queries()` |
| `param` | Merged into `params()`, and stored under the column id for `params($key)` |
| `data` | Written back into the filter state with `Filters::setParsedData()` |

The `data` write-back is what lets a filter show one thing and query another - see the
`{"title":"BUG","value":"bug"}` entry in the capture above, which came from the closure
below. `Column::$filterData` overrides it: if that property is an array it is used
directly, and if it is a closure its non-null return value wins.

```php
<?php

new Column(
    'tag',
    title: 'Tag',
    dataKey: 'tag',
    sortBy: 'u.tag',
    filterBy: fn($value) => [
        'query' => 'u.id IN (SELECT ticket_id FROM tags WHERE name = ?)',
        'param' => [$value],
        'data' => ['title' => strtoupper($value), 'value' => $value],
    ],
);
```

`prepareQueries()` appends. Calling it twice doubles every fragment and every parameter;
call it once per request.

### valueToQuery()

The static workhorse. It reads an operator off the front of the value and returns
`[$query, $params]`:

```php
<?php

public static function valueToQuery(
    string $fieldName,
    $value,
    string $compare = '=',
    ?\Closure $valueFormatter = null,
    $nullQuery = false
): array
```

Every line below is captured output from calling it with `$fieldName` of `u.name`:

<!-- captured:filters-operators -->
```text
'ann'    -> u.name = ?   params: ["ann"]
'=ann'   -> u.name = ?   params: ["ann"]
'!ann'   -> u.name != ?   params: ["ann"]
'<50'    -> u.name <= ?   params: ["50"]
'>50'    -> u.name >= ?   params: ["50"]
'@1,2,3' -> u.name IN (?, ?, ?)   params: ["1","2","3"]
'^ann'   -> u.name::TEXT ILIKE ?   params: ["ann%"]
'$son'   -> u.name::TEXT ILIKE ?   params: ["%son"]
'%ann'   -> u.name::TEXT ILIKE ?   params: ["%ann%"]
'10~20'  -> u.name >= ? AND u.name <= ?   params: ["10","20"]
['admin', 'editor'] -> u.role IN (?, ?)   params: ["admin","editor"]
[]       -> 1 = 0   params: []
nullQuery -> u.parent_id IS NULL   params: []
nullQuery '!' -> u.parent_id IS NOT NULL   params: []
```
<!-- /captured:filters-operators -->

| Leading character | Query |
| --- | --- |
| `=` | `field = ?` |
| `!` | `field != ?` |
| `<` | `field <= ?` - less than **or equal**, not strictly less than |
| `>` | `field >= ?` |
| `@` | `field IN (?, ?, ...)`, the rest split on commas |
| `^` | `field::TEXT ILIKE 'value%'` |
| `$` | `field::TEXT ILIKE '%value'` |
| `%` | `field::TEXT ILIKE '%value%'` |
| anything else | Whatever `$compare` was passed as, defaulting to `=` |

Two forms bypass the operator scan:

- A `~` anywhere in the value makes it a range: `field >= ? AND field <= ?` with the two
  halves as parameters. The regex is greedy on the left, so `a~b~c` splits into `a~b` and
  `c`.
- An array value becomes an `IN` list directly, which is what a
  `FieldType::SELECT_MULTIPLE` filter produces. An empty array becomes the constant
  `1 = 0` rather than the syntax error `IN ()`.

`$nullQuery: true` short-circuits everything and emits `field IS NULL`, or `field IS NOT
NULL` when `$compare` is `'!'`. `runFilter()` sets it when the column has
`$filterZeroIsNULL: true` and the value is `0` or `'0'`, which is how "no parent" is
expressed as a select option.

`$valueFormatter` is applied to each value before binding. For the `@` list every element is
formatted individually.

### runFilter()

```php
<?php

public static function runFilter(Column $filterColumn, $value): ?array
```

Chooses the comparison and the value formatter from the column's
[`ColumnType`](/staticphp-core/presentation/columns/#columntype), then delegates to
`valueToQuery()` for everything except the date cases:

<!-- captured:filters-runfilter -->
```text
text      'ann' -> u.name::TEXT ILIKE ?   params: ["%ann%"]
exact     'Ann' -> u.name = ?   params: ["Ann"]
int       '>30' -> u.age >= ?   params: [30]
decimal   '12,5' -> u.rate = ?   params: [12.5]
boolean   'true' -> u.active = ?   params: [1]
date      '01.08.2026' -> u.created >= ? AND u.created <= ?    params: ["2026-08-01 00:00:00","2026-08-01 23:59:59"]
range     '01.08.2026 - 31.08.2026' -> u.created >= ? AND u.created <= ?    params: ["2026-08-01 00:00:00","2026-08-31 23:59:59"]
no match  '1' -> NULL
```
<!-- /captured:filters-runfilter -->

- `TEXT` passes `'%'`, so an unprefixed value becomes a contains-match. Every other type
  passes `'='`. The user can still override with a leading operator character.
- `INT` casts with `(int)`, `DECIMAL` swaps a comma for a dot and casts with `(float)`,
  `BOOLEAN` recognises the strings `true` and `false` before casting with `(int)`.
- `ROW_NUMBER` and `SELECT_ALL_CHECKBOX` return `null` and contribute nothing.

### Dates

The four date types are handled by their own branch, which does not use `valueToQuery()` at
all: it rewrites `dd.mm.yyyy` into `yyyy-mm-dd` with `preg_replace()` and emits a `>=`/`<=`
pair spanning the day, or an `=` for a value that already carries a time.

`DATE` and `DATETIME` then run the bounds through `strtotime()` and bind **integer unix
timestamps**; `DATE_NATIVE` and `DATETIME_NATIVE` bind the `Y-m-d H:i:s` strings. Setting
`Column::$filterSqlDate: true` makes the plain pair behave like the native pair:

```php
<?php

public static function strtotime(string $value, bool $sqlDate = false)
{
    return ($sqlDate === true ? $value : strtotime($value));
}
```

<!-- captured:filters-dates -->
```text
DATE_NATIVE      '01.08.2026'                         -> u.created >= ? AND u.created <= ?   params: ["2026-08-01 00:00:00","2026-08-01 23:59:59"]
DATE_NATIVE      '01.08.2026 - 31.08.2026'            -> u.created >= ? AND u.created <= ?   params: ["2026-08-01 00:00:00","2026-08-31 23:59:59"]
DATETIME_NATIVE  '01.08.2026 10:00'                   -> u.created = ?   params: ["2026-08-01 10:00"]
DATETIME_NATIVE  '01.08.2026 10:00 - 31.08.2026 18:00' -> u.created >= ? AND u.created <= ?   params: ["2026-08-01 00:00:00","2026-08-01 23:59:59"]
DATE             '01.08.2026'                         -> u.created >= ? AND u.created <= ?   params: [1785531600,1785617999]
DATETIME         '01.08.2026 10:00'                   -> u.created = ?   params: [1785567600]
DATE_NATIVE      '2026-08-01'                         -> u.created >= ? AND u.created <= ?   params: ["2026-08-01 00:00:00","2026-08-01 23:59:59"]
```
<!-- /captured:filters-dates -->

:::caution[A date-and-time range silently collapses]
Look at the fourth line. `01.08.2026 10:00 - 31.08.2026 18:00` should be a two-day range and
comes back as the single day 2026-08-01, times discarded. The pattern meant to extract the
end of a datetime range is
`'/.*([0-9]{2})\.([0-9]{2})\.([0-9]{4}) $4:$5$/'` - it has replacement backreferences
`$4:$5` inside the *pattern*, where they are literal text and match nothing. Execution falls
through to the date-only branch, which finds one date and treats it as a single day. Ranges
work for `DATE` and `DATE_NATIVE`; do not offer one on a datetime column.
:::

The date branch returns `null` when the value does not parse as a date at all, so an
unparseable value filters nothing rather than erroring.

## SQLSort

Two methods, both covered in detail under
[sorting](/staticphp-core/presentation/sorting/#sortnulls):

```php
<?php

public function sortNulls(): SortNulls;
public function sortQuery(): string;
```

`sortQuery()` returns `" ORDER BY {$column} {$direction} {$nulls} "` with leading and
trailing spaces, ready to concatenate. `{$column}` is the current column's `$sortBy`
verbatim.

## SQLPagination

One method:

```php
<?php

public function limitQuery()
{
    return <<<EOL
OFFSET {$this->tableInstance->pagination->limitFrom}
LIMIT {$this->tableInstance->pagination->limitPerPage}
EOL;
}
```

Both values are integers computed by
[`Pagination::calculate()`](/staticphp-core/presentation/pagination/#calculate), so this one
is safe to concatenate. There are no surrounding spaces and the two clauses are separated by
a newline, so append it directly after `sortQuery()`.

## The dialect is PostgreSQL

Four things the SQL layer emits are not portable:

| Emitted | Where |
| --- | --- |
| `ILIKE` | `valueToQuery()` for every text comparison |
| `::TEXT` cast | The same fragments |
| `NULLS FIRST` / `NULLS LAST` | `SQLSort::sortQuery()`, always |
| `OFFSET` before `LIMIT` | `SQLPagination::limitQuery()` |

`ILIKE` and `::TEXT` are PostgreSQL. `NULLS FIRST` / `NULLS LAST` is standard SQL that some
engines do not implement. `OFFSET ... LIMIT ...` in that order is accepted by PostgreSQL;
MySQL wants `LIMIT` first.

There is no dialect switch and no configuration hook. On another engine, keep `Table`,
`Filters`, `Sort` and `Pagination` and write your own equivalents of these three classes -
they are 22, 36 and 506 lines, and only `SQLFilters` is substantial. `SQLFilters` is also
the only one whose useful parts (`valueToQuery()`, `runFilter()`) are static and can be
called without an instance.

## TableType and EditableTableType

The `Enums/` directory holds two more enums that belong to the
[HTML output](/staticphp-core/presentation/output-html/) rather than to the SQL classes, and
neither is read anywhere outside `Output/Html.php`.

`TableType`, **2** cases:

| Case | Value | Meaning |
| --- | --- | --- |
| `FULL_HTML` | `full_html` | Wrapper divs plus a footer holding the pagination |
| `TABLE_ONLY` | `table_only` | The `<table>` element alone |

`EditableTableType`, **2** cases:

| Case | Value | Meaning |
| --- | --- | --- |
| `WHOLE_TABLE` | `whole_table` | Editable cells render a form control immediately |
| `BY_FIELD` | `by_field` | Editable cells render as text with `data-` attributes for a script |

Both are set on the `Html` generator, not on the table:

```php
<?php

use StaticPHP\Presentation\Models\Tables\Enums\EditableTableType;
use StaticPHP\Presentation\Models\Tables\Enums\TableType;

$output = new Html($table);
$output->type = TableType::TABLE_ONLY;
$output->editableTableType = EditableTableType::BY_FIELD;
```

The markup each one produces, with captured examples, is under
[HTML output](/staticphp-core/presentation/output-html/#tabletype).
