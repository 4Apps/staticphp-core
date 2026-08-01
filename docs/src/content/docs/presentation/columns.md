---
title: Columns
description: The Column object, every setting it accepts, and the four enums that configure it.
sidebar:
    order: 2
---

`Column` is the only class in the table subsystem with no behaviour at all. It is a bag of
public properties plus a constructor that validates the names you pass. Everything a table
does with a column - render it, sort by it, filter on it, export it - is decided by reading
those properties back.

## Constructing one

`ColumnInterface` declares a single method, and `Column` is its only implementor:

```php
<?php

interface ColumnInterface
{
    public function __construct(string $id, ...$settings);
}
```

The variadic is meant to be filled with **named arguments**. PHP collects unmatched named
arguments into `$settings` keyed by name, and the constructor assigns each one to the
property of that name:

```php
<?php

public function __construct($id, ...$settings)
{
    $this->id = $id;
    foreach ($settings as $key => $value) {
        if (property_exists($this, $key) == false) {
            throw new \Exception("\"{$key}\" does not exists on Column");
        }

        $this->{$key} = $value;
    }
}
```

So a column reads as a keyword list:

```php
<?php

use StaticPHP\Presentation\Models\Tables\Column;
use StaticPHP\Presentation\Models\Tables\Enums\ColumnType;
use StaticPHP\Presentation\Models\Tables\Enums\FormatterType;

$column = new Column(
    'balance',
    title: 'Balance',
    type: ColumnType::DECIMAL,
    dataKey: 'balance',
    sortBy: 'u.balance',
    filterBy: 'u.balance',
    dataFormatter: FormatterType::DECIMAL2,
);
```

The `property_exists()` check turns a typo into an immediate exception rather than a
setting that silently does nothing:

```text
Exception: "tittle" does not exists on Column
```

There is no `id` property check - the first positional argument is always the id, and it is
also the array key the table stores the column under and the `field_<id>` css class the
renderer emits.

Properties can equally be set after construction; nothing about the constructor is special
beyond the name validation.

## The settings

Many of these accept a `\Closure` as well as a scalar. Where they do, the closure signature
is given, and it is invoked at render time by
[the HTML output](/staticphp-core/presentation/output-html/).

### Identity

| Property | Type | Default |
| --- | --- | --- |
| `$id` | `string` | required |
| `$title` | `string` | `''` |
| `$description` | `string` | `''` |
| `$type` | `ColumnType` | `ColumnType::TEXT` |

`$title` is the header text. `$description`, when non-empty, is appended to the header link
as a `title="..."` attribute together with `class="tooltip-line" data-toggle="tooltip"
data-placement="top"` - Bootstrap tooltip markup. It is interpolated into the attribute
**without escaping**, so it must not carry user input.

### Column visibility

| Property | Type | Default |
| --- | --- | --- |
| `$showColumn` | `bool\|\Closure` | `true` |

Checked separately in the header row, the filter row and every data row. The closure takes
no arguments. Only an exact `false` hides the column.

### Sorting

| Property | Type | Default |
| --- | --- | --- |
| `$sortEnabled` | `bool` | `true` |
| `$sortBy` | `null\|string\|\Closure` | `null` |
| `$sortDefaultColumn` | `bool` | `false` |
| `$sortDefaultDirection` | `SortDirection` | `SortDirection::ASC` |
| `$sortNulls` | `SortNulls` | `SortNulls::LAST` |
| `$sortLinkAttribute` | `?string` | `null` |

`$sortEnabled: false` renders the plain title instead of a link. `$sortLinkAttribute` is
spliced raw into the `<a>` tag. Exactly one column in a table must set
`$sortDefaultColumn: true`. All of this is covered on
[sorting](/staticphp-core/presentation/sorting/).

### Filtering

