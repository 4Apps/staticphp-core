---
title: Tables
description: The table model, the interfaces behind it, and a complete worked example.
sidebar:
    order: 1
---

A table in StaticPHP Core is four cooperating objects: a `Table` that holds the column
definitions and the row data, a `Filters`, a `Sort` and a `Pagination` that each own one
fragment of the request, and an output generator that turns the lot into markup. Nothing
renders itself; the table is a description that an output generator reads.

The classes live in `src/Presentation/Models/Tables/`.

The package ships one table built on top of them,
[`AuditTable`](/staticphp-core/audit/audit-table/), which is worth reading as a worked example
of the column set and `initData()` call this page describes.

## The interfaces

Three interfaces describe the contract, all in
`src/Presentation/Models/Tables/Interfaces/`.

`TableInterface` is what a table has to provide. Every other part of the subsystem is
typed against it rather than against the concrete `Table`. It declares state as well as
behaviour, through property hooks - the output generators and the SQL helpers read those
properties directly, so they are part of the contract rather than an implementation detail
of `Table`:

```php
<?php

interface TableInterface
{
    public ?Sort $sort { get; set; }
    public ?Filters $filter { get; set; }
    public ?Pagination $pagination { get; set; }

    public array $columns { get; set; }

    public RowPosition $avgRowPosition { get; set; }
    public RowPosition $sumRowPosition { get; set; }
    public RowPosition $customRowPosition { get; set; }

    public bool|\Closure $isEditable { get; set; }
    public bool $showReadonlyInputs { get; set; }
    public null|string|\Closure $idKey { get; set; }

    public function __construct(array $columns, string $urlPrefix = '');

    public function tableId(): string;
    public function parseQueryString(string $str, string $delimiter = '&'): array;

    public function initData(?string $filterData = null, ?string $sortData = null, ?int $page = null): void;

    public function getColumns(): array;
    public function setColumns(array $columns): void;

    public function getRows(): array;
    public function setRows(array &$rows): void;

    public function makeOutput(): mixed;
    public function showOutput(): void;
}
```

The hooks are `{ get; set; }` with no bodies, so a `Table` satisfies them with plain public
properties. What they buy is that a class implementing the interface without them does not
compile, which is what stops an output generator reaching for `$table->sort` on something
that never declared one.

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
    public function makeOutput(): mixed;
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

### Utils

Almost every column property in this subsystem is `T|\Closure`, and `Tables\Utils` is where
that gets resolved. It is four public statics with no state, and nothing in the framework
calls them from outside `Tables/` - but an `OutputInterface` implementation of your own is
on the outside, and this is the class it needs:

```php
<?php

public static function ensureArray(mixed $value): array;
public static function valueOrClosure(mixed $value, ?\Closure $closure = null, array $args = []): mixed;
public static function expandClosure(mixed $closure, array $args = []): mixed;
public static function runClosures(array $arrayOfData, array $args = []): array;
```

`expandClosure()` is the one to reach for: it calls `$closure` with `$args` when the value
is callable and returns it untouched when it is not, which is what makes a column property
accept either a literal or a closure without the caller testing. `runClosures()` maps it
over an array and coerces each result to a string, because its callers implode the result
straight into markup - attribute lists, class lists - so a value with no string form was
never usable there. A non-scalar becomes `''` rather than raising. `valueOrClosure()`
inverts the argument order: the value goes in first and the closure, if there is one, is
called with the value prepended to `$args`. `ensureArray()` wraps a non-array in one - it
does not filter, so `null` comes back as `[null]`.

```text
ensureArray('one')                                         => array ( 0 => 'one', )
ensureArray(['a' => 1])                                    => array ( 'a' => 1, )
ensureArray(null)                                          => array ( 0 => NULL, )
valueOrClosure('raw')                                      => 'raw'
valueOrClosure('raw', fn ($v, $s) => $s . $v, ['<'])       => '<raw'
expandClosure('plain', ['ignored'])                        => 'plain'
expandClosure(fn ($row) => $row['id'], [['id' => 7]])      => 7
expandClosure('strtoupper', ['ab'])                        => 'AB'
runClosures(['class' => 'a', 'id' => fn ($r) => $r], ['x']) => array ( 'class' => 'a', 'id' => 'x', )
runClosures([fn () => ['not', 'scalar']])                  => array ( 0 => '', )
runClosures([true, 1.5, null])                             => array ( 0 => '1', 1 => '1.5', 2 => '', )
```

`expandClosure()` tests with `is_callable()` rather than `instanceof \Closure`, so a
function name in a string is called too - `'strtoupper'` above. That is worth knowing before
storing a plain string in a property this passes through.

