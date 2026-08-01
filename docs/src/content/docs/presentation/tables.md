---
title: Tables
description: The table model, the interfaces behind it, and a complete worked example.
sidebar:
    order: 1
---

A table in StaticPHP Core is four cooperating objects: a `Table` that holds the column
definitions and the row data, a `Filters`, a `Sort` and a `Pagination` that each own one
segment of the url, and an output generator that turns the lot into markup. Nothing renders
itself; the table is a description that an output generator reads.

The classes live in `src/Presentation/Models/Tables/`.

## The interfaces

Three interfaces describe the contract, all in
`src/Presentation/Models/Tables/Interfaces/`.

`TableInterface` is what a table has to provide. Every other part of the subsystem is
typed against it rather than against the concrete `Table`:

```php
<?php

interface TableInterface
{
    public function __construct(array $columns, string $urlPrefix = '');

    public function tableId(): string;
    public function parseQueryString(string $str, string $delimiter = '&');

    public function initData(?string $filterData = null, ?string $sortData = null, ?int $page = null): void;

    public function getColumns(): array;
    public function setColumns(array $columns): void;

    public function getRows(): array;
    public function setRows(array &$rows): void;

    public function makeOutput();
    public function showOutput(): void;
}
```

`TableInstanceInterface` is the smaller one. It says only that the implementor is
constructed from a table, by reference:

```php
<?php

interface TableInstanceInterface
{
    public function __construct(TableInterface &$tableInstance);
}
```

`OutputInterface` extends it with the two rendering methods:

```php
<?php

interface OutputInterface extends TableInstanceInterface
{
    public function makeOutput();
    public function showOutput(): void;
}
```

### The TableInstance trait

`Traits/TableInstance.php` is the whole implementation of `TableInstanceInterface`, and it
is fifteen lines:

```php
<?php

trait TableInstance
{
    protected TableInterface $tableInstance;

    public function __construct(TableInterface &$tableInstance)
    {
        $this->tableInstance = &$tableInstance;
    }
}
```

`Output\Html`, `Output\Excel`, `SQL\SQLFilters`, `SQL\SQLSort` and `SQL\SQLPagination` all
use it, which is why they are all constructed the same way and all reach their data through
`$this->tableInstance`.

:::note[Bind the table to a variable first]
`&$tableInstance` is a by-reference parameter, so the argument should be something PHP can
take a reference to. Passing an expression is not fatal - PHP emits a notice and constructs
the object anyway:

```text

Notice: Only variables should be passed by reference in /srv/example/byref.php on line 12
constructed: StaticPHP\Presentation\Models\Tables\Output\Html
constructed: StaticPHP\Presentation\Models\Tables\Output\Html
```

Line 12 is `new Html(new Table($columns))`; the second construction, from a `$table`
variable, is silent. So the working advice is to bind the table first - not because the
alternative breaks, but because the notice is noise and because you almost always need the
variable afterwards to set rows and an output generator on it.
:::

## The Table class

`Table` implements `TableInterface`. Its constructor takes the columns and a url prefix,
generates a per-instance id, and hands the columns to `setColumns()`:

```php
<?php

public function __construct(array $columns, string $urlPrefix = '')
{
    $this->tableId = bin2hex(random_bytes(8));
    $this->urlPrefix = $urlPrefix;

    $this->setColumns($columns);
}
```

`tableId()` returns that sixteen character hex string. It appears in the rendered
`id="table_..."` and `id="table_filters_..."` attributes, and it is regenerated on every
instantiation, so it is not stable between requests.

### Public properties

| Property | Type | Default | Purpose |
| --- | --- | --- | --- |
| `$sort` | `?Sort` | `null` | Set by `initData()` |
| `$filter` | `?Filters` | `null` | Set by `initData()` |
| `$pagination` | `?Pagination` | `null` | Set by `initData()` |
| `$outputGenerator` | `?OutputInterface` | `null` | The renderer `makeOutput()` delegates to |
| `$columns` | `?array` | `null` | Keyed by column id |
| `$rows` | `?array` | `null` | The result set |
| `$children` | `?array` | `null` | Stored, never read |
| `$initRow` | `null\|\Closure` | `null` | Rewrites each row in `setRows()` |
| `$avgRow` | `?array` | `null` | A meta row rendered as one extra `<tr>` |
| `$avgRowPosition` | `RowPosition` | `BODY_TOP` | Where that row goes |
| `$sumRow` | `?array` | `null` | As above |
| `$sumRowPosition` | `RowPosition` | `BODY_TOP` | |
| `$customRow` | `?array` | `null` | As above |
| `$customRowPosition` | `RowPosition` | `BODY_TOP` | |
| `$beforeDataRow` | `?array` | `null` | Markup emitted before every data row |
| `$afterDataRow` | `?array` | `null` | Markup emitted after every data row |
| `$isEditable` | `bool\|\Closure` | `false` | Table-wide edit switch |
| `$idKey` | `null\|string\|\Closure` | `null` | Fallback row identifier |