| Property | Type | Default |
| --- | --- | --- |
| `$filterHidden` | `bool` | `false` |
| `$filterEnabled` | `bool` | `true` |
| `$filterTitle` | `?string` | `null` |
| `$filterDefaultValue` | `?string` | `null` |
| `$filterDateValue` | `?string` | `null` |
| `$filterFieldType` | `FieldType\|\Closure` | `FieldType::TEXT` |
| `$filterInputAttributes` | `array` | `[]` |
| `$filterInputClasses` | `array` | `[]` |
| `$filterSelectOptions` | `?array` | `null` |
| `$filterSelectOptionsIdKey` | `?string` | `null` |
| `$filterSelectOptionsTitleKey` | `?string` | `null` |
| `$filterSelectOptionsGroups` | `?array` | `null` |
| `$filterSelectOptionsGroupTitleKey` | `?string` | `null` |
| `$filterSelectMultiple` | `bool` | `false` |
| `$filterSelectSkipEmptyDefault` | `bool` | `false` |
| `$filterSelectDefaultDisabled` | `bool` | `false` |
| `$filterBy` | `null\|string\|\Closure` | `null` |
| `$filterZeroIsNULL` | `bool` | `false` |
| `$filterData` | `null\|array\|\Closure` | `null` |
| `$filterSqlDate` | `bool` | `false` |

Two of these do not do what their names suggest:

- `$filterHidden: true` renders an **empty** filter cell. `$filterEnabled: false` still
  renders the input, with `disabled="disabled"` on it.
- `$filterSelectMultiple` is declared here and read nowhere in `src/`. The multiple-select
  behaviour comes from `$filterFieldType: FieldType::SELECT_MULTIPLE`, which is what adds
  `multiple="multiple" size="3"`.

`$filterInputAttributes` and `$filterInputClasses` are arrays whose elements may be strings
or closures taking `($column, $value)`. Their results are imploded with a space.

The `$filterSelect*` group and `$filterTitle` shape the rendered `<select>`; the rest are
read by the SQL layer. See [filters](/staticphp-core/presentation/filters/) and
[SQL tables](/staticphp-core/presentation/sql-tables/).

### Data

| Property | Type | Default |
| --- | --- | --- |
| `$initValue` | `null\|\Closure` | `null` |
| `$idKey` | `null\|string\|\Closure` | `null` |
| `$dataKey` | `null\|string\|\Closure` | `null` |
| `$dataFormatter` | `FormatterType` | `FormatterType::TEXT` |
| `$dataColumnAttributes` | `array\|\Closure` | `[]` |
| `$dataColumnClasses` | `array\|\Closure` | `[]` |
| `$dataColumnPrefix` | `array\|\Closure` | `[]` |
| `$dataColumnAddon` | `array\|\Closure` | `[]` |
| `$dataColumnBage` | `null\|string\|\Closure` | `null` |

`$dataKey` names the key to read out of the row array. As a closure it takes
`($column, $rowIndex, $rowItem, $columnCount)` and returns the value, which is how a
computed column is written:

```php
<?php

new Column(
    'full_name',
    title: 'Name',
    dataKey: fn($column, $rowIndex, $row) => trim("{$row['first_name']} {$row['last_name']}"),
    sortBy: 'u.last_name',
);
```

`$initValue` does the same job one stage earlier: `Table::setRows()` calls it with
`($column, $rowIndex, $row)` and writes the result back into the row under the column id,
so the value is materialised into the data rather than computed per cell.

`$dataColumnPrefix` and `$dataColumnAddon` are concatenated before and after the cell value
inside the `<td>`, with **no escaping** - they exist to wrap the value in markup.
`$dataColumnBage` (spelled that way in the source) appends
`<span class="badge bg-{value}">` and `</span>` to that pair, so its value is a Bootstrap
colour suffix such as `success` or `danger`.

### Editing

| Property | Type | Default |
| --- | --- | --- |
| `$isEditable` | `bool\|\Closure` | `false` |
| `$editKey` | `null\|string\|\Closure` | `null` |
| `$editFieldType` | `FieldType` | `FieldType::TEXT` |
| `$editSelectOptions` | `?array` | `null` |
| `$editSelectOptionsGroupped` | `bool` | `false` |
| `$switchValue` | untyped | `1` |

