---
title: Audit table
description: The presentation-side viewer for the trail, the columns it defines, and how it renders a change.
sidebar:
    order: 6
---

`StaticPHP\Presentation\Models\Audit\AuditTable` builds a
[table](/staticphp-core/presentation/tables/) over `audit_log`. It is a **builder, not a
controller**: reading the trail needs authorisation, and the framework has no notion of who is
allowed to. The application checks access, calls `build()`, runs its own query - adding
`WHERE module = ?` if it shows one module at a time - and renders.

```php
<?php

namespace StaticPHP\Presentation\Models\Audit;

class AuditTable
{
    public static function columns(?callable $formatValue = null, string $alias = 'a'): array;

    public static function build(
        string $baseUrl,
        ?string $filterData = null,
        ?string $sortData = null,
        ?int $page = null,
        ?callable $formatValue = null,
        string $alias = 'a'
    ): SQLTable;

    public static function entity(mixed $row): string;
    public static function changes(mixed $row, ?callable $formatValue = null): string;
}
```

`$alias` is the table alias the sort and filter expressions are built against, so a query
written as `FROM audit_log a` needs nothing passed and one written as `FROM audit_log h` needs
`'h'`.

## The columns

Seven, in the order they are read:

```text
nr           title="#"        sortBy=null               filterBy=null
created_at   title="Date"     sortBy="a.created_at"     filterBy="a.created_at"
actor        title="Who"      sortBy="a.actor_name"     filterBy="a.actor_id"
event        title="Event"    sortBy="a.event"          filterBy="a.event"
module       title="Module"   sortBy="a.module"         filterBy="a.module"
entity       title="Record"   sortBy="a.entity_type"    filterBy="a.entity_type"
changes      title="Changes"  sortBy=null               filterBy="a.new_values"
```