`$children`, `getChildren()` and `setChildren()` exist on `Table` but are not part of
`TableInterface` and nothing in `src/` reads them. Treat them as an unfinished feature.

`$beforeDataRow` and `$afterDataRow` are arrays whose elements are strings or closures.
`Html::tableBody()` runs the closures with `($rowIndex, $rowItem, $columnCount, $table)`
and concatenates the results around each data row, so whatever they return has to be
complete `<tr>` markup.

### Columns in, columns out

`setColumns()` rejects anything that is not a `Column` and rekeys the array by column id:

```php
<?php

foreach ($columns as $column) {
    if ($column instanceof Column == false) {
        throw new \Exception("Not all columns are instances of Column");
    }

    $this->columns[$column->id] = $column;
}
```

So `$table->columns['name']` is the `Column` whose id is `name`, whatever order it was
declared in. The sort and filter parsers both rely on that lookup.

Column definitions are covered on [columns](/staticphp-core/presentation/columns/).

### Rows in

`setRows()` takes the array **by reference** and runs two passes over it:

1. If `$table->initRow` is a closure, every row is replaced by
   `$initRow($rowIndex, $row)`.
2. For every column with an `initValue` closure, `$rows[$rowIndex][$column->id]` is
   replaced by `$initValue($column, $rowIndex, $row)`.

Because the parameter is `array &$rows`, the caller's array is modified in place and a
literal cannot be passed:

```php
<?php

$rows = Db::fetchAll($sql, $params);
$table->setRows($rows);
```

## Assembling a table

`initData()` is where the three url segments arrive. Each one is independent: pass `null`
to skip building that object entirely.

```php
<?php

public function initData(?string $filterData = null, ?string $sortData = null, ?int $page = null): void
{
    if ($filterData !== null) {
        $this->filter = new Filters($this, "{$this->urlPrefix}%filter/{$sortData}", $filterData);
    }
    if ($sortData !== null) {
        $this->sort = new Sort($this, "{$this->urlPrefix}{$filterData}/%sort", $sortData);
    }
    if ($page !== null) {
        $this->pagination = new Pagination($this, "{$this->urlPrefix}{$filterData}/{$sortData}/%pagination", $page);
    }
}
```

Notice what the three url prefixes are made of. Each one interpolates the *other two*
segments literally and leaves a `%filter`, `%sort` or `%pagination` placeholder in its own
position. That is how a sort link can change the sort while preserving the active filter
and the current page: the output generator only has to `str_replace()` one placeholder.

With `$urlPrefix = '/users/'` and `initData('name=an', 'age=desc', 3)`:

```text
filter->url()     -> '/users/%filter/age=desc'
sort->url()       -> '/users/name=an/%sort'
pagination->url() -> '/users/name=an/age=desc/%pagination'
```

An empty string is not `null`, so `initData('', '', 1)` builds all three objects with no
filter and no sort applied. That is the usual call for a first page load.

:::caution[Order matters]
`initData()` builds `Filters` before `Sort`, and `Sort`'s constructor throws if no column
has `sortDefaultColumn: true`. `Html::makeOutput()` needs all three objects in
`TableType::FULL_HTML` mode and throws if one is missing.
:::

### Rendering

`Table::makeOutput()` and `Table::showOutput()` are thin: if `$outputGenerator` is set they
delegate to it, otherwise they do nothing and return `null`. The renderers are
[HTML](/staticphp-core/presentation/output-html/) and
[Excel](/staticphp-core/presentation/output-excel/).

```php
<?php

public function makeOutput()
{
    if (!empty($this->outputGenerator)) {
        return $this->outputGenerator->makeOutput();
    }
}
```

### parseQueryString()

Both `Filters` and `Sort` parse their segment with `Table::parseQueryString()`, passing
`;` as the delimiter. It splits on the delimiter, splits each pair on `=`, urldecodes the
first two fragments and drops any pair with no `=` in it. It is a plain method rather than
PHP's `parse_str()`, so `[]` in a key means nothing and a repeated key keeps its last value.
A value containing an unencoded `=` is truncated at it: `a=b=c` parses to `a => b`.

## A complete worked example

The script below is a single file. It builds an `SQLTable`, produces the SQL a real
controller would run, feeds a fixed result set back in and renders it. Only step 4 stands
in for a database.

