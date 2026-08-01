---
title: HTML output
description: The HTML output generator, the markup it emits, and how editable cells are rendered.
sidebar:
    order: 6
---

`StaticPHP\Presentation\Models\Tables\Output\Html` is the default output generator. It reads
a table and returns a string of markup; it holds no data of its own beyond a handful of
presentation settings.

```php
<?php

use StaticPHP\Presentation\Models\Tables\Output\Html;

$table->outputGenerator = new Html($table);
echo $table->makeOutput();
```

It implements
[`OutputInterface`](/staticphp-core/presentation/tables/#the-interfaces) and uses the
`TableInstance` trait, so the constructor is the trait's - one by-reference table argument
and nothing else.

:::note[The markup targets Bootstrap 5]
Every class name it emits - `table-responsive`, `block block-rounded`, `form-control`,
`form-select`, `form-check form-switch`, `pagination page-item page-link`, `badge bg-*` -
is Bootstrap. The sort indicator is a Font Awesome `fa-sort-alpha-up` / `fa-sort-alpha-down`
span. Nothing is configurable short of overriding the class properties or restyling those
selectors; the package ships no css.
:::

## Settings

| Property | Type | Default |
| --- | --- | --- |
| `$type` | `TableType` | `TableType::FULL_HTML` |
| `$classNames` | `string` | `'table'` |
| `$tableAttributes` | `array` | `[]` |
| `$editableTableType` | `EditableTableType` | `EditableTableType::WHOLE_TABLE` |
| `$dataRowAttributes` | `array` | `[]` |
| `$dataRowClasses` | `array` | `['data-row']` |
| `$dataColumnAttributes` | `array` | `[]` |
| `$dataColumnClasses` | `array` | `['data-col']` |

`$classNames` is a single string written straight into `class="..."` on the `<table>`.
`$tableAttributes` is an array of complete attribute strings, imploded with a space;
elements may be closures taking `($table)`.

The four `$dataRow*` / `$dataColumn*` arrays are the table-wide defaults that each column's
own `$dataColumnAttributes` and `$dataColumnClasses` are merged **onto**. Their elements may
be closures: the row pair receives `($rowIndex, $rowItem, $columnCount)`, the column pair
`($column, $rowIndex, $rowItem, $columnCount)`. Anything that evaluates to a falsy value is
dropped by `array_filter()` before the implode, so a closure returning `''` or `null`
contributes nothing:

```php
<?php

$output = new Html($table);
$output->classNames = 'table table-striped table-hover';
$output->tableAttributes = ['data-url="/admin/users/update"'];
$output->dataRowClasses[] = fn($rowIndex, $row) => empty($row['active']) ? 'text-muted' : '';
```

## TableType

`Enums/TableType.php`, a backed string enum with **2** cases:

| Case | Value | Emits |
| --- | --- | --- |
| `FULL_HTML` | `full_html` | Wrapper divs, the table, and a footer holding the pagination |
| `TABLE_ONLY` | `table_only` | The `<table>` element and nothing else |

`FULL_HTML` is the default and wraps the table in

```html
<div class="block block-rounded">
  <div class="block-content block-content-full">
    <div class="table-responsive">
      ...table...
    </div>
  </div>
  <div class="block-footer">...pagination...</div>
</div>
```

`TABLE_ONLY` skips both the wrapper and the pagination call, which is what makes it the
right choice for an ajax refresh that replaces the table body in place - and the only way
to render a table that was built without a `Pagination`, since `paginationLinks()` throws
when there is none.

```text
        <table id="table_27a6dc755af3c1b3" class="table table-striped" data-url="/users/update" >
            <thead>
                
                <tr><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/users//name=desc" >Name</a></div><div class="visible-print d-none d-print-inline">Name</div>&nbsp;&nbsp;<span class="fa fas fa-sort-alpha-down sort-icon"></span></div></th><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/users//active=asc" >Active</a></div><div class="visible-print d-none d-print-inline">Active</div></div></th></tr>
                <tr id="table_filters_27a6dc755af3c1b3">
<td   class="" ><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_name"    ></td>
<td   class="" ><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_active"    ></td>
</tr>

                
            </thead>
            <tbody>
                
                <tr title="" class="data-row" ><td  class="data-col field_name"  >Anna</td>
<td  class="data-col field_active"  >    <div class="form-check form-switch">
        <input
            type="checkbox"
            name="active"
            id="active_7"
            value="1"
            class="form-check-input update_field "
             checked="checked"
            
        >
        <label class="form-check-label" for="active_7"></label>
    </div></td>
</tr>

                
            </tbody>
            <tfoot>
                
                
            </tfoot>
        </table>
```

That was produced with `$classNames = 'table table-striped'` and
`$tableAttributes = ['data-url="/users/update"']`, on a table with one editable
`FieldType::SWITCH` column.

## The structure of makeOutput()

`makeOutput()` builds the markup from seven calls, in this order:

```html
<table id="table_{tableId}" class="{classNames}"{tableAttributes}>
    <thead>
        {rowWithPosition(HEAD_TOP)}
        {titleRow()}
        {filtersRow()}
        {rowWithPosition(HEAD_BOTTOM)}
    </thead>
    <tbody>
        {rowWithPosition(BODY_TOP)}
        {tableBody()}
        {rowWithPosition(BODY_BOTTOM)}
    </tbody>
    <tfoot>
        {rowWithPosition(FOOT_TOP)}
        {rowWithPosition(FOOT_BOTTOM)}
    </tfoot>
</table>
```

Three of those throw if the corresponding object was never built by `initData()`:

```text
Exception: Sort is not initialized
Exception: Filter is not initialized
Exception: Pagination is not initialized
```

The pagination one only fires in `FULL_HTML` mode, because that is the only branch that
calls `paginationLinks()`.

Before any of it, `makeOutput()` walks the columns and adds a `data-field_{id}_options`
attribute to the `<table>` for every column whose `$editFieldType` is one of the three
select types, holding `json_encode()` of the options. Grouped options add
`data-field_{id}_options_groupped="true"`. These exist for client-side editing scripts to
read; nothing server side uses them.

:::caution[The options attribute uses a different editability test from the cells]
The loop skips a column with `if ($column->isEditable === false) { continue; }` - a literal
identity check. Two consequences:

- A `$isEditable` **closure** is not `=== false` whatever it returns, so it never skips. The
  closure is not called here.
- `Table::$isEditable`, the table-wide switch, is not consulted at all. The per-cell path
  does consult it, as `Utils::expandClosure($column->isEditable) && Utils::expandClosure($this->tableInstance->isEditable)`.

So a table with editing switched off can still publish its option lists in the markup:

```text
Table::$isEditable = false, and neither column is editable.

the <table> tag makeOutput() produced:
<table class="table" data-field_b_options="{&quot;1&quot;:&quot;Draft&quot;,&quot;2&quot;:&quot;Sent&quot;}" >

cells rendered for row 0:
  <td  class="data-col field_a"  >1</td>
  <td  class="data-col field_b"  >2</td>
```

Column `a` set `isEditable: false` literally and is skipped. Column `b` set
`isEditable: fn() => false` and its options are emitted regardless, even though the table's
own switch is off and neither cell renders a control. Do not put anything in
`$editSelectOptions` that a reader of the page should not see.
:::

`showOutput()` sends `Content-Type: text/html; charset=utf-8` and echoes the result.

## Escaping

`Html::escape()` is a static wrapper on `htmlspecialchars()` with the flags that matter:

```php
<?php

public static function escape($value): string
{
    if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
        $value = (is_array($value) || is_object($value) ? '' : (string) $value);
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

`ENT_QUOTES` covers both quote characters, so the result is safe in a double- or
single-quoted attribute as well as in text. `ENT_SUBSTITUTE` replaces invalid UTF-8 rather
than returning an empty string, which is the failure mode that turns a mis-encoded byte into
a silently blank cell. Arrays and objects become the empty string; `null` becomes `''` and
`true` becomes `'1'`:

```text
'<b>&"x"</b>'      -> '&lt;b&gt;&amp;&quot;x&quot;&lt;/b&gt;'
"O'Neil"           -> 'O&#039;Neil'
null               -> ''
true               -> '1'
['a']              -> ''
42                 -> '42'
```

Cell values are escaped at exactly one place, at the end of the per-cell work in
`htmlDataRow()`:

```php
<?php

if ($dataValueIsMarkup === false && $column->escapeDataHtml === true) {
    $dataValue = self::escape($dataValue);
}
```

`$dataValueIsMarkup` is set by any branch that replaced the value with markup it built
itself - the checkbox, switch, select, textarea and input branches - because those already
escaped what they interpolated and escaping again would show the tags. Everything else is
raw data and gets escaped unless the column opted out with
[`$escapeDataHtml: false`](/staticphp-core/presentation/columns/#presentation).

Several things are **not** escaped, by design, and must not carry user input:

- `Column::$description`, spliced into the header `title="..."` attribute.
- `Column::$sortLinkAttribute`, spliced into the `<a>` tag.
- `$columnAttributes`, `$columnClasses`, `$dataColumnAttributes`, `$dataColumnClasses`,
  `$dataRowAttributes`, `$dataRowClasses`, `$tableAttributes` - all complete attribute or
  class fragments.
- `Column::$dataColumnPrefix` and `$dataColumnAddon`.
- The `$title` argument of `htmlDataRow()`, written into `title="..."`.

## The header row

`titleRow()` emits one `<th>` per visible column carrying `sortLinkHtml()`, plus that
column's `$columnAttributes` and `$columnClasses`.

`sortLinkHtml()` returns the bare `$title` when `$sortEnabled` is false. Otherwise it builds
two copies of the label - one in `<div class="hidden-print d-print-none">` wrapping an `<a>`,
one in `<div class="visible-print d-none d-print-inline">` as plain text - so the link
disappears in print stylesheets. The active column gets a trailing
`<span class="fa fas fa-sort-alpha-down sort-icon">` (`-up` when descending).

`sortUrl()` computes the target: see
[sorting](/staticphp-core/presentation/sorting/#the-url).

## The filter row

`filtersRow()` emits one `<td>` per visible column carrying `filterInputField()`. Both
respect `Column::$showColumn`.

`filterInputField()` switches on `$filterFieldType` - the full mapping is on
[columns](/staticphp-core/presentation/columns/#fieldtype). What every branch shares:

- `id="filter_{columnId}"`.
- The classes `form-control form-control-sm input-xs filter`, plus the column's
  `$filterInputClasses`, plus `form-select form-select-sm` for the select branches.
- `$filterInputAttributes`, and `disabled="disabled"` when `$filterEnabled` is false.
- An empty string when `$filterHidden` is true - the cell is still emitted, the control is
  not.

`filterInputValue($field, $compare = null, $checkbox = false)` supplies the current value.
With no `$compare` it returns ` value="{title}"` for an input, plus
` data-value="{value}"` when `title` and `value` differ. With a `$compare` it returns
` selected="selected"` or ` checked="checked"` when that option is the active one, matching
against `value` and handling the array case that a multiple select produces.

The select branches build their options from `$filterSelectOptions`, or from
`[0 => 'No', 1 => 'Yes']` for `SWITCH`, `CHECKBOX` and `SELECT_NO_YES`. An empty option
carrying `$filterTitle` is prepended unless `$filterSelectSkipEmptyDefault` is set, and
`$filterSelectDefaultDisabled` makes that option `disabled`.

### Grouped options

`$filterSelectOptionsGroups` switches the branch to `<optgroup>`s. It is a second array
whose **keys** index into `$filterSelectOptions`: each group key is looked up there for that
group's options, and a group with no matching key emits an empty `<optgroup>`. The four
properties work together:

| Property | Role |
| --- | --- |
| `$filterSelectOptionsGroups` | `[groupKey => group]`, in render order |
| `$filterSelectOptionsGroupTitleKey` | Key to read the label out of a group; the group itself is the label when unset |
| `$filterSelectOptionsIdKey` | Key to read an option's value; the array key is used when unset |
| `$filterSelectOptionsTitleKey` | Key to read an option's label; the option itself is used when unset |

```php
<?php

new Column(
    'country',
    title: 'Country',
    dataKey: 'country',
    sortBy: 'country',
    sortDefaultColumn: true,
    filterTitle: 'Any country',
    filterFieldType: FieldType::SELECT,
    filterSelectOptions: [
        'baltics' => [['id' => 'lv', 'name' => 'Latvia'], ['id' => 'ee', 'name' => 'Estonia']],
        'nordics' => [['id' => 'fi', 'name' => 'Finland']],
    ],
    filterSelectOptionsIdKey: 'id',
    filterSelectOptionsTitleKey: 'name',
    filterSelectOptionsGroups: [
        'baltics' => ['label' => 'Baltics'],
        'nordics' => ['label' => 'Nordics'],
    ],
    filterSelectOptionsGroupTitleKey: 'label',
);
```

`filterInputField()` on that column, with `country=ee` in the filter, returns one line:

```html
<select class="form-control form-control-sm input-xs filter   form-select form-select-sm" id="filter_country"   ><option value="">Any country</option><optgroup label="Baltics"><option value="lv">Latvia</option><option value="ee" selected="selected">Estonia</option></optgroup><optgroup label="Nordics"><option value="fi">Finland</option></optgroup></select>
```

The empty `$filterTitle` option is emitted before the first `<optgroup>`, and the active
option carries `selected="selected"` exactly as in the flat case.

`inputValue($value, $compare = null, $checkbox = false)` is the same idea without the filter
lookup, used by the editable-cell branches.

## Data rows

`tableBody()` iterates the rows, wrapping each `htmlDataRow()` call in the table's
`$beforeDataRow` and `$afterDataRow` closures. With no rows at all it returns a single
placeholder row:

```html
<tr><td colspan="{columnCount}" class="table-empty table-secondary">No record was found</td></tr>
```

That string is not translated and there is no hook to replace it.

`htmlDataRow($rowIndex, $rowItem, $title = '', $rowClasses = [])` does the per-cell work.
For each visible column, in order:

1. Read the value: `$dataKey` as a closure gets
   `($column, $rowIndex, $rowItem, $columnCount)`, otherwise it is a key into the row array.
2. Apply `$dataFormatter` through `formatData()`.
3. If `$rowIndex < 0` - a meta row - emit a bare `<td>` and stop here.
4. Resolve the row id, trying `$column->idKey`, then the row array, then `$table->idKey`,
   falling back to the row index. It is escaped once and reused in every `id=` and
   `data-id=` below.
5. Decide editability: the column's `$isEditable` **and** the table's, both expanded if
   they are closures.
6. Merge the column's data classes and attributes onto the generator's.
7. Apply the `ColumnType` switch (`ROW_NUMBER`, `SELECT_ALL_CHECKBOX`).
8. Apply the `$editFieldType` switch.
9. Escape, unless step 7 or 8 produced markup.
10. Wrap in `$dataColumnPrefix` / `$dataColumnAddon`, `$dataColumnBage`, and the expandable
    text divs.

The cell ends up as `<td{attributes}>{prefix}{value}{addon}</td>`. Every column gets a
`field_{id}` class unless it is being rendered as a by-field edit target.

`rowWithPosition()` renders the average, sum and custom meta rows at a given
[`RowPosition`](/staticphp-core/presentation/columns/#rowposition), in that order, with row
indexes `-1`, `-2` and `-3` and the class pairs `table-avg-row`, `table-sum-row` and
`table-custom-row`, each alongside `table-meta-row`.

## Editable cells

Two switches have to be on. `Table::$isEditable` is the table-wide one and
`Column::$isEditable` the per-column one; a cell is editable only if both expand to truthy.
Either may be a closure taking no arguments, which is how "editable if the current user has
the permission" is expressed.

### EditableTableType

`Enums/EditableTableType.php`, a backed string enum with **2** cases:

| Case | Value | Meaning |
| --- | --- | --- |
| `WHOLE_TABLE` | `whole_table` | Every editable cell renders its form control immediately |
| `BY_FIELD` | `by_field` | Cells render as text and carry the data needed to build a control on click |

`WHOLE_TABLE`, the default, puts a real `<input>`, `<select>`, `<textarea>` or checkbox in
each editable cell, with an `update_field` class for a script to bind to. The switch
rendering from the `TABLE_ONLY` capture above is an example.

`BY_FIELD` takes the other branches early: the select, textarea and default-input branches
all `break` out before generating anything when `$editableTableType === BY_FIELD`. The cell
keeps its formatted text and instead gains

- `data-name="{columnId}"`,
- `data-type="{editFieldType->value}"`,
- `data-raw_value="{editValue}"` with `"`, `<` and `>` replaced,
- a `table_edit_field_trigger` class,
- a `<span class="table_edit_display field_{columnId}">` wrapper around the value.

```text
        <table id="table_f1a3eca848e960bd" class="table"  >
            <thead>
                
                <tr><th   class="" ><div class="d-flex align-items-center"><div class="hidden-print d-print-none"><a href="/users//name=desc" >Name</a></div><div class="visible-print d-none d-print-inline">Name</div>&nbsp;&nbsp;<span class="fa fas fa-sort-alpha-down sort-icon"></span></div></th></tr>
                <tr id="table_filters_f1a3eca848e960bd">
<td   class="" ><input type="text" class="form-control form-control-sm input-xs filter  " id="filter_name"    ></td>
</tr>

                
            </thead>
            <tbody>
                
                <tr title="" class="data-row" ><td data-name="name" data-type="text" data-raw_value="Anna"  class="data-col table_edit_field_trigger"  ><span class="table_edit_display field_name">Anna</span></td>
</tr>

                
            </tbody>
            <tfoot>
                
                
            </tfoot>
        </table>
```

Note that `SWITCH` and `CHECKBOX` have no `BY_FIELD` early exit, so those two render their
checkbox in both modes.

`$editKey` picks which row key supplies the value being edited; it defaults to `$dataKey`,
and the renderer assigns that default onto the column object the first time it renders a
row. A closure gets `($column, $rowIndex, $rowItem, $columnCount)`.

The client-side script that consumes `update_field`, `table_edit_field_trigger`,
`data-raw_value`, `parent_checkbox` / `child_checkbox` and the
`data-field_{id}_options` attributes is not part of this package. The markup is a contract
with an application asset that has to be written to match.

## Pagination links

`paginationLinks()` returns `''` when `$pageCount <= 1`, and otherwise a Bootstrap
`<ul class="pagination">` with five kinds of item: first, previous, the page window from
`$pagesFrom` to `$pagesTo`, next, and last. The current page's `<li>` gets `active`; first
and previous get `disabled` on page one, next and last on the final page.

`paginationUrl($url, $page)` is the substitution, `str_replace('%pagination', $page, $url)`.

:::caution[Disabled links still point somewhere]
The `disabled` class is cosmetic. On page one the "previous" anchor is still rendered with
`href` set to `paginationUrl($url, $pagination->prevPage)`, and `prevPage` is `0` there - so
the `%pagination` placeholder is replaced with `0`. Bootstrap's `.page-link` styling on a
`.disabled` item suppresses pointer events; without that css the link is clickable and leads
to a page that does not exist.
:::

See [pagination](/staticphp-core/presentation/pagination/) for how the window is computed.