A cell is editable only when the column's `$isEditable` **and** the table's `$isEditable`
are both truthy. `$editKey` defaults to `$dataKey` when it is left null - the renderer
assigns it on first use. See
[the HTML output](/staticphp-core/presentation/output-html/#editable-cells).

### Export

| Property | Type | Default |
| --- | --- | --- |
| `$exportKey` | `bool\|null\|string\|\Closure` | `null` |

Only read by the Excel generator. `false` excludes the column from the sheet, `null` falls
back to `$dataKey`, a string names a row key and a closure takes
`($column, $rowIndex, $rowItem)`. See
[Excel output](/staticphp-core/presentation/output-excel/).

### Presentation

| Property | Type | Default |
| --- | --- | --- |
| `$columnAttributes` | `array` | `[]` |
| `$columnClasses` | `array` | `[]` |
| `$expandableText` | `bool` | `false` |
| `$escapeDataHtml` | `bool` | `true` |

`$columnAttributes` and `$columnClasses` are applied to the `<th>` in the header row and
the `<td>` in the filter row - not to data cells, which use the `$dataColumn*` pair.
Elements may be closures taking `($column)`.

`$expandableText: true` adds a `text-cell` class and wraps the value in
`<div class="truncated-text">` plus a `<div class="expand-switch">Expand</div>` toggle. It
is refused on an editable column:

```text
Exception: Expandable text is not supported for editable columns
```

`$escapeDataHtml` is the one setting to be careful with. It is `true` by default and the
source says why:

> Escaping is on by default. Only turn it off for a column whose data is trusted markup
> produced by the application itself - never for anything reaching the table from a request
> or from user editable database content.

Turning it off, on two columns fed the same `<b>bold</b>` value:

<!-- captured:escaping -->
```text
<td  class="data-col field_raw"  >&lt;b&gt;bold&lt;/b&gt;</td>
<td  class="data-col field_trusted"  ><b>bold</b></td>
```
<!-- /captured:escaping -->

## ColumnType

`Enums/ColumnType.php`, a backed string enum with **11** cases. It describes how the value
is stored in the data source, and it is what the SQL filter layer switches on to decide how
to build a `WHERE` clause.

| Case | Value | Effect |
| --- | --- | --- |
| `TEXT` | `text` | SQL filter uses `ILIKE '%value%'` |
| `TEXT_EXACT` | `text_exact` | SQL filter uses `=` |
| `INT` | `int` | Values cast with `(int)` |
| `DECIMAL` | `decimal` | Comma swapped for a dot, values cast with `(float)` |
| `BOOLEAN` | `boolean` | `true`/`false` strings recognised, then cast with `(int)` |
| `DATE` | `date` | Parsed as a date, converted with `strtotime()` |
| `DATETIME` | `datetime` | Parsed as a date and time, converted with `strtotime()` |
| `DATE_NATIVE` | `date_native` | Parsed as a date, left as a `Y-m-d H:i:s` string |
| `DATETIME_NATIVE` | `datetime_native` | Parsed as a date and time, left as a string |
| `ROW_NUMBER` | `row-number` | Cell shows the one-based row position |
| `SELECT_ALL_CHECKBOX` | `select-all-checkboxes` | Cell shows a per-row checkbox |

The `_NATIVE` pair differs from its plain counterpart only in what reaches the query: the
plain cases run the parsed value through `strtotime()` and bind an integer unix timestamp,
the `_NATIVE` cases bind the string. Which you want depends on the column type in the
database. `$filterSqlDate: true` suppresses the `strtotime()` call for the plain cases too.

The last two are rendering instructions rather than storage types. `SQLFilters::runFilter()`
returns `null` for both, so neither ever contributes to a `WHERE` clause, and the Excel
generator skips `ROW_NUMBER` columns entirely.

## FieldType

`Enums/FieldType.php`, a backed string enum with **14** cases. It describes a form control,
and it is used in two different places: `$filterFieldType` for the filter row and
`$editFieldType` for editable data cells.

| Case | Value | As a filter | As an edit field |
| --- | --- | --- | --- |
| `TEXT` | `text` | `<input type="text">` | `<input type="text">` |
| `INT` | `int` | `<input type="number">` | `<input type="number">` |
| `DECIMAL` | `decimal` | `<input type="number">` | `<input type="number">` |
| `DATE` | `date` | text input, `datepicker-trigger` class | text input |
| `DATETIME` | `datetime` | text input, `datetimepicker-trigger` class | text input |
| `DATEINTERVAL` | `dateinterval` | text input, `dateintervalpicker-trigger` class | text input |
| `MULTILINE_TEXT` | `multiline-text` | throws | `<textarea rows="2">` |
| `SWITCH` | `switch` | select of No/Yes | checkbox in `form-check form-switch` |
| `CHECKBOX` | `checkbox` | select of No/Yes | checkbox in `form-check` |
| `SELECT` | `select` | `<select>` from `$filterSelectOptions` | `<select>` from `$editSelectOptions` |
| `SELECT_NO_YES` | `select_no_yes` | select of No/Yes | select of No/Yes |
| `SELECT_MULTIPLE` | `select-multiple` | `<select multiple size="3">` | `<select multiple size="3">` |
| `ROW_NUMBER` | `row-number` | throws | text input |
| `SELECT_ALL_CHECKBOX` | `select-all-checkboxes` | header checkbox `parent_checkbox` | text input |

Two of them raise an exception in the filter row rather than degrade:

```text
Exception: Multiline text is not supported for filter
Exception: Row number is not supported for filter
```

The "as an edit field" column is what the `default:` branch of the edit switch produces -
only `SWITCH`, `CHECKBOX`, the three `SELECT*` cases and `MULTILINE_TEXT` have branches of
their own, so the remaining cases all fall through to a text or number input.

:::caution[The datepicker class is never added to an edit field]
The edit branch tests `in_array($column->type, [FieldType::DATE, FieldType::DATEINTERVAL,
FieldType::DATETIME])` - that is `$type`, a `ColumnType`, compared against `FieldType`
cases. Two different enums are never equal, so the `datepicker-trigger` class is never
added to an editable cell. Documented as the code behaves, not as the code reads.
:::

## FormatterType

`Enums/FormatterType.php`, a backed string enum with **10** cases. `$dataFormatter` picks
one; `Html::formatData()` applies it to the cell value before escaping.

Real output, from a run against `Html::formatData()`:

<!-- captured:formatters -->
```text
TEXT      '1234.5678'                      -> '1234.5678'
INT       '1234.5678'                      -> '1235'
DECIMAL   '1234.5678'                      -> '1234.57'
DECIMAL1  '1234.5678'                      -> '1234.6'
DECIMAL2  '1234.5678'                      -> '1234.57'
DECIMAL3  '1234.5678'                      -> '1234.568'
DECIMAL4  '1234.5678'                      -> '1234.5678'
BOOLEAN   1                                -> 'Yes'
DATE      DateTime(2026-08-01 13:45:00)    -> '2026-08-01'
DATETIME  DateTime(2026-08-01 13:45:00)    -> '2026-08-01 13:45:00'
closure   'ab'                             -> 'AB'
```
<!-- /captured:formatters -->

| Case | Value | Behaviour |
| --- | --- | --- |
| `TEXT` | `text` | String interpolation, no change |
| `INT` | `int` | Locale number format, 0 decimals |
| `DECIMAL` | `decimal` | Locale number format, 2 decimals, then `+ 0` |
| `DECIMAL1` | `decimal1` | Locale number format, 1 decimal |
| `DECIMAL2` | `decimal2` | Locale number format, 2 decimals |
| `DECIMAL3` | `decimal3` | Locale number format, 3 decimals |
| `DECIMAL4` | `decimal4` | Locale number format, 4 decimals |
| `BOOLEAN` | `boolean` | `Yes` when loosely equal to `1`, otherwise `No` |
| `DATE` | `date` | `ExtendedDateTime::formatDate()`, or `Y-m-d` for a plain `DateTime` |
| `DATETIME` | `datetime` | `ExtendedDateTime::formatDateTime()`, or `Y-m-d H:i:s` |

`$dataFormatter` is typed `FormatterType`, so those ten are the only values a column can
carry. `formatData()` itself is looser - it returns the value unchanged for `null` and calls
any callable as `$formatter($data)` - but that is only reachable by calling the method
directly, not through a column.

The two date cases only reformat `DateTime` instances. A date that arrives from the
database as a string passes through untouched. `ExtendedDateTime` is covered under
[dates](/staticphp-core/utilities/dates/).

:::danger[FormatterType::DECIMAL breaks when the locale groups thousands]
`DECIMAL` is the only case that post-processes the formatted string:
`return $this->localeNumberFormat((float)$data) + 0;`. Adding `0` to a string that contains
a thousands separator makes PHP parse only the leading digits. Captured with `LC_NUMERIC`
set to `lv_LV.UTF-8`:

<!-- captured:decimal-locale -->
```text
setlocale(LC_NUMERIC, 'lv_LV.UTF-8') -> 'lv_LV.UTF-8'
localeconv()['thousands_sep']        -> ' '
localeNumberFormat(1234.5678, 2)     -> '1 234,57'
                                        Warning: A non-numeric value encountered
formatData('1234.5678', DECIMAL)     -> '1'
formatData('1234.5678', DECIMAL2)    -> '1 234,57'
```
<!-- /captured:decimal-locale -->

`DECIMAL2` produces the same number of decimals with none of this. Prefer it.
:::

### Which locale

`localeNumberFormat()` prefers the active i18n locale and only falls back to
`localeconv()`:

```php
<?php

public function localeNumberFormat($number, $decimals = 2)
{
    if (\StaticPHP\Utils\Models\i18n::isInitialised() === true) {
        return \StaticPHP\Utils\Models\i18n::number($number ?? 0, $decimals);
    }

    $locale = localeconv();
    return number_format($number ?? 0, $decimals, $locale['decimal_point'], $locale['thousands_sep']);
}
```

Nothing in the framework sets `LC_NUMERIC`, so on a request that has not called
[`i18n::init()`](/staticphp-core/i18n/overview/) the fallback runs under the C locale: a dot
for the decimal point and no thousands separator. The captured formatter output above was
produced that way.

## RowPosition

`Enums/RowPosition.php`, a backed string enum with **6** cases. It says where one of the
table's three meta rows is rendered.

| Case | Value | Rendered |
| --- | --- | --- |
| `HEAD_TOP` | `head_top` | First row of `<thead>`, above the titles |
| `HEAD_BOTTOM` | `head_bottom` | Last row of `<thead>`, below the filters |
| `BODY_TOP` | `body_top` | First row of `<tbody>` |
| `BODY_BOTTOM` | `body_bottom` | Last row of `<tbody>` |
| `FOOT_TOP` | `foot_top` | First row of `<tfoot>` |
| `FOOT_BOTTOM` | `foot_bottom` | Last row of `<tfoot>` |

Each of `$avgRow`, `$sumRow` and `$customRow` on the table has its own position property,
all three defaulting to `BODY_TOP`. When several land on the same position they are
emitted in that order: average, sum, custom.

```php
<?php

use StaticPHP\Presentation\Models\Tables\Enums\RowPosition;

$table->sumRow = ['name' => 'Total', 'balance' => '1192.5'];
$table->sumRowPosition = RowPosition::FOOT_TOP;
```

Meta rows are rendered by `htmlDataRow()` with a negative row index (`-1` for average, `-2`
for sum, `-3` for custom), which is what makes their cells plain `<td>` elements with no
classes, attributes or edit controls. They still go through each column's formatter. There
is a worked example on [tables](/staticphp-core/presentation/tables/#a-complete-worked-example).