```php
<?php

require 'vendor/autoload.php';

use StaticPHP\Presentation\Models\Tables\Column;
use StaticPHP\Presentation\Models\Tables\Output\Html;
use StaticPHP\Presentation\Models\Tables\SQL\SQLTable;
use StaticPHP\Presentation\Models\Tables\Enums\ColumnType;
use StaticPHP\Presentation\Models\Tables\Enums\FieldType;
use StaticPHP\Presentation\Models\Tables\Enums\FormatterType;
use StaticPHP\Presentation\Models\Tables\Enums\RowPosition;

// 1. Describe the columns.
$columns = [
    new Column(
        'nr',
        type: ColumnType::ROW_NUMBER,
        sortEnabled: false,
        filterHidden: true,
    ),
    new Column(
        'name',
        title: 'Name',
        type: ColumnType::TEXT,
        dataKey: 'name',
        sortBy: 'u.name',
        filterBy: 'u.name',
        filterTitle: 'Search',
        sortDefaultColumn: true,
    ),
    new Column(
        'active',
        title: 'Active',
        type: ColumnType::BOOLEAN,
        dataKey: 'active',
        sortBy: 'u.active',
        filterBy: 'u.active',
        dataFormatter: FormatterType::BOOLEAN,
        filterFieldType: FieldType::SELECT_NO_YES,
    ),
    new Column(
        'balance',
        title: 'Balance',
        type: ColumnType::DECIMAL,
        dataKey: 'balance',
        sortBy: 'u.balance',
        filterBy: 'u.balance',
        dataFormatter: FormatterType::DECIMAL2,
    ),
];

// 2. Build the table and hand it the three url segments from the request.
$table = new SQLTable($columns, '/users/');
$table->initData('name=an', 'balance=desc', 1);

// 3. Turn the parsed filters into a WHERE clause and bound parameters.
$table->sqlFilter->prepareQueries();
$where = $table->sqlFilter->querySql();
$params = $table->sqlFilter->params();
$order = $table->sqlSort->sortQuery();

// 4. Count first, so pagination knows how many pages there are.
$table->pagination->calculate(3, 1);
$limit = $table->sqlPagination->limitQuery();

echo "SQL: SELECT * FROM users u{$where}{$order}{$limit}\n";
echo 'params: ' . json_encode($params) . "\n\n";

// 5. Feed the result set back in.
$rows = [
    ['id' => 7, 'name' => 'Anna', 'active' => 1, 'balance' => '1204.5'],
    ['id' => 8, 'name' => 'Ansis', 'active' => 0, 'balance' => '-12'],
    ['id' => 9, 'name' => 'Andris', 'active' => 1, 'balance' => '0'],
];
$table->setRows($rows);

// 6. Optional summary row, rendered at the top of tbody.
$table->sumRow = ['name' => 'Total', 'balance' => '1192.5'];
$table->sumRowPosition = RowPosition::BODY_TOP;

// 7. Render.
$table->outputGenerator = new Html($table);
echo $table->makeOutput();
```

Steps 1 to 4 print the query that would be sent to the database:

```text
SQL: SELECT * FROM users u WHERE u.name::TEXT ILIKE ? ORDER BY u.balance DESC NULLS LAST OFFSET 0
LIMIT 50
params: ["%an%"]
```