The internal callers are `Output\Html` (twenty-two), `SQL\SQLFilters` (five) and `Table`
(two); [HTML output](/staticphp-core/presentation/output-html/) shows the shape they use it
in.

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
| `$columns` | `array` | `[]` | Keyed by column id |
| `$rows` | `array` | `[]` | The result set |
| `$children` | `array` | `[]` | Stored, never read |
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
| `$showReadonlyInputs` | `bool` | `false` | Render non-editable rows as disabled inputs |
| `$idKey` | `null\|string\|\Closure` | `null` | Fallback row identifier |

`$showReadonlyInputs` matters when `$isEditable` is a closure that answers differently per
row. Left off, the editable rows render controls and the rest render bare text, so column
widths jump between rows; turned on, every row renders the same control and the non-editable
ones are `disabled`. `Html::htmlDataRow()` is the only reader.

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

`initData()` is where the three request-derived strings arrive. Each one is independent:
pass `null` to skip building that object entirely.

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
fragments literally and leaves a `%filter`, `%sort` or `%pagination` placeholder in its own
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

:::caution[Those three urls are not routable as written]
`/users/name=an/%sort` is a *path*, and
[`Router::isSafeSegment()`](/staticphp-core/core/router/#safety-helpers) rejects any segment
holding a `=`, so following that link is a 404 before the controller loads. Percent-encoding
does not help - segments are decoded before the check. The `$urlPrefix` that keeps all three
alive ends in a query parameter rather than a slash; see
[from script to controller](#from-script-to-controller) below and
[filters](/staticphp-core/presentation/filters/#it-cannot-arrive-as-a-url-segment).
:::

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

public function makeOutput(): mixed
{
    if (!empty($this->outputGenerator)) {
        return $this->outputGenerator->makeOutput();
    }

    return null;
}
```

The `mixed` return type is what lets the two shipped generators disagree about what they
produce: `Html::makeOutput()` is declared `: string` and `Excel::makeOutput()` returns a
`Spreadsheet`. Both narrow `mixed`, which is legal.

### parseQueryString()

Both `Filters` and `Sort` parse their fragment with `Table::parseQueryString()`, passing
`;` as the delimiter. It splits on the delimiter, splits each pair on `=`, urldecodes the
first two fragments and drops any pair with no `=` in it. It is a plain method rather than
PHP's `parse_str()`, so `[]` in a key means nothing and a repeated key keeps its last value.
A value containing an unencoded `=` is truncated at it: `a=b=c` parses to `a => b`.

## A complete worked example

The script below is a single file. It builds an `SQLTable`, produces the SQL a real
controller would run, feeds a fixed result set back in and renders it. Only step 4 stands
in for a database. It keeps the plain `'/users/'` prefix so the generated urls stay short
and readable; those particular urls are not routable, and
[from script to controller](#from-script-to-controller) below is where that gets fixed.

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

// 2. Build the table and hand it the three strings the request supplied.
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
        <div class="table-responsive">        <table id="table_e6d2ff10a319d423" class="table"  >
            <thead>
                
                <tr><th   class="" ></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/users/name=an/name=asc" >Name</a></div><div class="visible-print d-none d-print-inline">Name</div></div></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/users/name=an/active=asc" >Active</a></div><div class="visible-print d-none d-print-inline">Active</div></div></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/users/name=an/balance=asc" >Balance</a></div><div class="visible-print d-none d-print-inline">Balance</div>&nbsp;&nbsp;<span class="fa fas fa-sort-alpha-up sort-icon"></span></div></th></tr>
                <tr id="table_filters_e6d2ff10a319d423">
<td   class="" ></td>
<td   class="" ><div class="filter-input-wrap has-value"><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_name"    placeholder="Search"   value="an"><button type="button" class="btn-close filter-clear-btn" tabindex="-1" aria-label="Clear filter"></button></div></td>
<td   class="" ><div class="filter-input-wrap"><select class="form-control form-control-sm input-xs filter   form-select form-select-sm" id="filter_active"   ><option value=""></option><option value="0">No</option><option value="1">Yes</option></select><button type="button" class="btn-close filter-clear-btn" tabindex="-1" aria-label="Clear filter"></button></div></td>
<td   class="" ><div class="filter-input-wrap"><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_balance"    ><button type="button" class="btn-close filter-clear-btn" tabindex="-1" aria-label="Clear filter"></button></div></td>
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
- Every other header is a sort link whose url is the current one with the sort fragment
  replaced. The active column carries an extra `fa-sort-alpha-up` icon span.
- The filter row reflects the parsed filter: `name` is prefilled with `an`, and `active`
  renders as a select because its `filterFieldType` is `FieldType::SELECT_NO_YES`.
- Every control the user can type into or pick from sits in a
  `<div class="filter-input-wrap">` with a `btn-close filter-clear-btn` button after it. The
  `name` wrapper also carries `has-value`, because that is the only column the filter string
  supplied. The hidden `nr` cell gets neither. See
  [the filter row](/staticphp-core/presentation/output-html/#the-filter-row).
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

In an application the three arguments to `initData()` come from the request and the rows
come from the database. Same object graph, different sources.

They cannot come from url segments. A filter string holds `=`, a page number is digits, and
[`Router::isSafeSegment()`](/staticphp-core/core/router/#safety-helpers) refuses both, so
`/admin/users/index/name=an/name=asc/1` is a 404 rather than a call. They arrive on the
query string instead - and in **one** parameter, because `initData()` joins the three
fragments with `/` when it builds the urls the output generator links to. Ending
`$urlPrefix` with `?table=` puts that whole join inside the parameter's value, which is the
only shape that keeps the generated sort and pagination links routable:

```php
<?php

namespace Admin\Controllers;

use StaticPHP\Core\Controllers\Controller;
use StaticPHP\Core\Models\Router;
use StaticPHP\Utils\Models\Db;
use StaticPHP\Presentation\Models\Tables\Output\Html;
use StaticPHP\Presentation\Models\Tables\SQL\SQLTable;

class Users extends Controller
{
    public static function index()
    {
        // "name=an;state=open/name=asc/2" - filter, sort and page, in that order
        [$filterData, $sortData, $page] = array_pad(
            explode('/', (string) ($_GET['table'] ?? ''), 3),
            3,
            ''
        );
        $page = max(1, (int) $page);

        $table = new SQLTable(self::columns(), Router::siteUrl('admin/users/index') . '?table=');
        $table->initData($filterData, $sortData, $page);
        $table->sqlFilter->prepareQueries();

        $where = $table->sqlFilter->querySql();
        $params = $table->sqlFilter->params();

        $total = Db::query("SELECT count(*) FROM users u{$where}", $params)->fetchColumn();
        $table->pagination->calculate((int) $total, $page);

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

The namespace root is the module name, not a path - `Admin\Controllers\Users` is
`APP_MODULES_PATH/Admin/Controllers/Users.php`, which is what the application autoloader
resolves and what the router builds. `Db` is covered under
[database](/staticphp-core/database/db/), and the segment rule behind all of the above under
[routing](/staticphp-core/core/router/#safety-helpers).

A cut-down copy of that controller - same columns, same `$urlPrefix`, fixed rows instead of
a query - run through the router with `?table=name%3Dan/active%3Ddesc/2` emits these
anchors:

```html
<a href="http://localhost/admin/users/index?table=name=an/name=asc" >Name</a>
<a href="http://localhost/admin/users/index?table=name=an/active=asc" >Active</a>
<a class="page-link" href="http://localhost/admin/users/index?table=name=an/active=desc/1">1</a>
<a class="page-link" href="http://localhost/admin/users/index?table=name=an/active=desc/3">3</a>
```

Every one of them is a url `isSafeSegment()` accepts, and following any of them re-renders
the table with the new sort or page. That is the round trip a path-segment `$urlPrefix`
cannot complete: `/admin/users/index/name=an/active=desc/2` returns 404 with the controller
never loaded.

## What throws

Every line below was produced by calling the code:

```text
unknown Column setting: Exception: "tittle" does not exists on Column
non-Column in the array: Exception: Not all columns are instances of Column
no default sort column: Exception: No default column was found
render with no sort: Exception: Sort is not initialized
render with no filter: Exception: Filter is not initialized
paginationLinks() with no pagination: Exception: Pagination is not initialized
prepareQueries() with no filter: LogicException: SQLFilters needs a table whose filtering was initialised
sortQuery() with no sort: LogicException: SQLSort needs a table whose sorting was initialised
limitQuery() with no pagination: LogicException: SQLPagination needs a table whose paging was initialised
expandable + editable: Exception: Expandable text is not supported for editable columns
```

The three `LogicException`s are the deliberate ones. Each names the object `initData()` did
not build, which is the whole of the diagnosis: an `SQL*` helper constructed by hand against
a table whose corresponding argument was `null`. `SQLTable::initData()` cannot produce them,
because it builds each helper only when the matching argument is not `null`.

Two things that used to throw here no longer do, and both now have a defined answer instead:

```text
filterData() with no filter string -> ''
sortBy() with no sortBy set        -> the column id
```

- `Filters::filterData()` returns `$this->filterData ?? ''`, so a `Filters` constructed
  without a filter string reports the empty string rather than failing its own `: string`
  return type.
- `Sort::sortBy()` falls back to the column id. That is a usable `ORDER BY` term only when
  the query selects the column under exactly that name; set `$sortBy` explicitly on anything
  you sort on. See [sorting](/staticphp-core/presentation/sorting/).