| Column | Shows | Notes |
| --- | --- | --- |
| `nr` | the row position | `ColumnType::ROW_NUMBER`; not sortable, not filterable, not exported |
| `created_at` | `created_at` | `ColumnType::DATETIME`, the default sort, descending; filtered as `FieldType::DATEINTERVAL` |
| `actor` | `actor_name` | sorted by `actor_name`, **filtered by `actor_id`** |
| `event` | `event` | filtered as a `FieldType::SELECT` over the three `AuditEvent` constants |
| `module` | `module` | |
| `entity` | [`entity()`](#entity) | sorted and filtered by `entity_type` |
| `changes` | [`changes()`](#changes) | not sortable; filtered by `new_values`; renders its own markup |

The `actor` column is worth reading twice. The name is on the row rather than joined, so the
column keeps working after the account is renamed or deleted - but the filter is on
`actor_id`, so typing a name into the "Who" box searches ids and finds nothing. Either change
`filterBy` in your own copy of the column set, or label the box for what it does.

`build()` constructs an [`SQLTable`](/staticphp-core/presentation/sql-tables/#sqltable), sets
`idKey` to `'id'` and calls `initData()` with the three request-derived strings. Pass `null`
for any of them to skip building that object, exactly as
[`Table::initData()`](/staticphp-core/presentation/tables/#assembling-a-table) describes.

The sql each combination produces - the dialect is
[PostgreSQL](/staticphp-core/presentation/sql-tables/#the-dialect-is-postgresql):

```text
filter="" sort=""
  WHERE:  
  params: []
  ORDER:  ORDER BY a.created_at DESC NULLS LAST
filter="actor=Anna" sort=""
  WHERE:  WHERE a.actor_id::TEXT ILIKE ?
  params: ["%Anna%"]
  ORDER:  ORDER BY a.created_at DESC NULLS LAST
filter="event=deleted;module=catalog" sort="module=asc"
  WHERE:  WHERE a.event::TEXT ILIKE ? AND a.module::TEXT ILIKE ?
  params: ["%deleted%","%catalog%"]
  ORDER:  ORDER BY a.module ASC NULLS LAST
filter="changes=Riga" sort=""
  WHERE:  WHERE a.new_values::TEXT ILIKE ?
  params: ["%Riga%"]
  ORDER:  ORDER BY a.created_at DESC NULLS LAST
filter="entity=people" sort=""
  WHERE:  WHERE a.entity_type::TEXT ILIKE ?
  params: ["%people%"]
  ORDER:  ORDER BY a.created_at DESC NULLS LAST
```

Two things to note. The `changes` filter is `new_values::TEXT ILIKE '%...%'` over the encoded
json, so it searches what a change became and never what it was - a search for the old value
of a column finds nothing. And the `event` filter, despite rendering as a select, is still an
`ILIKE` with wildcards, so it matches on substring rather than equality.

## entity()

"table #key", or just the table name when the row carries no key:

```text
with a key: people #42
without:    people
object row: people #42
not a row:  ""
```

Rows may be arrays or objects - `get_object_vars()` is applied first - and anything that is
neither yields the empty string rather than an error. A row with an empty `entity_id` is the
normal shape for
[an insert on postgres](/staticphp-core/audit/recording/#what-fills-entity_id).

## changes()

The change itself, as markup. An update is rendered as a pair:

```text
<strong>name</strong>: Anna &rarr; Anna Berzina<br /><strong>city</strong>: Dobele &rarr; Riga
```

A created event has no old values worth showing and a deleted one has no new ones, so each is
rendered one-sided rather than as a diff against nothing:

```text
<strong>name</strong>: Anna<br /><strong>city</strong>: Dobele
```

```text
<strong>name</strong>: Anna<br /><strong>city</strong>: Dobele
```

A row where neither column holds anything renders the empty string:

```text
""
```

The iteration is over `$new ?? $old ?? []`, so an update lists the columns of `new_values` and
looks each one up in `old_values`; a column present only in `old_values` is not shown.
[`Diff`](/staticphp-core/audit/diffing/) writes both sides with the same keys, so that only
matters for events an application recorded itself.

Values that were redacted are shown as they were stored:

```text
<strong>password</strong>: *** &rarr; ***
```

A nested value is json-encoded rather than becoming the word `Array`:

```text
<strong>meta</strong>: {&quot;a&quot;:1}
```

Both `old_values` and `new_values` are accepted as json strings **or** as already-decoded
arrays, because some drivers hand json columns back decoded.

### Escaping

Every value and every column name goes through `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
That is not incidental: the trail records what was submitted, so an audit viewer is the one
page in an application guaranteed to be rendering somebody's input back at an administrator.

```text
<strong>name</strong>: Anna &rarr; &lt;img src=x onerror=alert(1)&gt;
```

Because the method emits `<strong>` and `<br />` itself, its column is declared with
`escapeDataHtml: false`, which switches off the table's own escaping for that cell. That is
why the escaping has to happen here, and why a replacement renderer has to do the same.

### The formatter

`$formatValue` receives a column name and a stored value and returns what to show. Dates held
as anything other than a timestamp are the usual reason to pass one:

```php
<?php

$table = AuditTable::build(self::$method_url, $filterData, $sortData, $page, function (string $column, mixed $value): string {
    if ($column === 'approved_at' && is_numeric($value)) {
        return gmdate('d.m.Y', (int) $value);
    }

    return (is_scalar($value) ? (string) $value : '');
});
```

```text
<strong>approved_at</strong>: 03.08.2026
```

Its return value is escaped afterwards, so a formatter cannot inject markup and cannot be used
to emit any. It applies **only** inside the `changes` column; `created_at` renders as it is
stored, offset and all, unless you replace that column's definition.

## A complete worked example

The script below records three changes through
[`Audit`](/staticphp-core/audit/recording/), then reads them back the way a controller would.
Only the authorisation check is missing.

```php
<?php

use StaticPHP\Presentation\Models\Audit\AuditTable;
use StaticPHP\Presentation\Models\Tables\Output\Html;
use StaticPHP\Utils\Models\Audit\Audit;
use StaticPHP\Utils\Models\Db;

Audit::insert('people', ['name' => 'Anna', 'city' => 'Dobele'], module: 'catalog');
Db::insert('people', ['name' => 'Girts', 'city' => 'Jelgava']);
Audit::update('people', ['city' => 'Riga'], ['name' => 'Girts'], module: 'catalog');
Audit::delete('people', ['name' => 'Anna'], module: 'catalog');

// The application checks access, then builds the table.
$table = AuditTable::build('/admin/audit?table=', '', 'created_at=asc', 1);
$table->sqlFilter->prepareQueries();

$where = $table->sqlFilter->querySql();
$params = $table->sqlFilter->params();

$total = Db::query("SELECT count(*) FROM audit_log a{$where}", $params)->fetchColumn();
$table->pagination->calculate((int) $total, 1);

$sql = "SELECT * FROM audit_log a{$where}"
    . $table->sqlSort->sortQuery()
    . $table->sqlPagination->limitQuery();

echo "SQL: {$sql}\n";
echo 'params: ' . json_encode($params) . "\n\n";

$rows = Db::fetchAll($sql, $params);
$table->setRows($rows);

$table->outputGenerator = new Html($table);
echo $table->makeOutput();
```

Run against PostgreSQL 18, with the exclude list covering `people.password`, steps one to
three print the query a controller would send:

```text
SQL: SELECT * FROM audit_log a ORDER BY a.created_at ASC NULLS LAST OFFSET 0
LIMIT 50
params: []
```

`ORDER BY ... NULLS LAST` and the `OFFSET` before `LIMIT` are PostgreSQL syntax; see
[the dialect](/staticphp-core/presentation/sql-tables/#the-dialect-is-postgresql). Then the
markup:

```html
<div class="block block-rounded">
    <div class="block-content block-content-full">
        <div class="table-responsive">        <table id="table_fa1243460b87e69b" class="table"  >
            <thead>
                
                <tr><th   class="" >#</th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/admin/audit?table=/created_at=desc" >Date</a></div><div class="visible-print d-none d-print-inline">Date</div>&nbsp;&nbsp;<span class="fa fas fa-sort-alpha-down sort-icon"></span></div></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/admin/audit?table=/actor=asc" >Who</a></div><div class="visible-print d-none d-print-inline">Who</div></div></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/admin/audit?table=/event=asc" >Event</a></div><div class="visible-print d-none d-print-inline">Event</div></div></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/admin/audit?table=/module=asc" >Module</a></div><div class="visible-print d-none d-print-inline">Module</div></div></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/admin/audit?table=/entity=asc" >Record</a></div><div class="visible-print d-none d-print-inline">Record</div></div></th><th   class="" >Changes</th></tr>
                <tr id="table_filters_fa1243460b87e69b">
<td   class="" ><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_nr"    disabled="disabled" ></td>
<td   class="" ><div class="filter-input-wrap"><input type="text" class="form-control form-control-sm input-xs filter   dateintervalpicker-trigger" id="filter_created_at"    ><button type="button" class="btn-close filter-clear-btn" tabindex="-1" aria-label="Clear filter"></button></div></td>
<td   class="" ><div class="filter-input-wrap"><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_actor"    ><button type="button" class="btn-close filter-clear-btn" tabindex="-1" aria-label="Clear filter"></button></div></td>
<td   class="" ><div class="filter-input-wrap"><select class="form-control form-control-sm input-xs filter   form-select form-select-sm" id="filter_event"   ><option value=""></option><option value="created">Created</option><option value="updated">Updated</option><option value="deleted">Deleted</option></select><button type="button" class="btn-close filter-clear-btn" tabindex="-1" aria-label="Clear filter"></button></div></td>
<td   class="" ><div class="filter-input-wrap"><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_module"    ><button type="button" class="btn-close filter-clear-btn" tabindex="-1" aria-label="Clear filter"></button></div></td>
<td   class="" ><div class="filter-input-wrap"><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_entity"    ><button type="button" class="btn-close filter-clear-btn" tabindex="-1" aria-label="Clear filter"></button></div></td>
<td   class="" ><div class="filter-input-wrap"><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_changes"    ><button type="button" class="btn-close filter-clear-btn" tabindex="-1" aria-label="Clear filter"></button></div></td>
</tr>

                
            </thead>
            <tbody>
                
                <tr title="" class="data-row" ><td  class="data-col text-center col-md-c-1 field_nr"  >1.</td>
<td  class="data-col field_created_at"  >2026-08-02 01:03:25+00</td>
<td  class="data-col field_actor"  >Anna Berzina</td>
<td  class="data-col field_event"  >created</td>
<td  class="data-col field_module"  >catalog</td>
<td  class="data-col field_entity"  >people</td>
<td  class="data-col field_changes"  ><strong>city</strong>: Dobele<br /><strong>name</strong>: Anna</td>
</tr>
<tr title="" class="data-row" ><td  class="data-col text-center col-md-c-1 field_nr"  >2.</td>
<td  class="data-col field_created_at"  >2026-08-02 01:03:25+00</td>
<td  class="data-col field_actor"  >Anna Berzina</td>
<td  class="data-col field_event"  >updated</td>
<td  class="data-col field_module"  >catalog</td>
<td  class="data-col field_entity"  >people #2</td>
<td  class="data-col field_changes"  ><strong>city</strong>: Jelgava &rarr; Riga</td>
</tr>
<tr title="" class="data-row" ><td  class="data-col text-center col-md-c-1 field_nr"  >3.</td>
<td  class="data-col field_created_at"  >2026-08-02 01:03:25+00</td>
<td  class="data-col field_actor"  >Anna Berzina</td>
<td  class="data-col field_event"  >deleted</td>
<td  class="data-col field_module"  >catalog</td>
<td  class="data-col field_entity"  >people #1</td>
<td  class="data-col field_changes"  ><strong>id</strong>: 1<br /><strong>city</strong>: Dobele<br /><strong>name</strong>: Anna<br /><strong>active</strong>: 1<br /><strong>password</strong>: ***</td>
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

- The first row is the insert, and its `Record` cell is `people` with no key - postgres could
  not answer `lastInsertId()`, so `entity_id` was recorded empty. See
  [what fills entity_id](/staticphp-core/audit/recording/#what-fills-entity_id).
- The second is the update, showing only `city` because that is all that changed.
- The third is the delete, showing the whole row, with `password` already `***` because
  redaction happened when the row was written and not here.
- Column order inside the created and deleted rows is postgres `jsonb` key order - shortest
  key first, then bytewise - not the order the application wrote them in. See
  [driver differences](/staticphp-core/audit/storage/#the-audit_log-table).
- The `Date` cells carry the raw `timestamptz` text, offset included.
- The `nr` column's filter cell renders a `disabled` input rather than nothing, because it is
  `filterEnabled: false` rather than `filterHidden: true`.
- Pagination is empty: three rows over fifty per page is one page, and `paginationLinks()`
  returns an empty string when `pageCount <= 1`.

The `id="table_..."` value differs on every run - it is `bin2hex(random_bytes(8))`.

Turning that script into a real controller is the same change as for any other table, and the
url shape matters: the three fragments have to arrive in one query parameter. See
[from script to controller](/staticphp-core/presentation/tables/#from-script-to-controller).

## Replacing a column

`columns()` returns a plain `list<Column>`, so an application that wants a different
`filterBy`, an extra column or a translated title can build its own array from it:

```php
<?php

use StaticPHP\Presentation\Models\Audit\AuditTable;
use StaticPHP\Presentation\Models\Tables\Column;
use StaticPHP\Presentation\Models\Tables\SQL\SQLTable;

$columns = AuditTable::columns();

// index 2 is the actor column; filter on the name instead of the id
$columns[2] = new Column(
    'actor',
    title: 'Who',
    dataKey: 'actor_name',
    sortBy: 'a.actor_name',
    filterBy: 'a.actor_name'
);

$table = new SQLTable($columns, $baseUrl);
$table->idKey = 'id';
$table->initData($filterData, $sortData, $page);
```

That is what `build()` does, so replacing it costs three lines. Column settings are covered
under [columns](/staticphp-core/presentation/columns/).