The `OFFSET` before `LIMIT`, and the `::TEXT ILIKE` and `NULLS LAST` clauses, are
PostgreSQL syntax. See
[SQL tables](/staticphp-core/presentation/sql-tables/#the-dialect-is-postgresql).

Steps 5 to 7 print the markup:

```html
<div class="block block-rounded">
    <div class="block-content block-content-full">
        <div class="table-responsive">        <table id="table_a995c74cfaacbfee" class="table"  >
            <thead>
                
                <tr><th   class="" ></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/users/name=an/name=asc" >Name</a></div><div class="visible-print d-none d-print-inline">Name</div></div></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/users/name=an/active=asc" >Active</a></div><div class="visible-print d-none d-print-inline">Active</div></div></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/users/name=an/balance=asc" >Balance</a></div><div class="visible-print d-none d-print-inline">Balance</div>&nbsp;&nbsp;<span class="fa fas fa-sort-alpha-up sort-icon"></span></div></th></tr>
                <tr id="table_filters_a995c74cfaacbfee">
<td   class="" ></td>
<td   class="" ><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_name"    placeholder="Search"   value="an"></td>
<td   class="" ><select class="form-control form-control-sm input-xs filter   form-select form-select-sm" id="filter_active"   ><option value=""></option><option value="0">No</option><option value="1">Yes</option></select></td>
<td   class="" ><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_balance"    ></td>
</tr>

                
            </thead>
            <tbody>
                <tr title="SUM" class="data-row table-sum-row table-meta-row" ><td></td>
<td>Total</td>
<td>No</td>
<td>1192.50</td>
</tr>

                <tr title="" class="data-row" ><td  class="data-col text-center col-md-c-1 field_nr"  >1.</td>
<td  class="data-col field_name"  >Anna</td>
<td  class="data-col field_active"  >Yes</td>
<td  class="data-col field_balance"  >1204.50</td>
</tr>
<tr title="" class="data-row" ><td  class="data-col text-center col-md-c-1 field_nr"  >2.</td>
<td  class="data-col field_name"  >Ansis</td>
<td  class="data-col field_active"  >No</td>
<td  class="data-col field_balance"  >-12.00</td>
</tr>
<tr title="" class="data-row" ><td  class="data-col text-center col-md-c-1 field_nr"  >3.</td>
<td  class="data-col field_name"  >Andris</td>
<td  class="data-col field_active"  >Yes</td>
<td  class="data-col field_balance"  >0.00</td>
</tr>

                
            </tbody>
            <tfoot>
                
                
            </tfoot>
        </table>        </div>
    </div>
    <div class="block-footer">
        
    </div>
</div>
```

Reading that back:

- The `nr` column is `ColumnType::ROW_NUMBER`, so its cells are the one-based row
  position and its header is empty. `filterHidden: true` leaves its filter cell blank.
- Every other header is a sort link whose url is the current path with the sort segment
  replaced. The active column carries an extra `fa-sort-alpha-up` icon span.
- The filter row reflects the parsed filter: `name` is prefilled with `an`, and `active`
  renders as a select because its `filterFieldType` is `FieldType::SELECT_NO_YES`.
- The sum row is rendered first inside `<tbody>` because `sumRowPosition` is
  `RowPosition::BODY_TOP`. It gets `table-sum-row table-meta-row` classes and
  `title="SUM"`.
- Pagination is empty because 3 records over 50 per page is one page, and
  `paginationLinks()` returns an empty string when `pageCount <= 1`.

:::note[Meta rows go through the same formatters]
The sum row has no `active` key, so that cell's value is the empty string, and
`FormatterType::BOOLEAN` turns anything not loosely equal to `1` into `No`. Meta rows are
ordinary row arrays rendered by the same code path as data rows; give them every key you do
not want formatted into something misleading.
:::

The `id="table_..."` value differs on every run - it is `bin2hex(random_bytes(8))`.

### From script to controller

In an application the three arguments to `initData()` come from url segments and the rows
come from the database. Same object graph, different sources:

```php
<?php

namespace Application\Modules\Admin\Controllers;

use StaticPHP\Core\Controllers\Controller;
use StaticPHP\Utils\Models\Db;
use StaticPHP\Presentation\Models\Tables\Output\Html;
use StaticPHP\Presentation\Models\Tables\SQL\SQLTable;

class Users extends Controller
{
    public static function index($filterData = '', $sortData = '', $page = 1)
    {
        $table = new SQLTable(self::columns(), '/admin/users/');
        $table->initData($filterData, $sortData, (int) $page);
        $table->sqlFilter->prepareQueries();

        $where = $table->sqlFilter->querySql();
        $params = $table->sqlFilter->params();

        $total = Db::query("SELECT count(*) FROM users u{$where}", $params)->fetchColumn();
        $table->pagination->calculate((int) $total, (int) $page);

        $sql = "SELECT * FROM users u{$where}"
            . $table->sqlSort->sortQuery()
            . $table->sqlPagination->limitQuery();
        $rows = Db::fetchAll($sql, $params);
        $table->setRows($rows);

        $table->outputGenerator = new Html($table);
        $table->showOutput();
    }
}
```

`Db` is covered under [database](/staticphp-core/database/db/), and mapping url segments
onto method arguments under [routing](/staticphp-core/core/router/).

## What throws

Every line below was produced by calling the code:

```text
unknown Column setting: Exception: "tittle" does not exists on Column
non-Column in the array: Exception: Not all columns are instances of Column
no default sort column: Exception: No default column was found
filterData() with no filter string: TypeError: StaticPHP\Presentation\Models\Tables\Filters::filterData(): Return value must be of type string, null returned
sortBy() with no sortBy set: TypeError: StaticPHP\Presentation\Models\Tables\Sort::sortBy(): Return value must be of type string, null returned
render with no sort: Exception: Sort is not initialized
render with no filter: Exception: Filter is not initialized
paginationLinks() with no pagination: Exception: Pagination is not initialized
expandable + editable: Exception: Expandable text is not supported for editable columns
```

The two `TypeError`s are the interesting ones, because neither is a guard the framework put
there deliberately:

- `Filters::filterData()` is declared `: string` but returns the nullable
  `$this->filterData`. Constructing `Filters` without a filter string leaves it `null`.
  `initData()` never does that - it only builds a `Filters` when `$filterData !== null` -
  so you reach this only by constructing `Filters` yourself.
- `Sort::sortBy()` is declared `: string` and returns `$this->currentColumn->sortBy`, which
  defaults to `null`. Any column that can become the sort column needs a `sortBy` value.
  See [sorting](/staticphp-core/presentation/sorting/).
