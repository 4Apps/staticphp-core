---
title: Excel output
description: The Excel output generator, the library it needs, and the caveats that come with it.
sidebar:
    order: 7
---

`StaticPHP\Presentation\Models\Tables\Output\Excel` is the second implementation of
[`OutputInterface`](/staticphp-core/presentation/tables/#the-interfaces). It walks the same
`Table` the [HTML generator](/staticphp-core/presentation/output-html/) does and writes an
`.xlsx` file instead of markup.

## The dependency

The file opens with three imports from a library this package does not ship:

```php
<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
```

**`phpoffice/phpspreadsheet` appears nowhere in `composer.json`** - not in `require`, not in
`require-dev`, and not in `suggest`, which does list `twig/twig`. So it is neither a hard
dependency nor a declared soft one; it is an undeclared import that resolves only if the
application happens to require the library itself.

```json
"require": {
    "php": ">=8.4",
    "ext-intl": "*",
    "ext-mbstring": "*",
    "ext-pdo": "*"
}
```

Nothing else in `src/` references the library, so a stock installation runs perfectly well
without it and only this one class is unusable:

<!-- captured:excel-missing -->
```text
class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet') -> false
```
<!-- /captured:excel-missing -->

To use the class, add the library to your own application:

```bash
composer require phpoffice/phpspreadsheet
```

Its own extension requirements are its to declare; consult its documentation rather than
this page. This package's CI workflow installs `intl, mbstring, xml, zip, bcmath` and the
PDO drivers, none of it on phpspreadsheet's account, since the library is not installed
there either.

:::caution[Nothing on this page was executed]
The captured block above is the only output on this page that was produced by running code,
and all it shows is that the class is absent. The library is not installed in this
repository, so the generator could not be exercised: everything below is read off the source
rather than observed. Verify against a real workbook before relying on it.
:::

## Settings

| Property | Type | Default |
| --- | --- | --- |
| `$filename` | `string` | `''` |
| `$author` | `string` | `''` |
| `$formatter` | `?\Closure` | `null` |

`$filename` is used without the extension - `showOutput()` appends `.xlsx`. `$author` goes
into the workbook properties as both creator and last modified by. `$formatter` is called
with the `Spreadsheet` object after the data is written and before it is saved, which is the
only place to set column widths, styles or freeze panes.

```php
<?php

use StaticPHP\Presentation\Models\Tables\Output\Excel;

$output = new Excel($table);
$output->filename = 'users-2026-08';
$output->author = 'Reporting';
$output->formatter = function ($xls) {
    $sheet = $xls->getActiveSheet();
    $sheet->getStyle('A1:D1')->getFont()->setBold(true);
    $sheet->freezePane('A2');
};

$table->outputGenerator = $output;
$table->showOutput();
```

## makeOutput()

Returns a `Spreadsheet` rather than a string - the signature is `makeOutput(): Spreadsheet`,
which is why `OutputInterface::makeOutput()` has no return type.

It writes to sheet index 0. Row 1 is the header, taken from each column's `$title`; data
starts at row 2. A column is skipped when its `$exportKey` is exactly `false` or its
`$type` is `ColumnType::ROW_NUMBER`.

The value for a cell comes from `$exportKey`, falling back to `$dataKey` when `$exportKey`
is `null`:

```php
<?php

$exportKey = $column->exportKey;
if ($column->exportKey === null) {
    $exportKey = $column->dataKey;
}

$cellValue = is_callable($exportKey) ? $exportKey($column, $rowIndex, $rowItem) : $rowItem[$exportKey];
```

Note what this path does **not** do:

- It ignores `$dataFormatter` entirely. The HTML generator's number and date formatting does
  not apply; raw row values reach the sheet. Format in the closure, or in `$exportKey`.
- It ignores `$showColumn`. A column hidden in the HTML table is still exported.
- `$rowItem[$exportKey]` is a direct array access with no `isset()` guard, so a key that is
  not in the row raises an undefined-key warning and writes an empty cell. Both `$exportKey`
  and `$dataKey` default to `null`, so a column with neither set indexes the row with `null`.

Closure `$exportKey`s get three arguments - `($column, $rowIndex, $rowItem)` - one fewer than
the four the HTML generator passes to `$dataKey`.

## Cell types

Every cell is written with `setValueExplicit()` under a switch on the column type:

```php
<?php

switch ($column->type) {
    case 'int':
    case 'float':
        $cell->setValueExplicit($cellValue ?? 0, DataType::TYPE_NUMERIC);
        break;

    default:
        $cell->setValueExplicit($cellValue ?? '', DataType::TYPE_STRING);
        break;
}
```

`$column->type` is a `ColumnType`, and the two cases are strings. A backed enum is never
loosely equal to its own backing value, let alone to `'float'`, which is not a `ColumnType`
value at all - the values are `int` and `decimal`. `switch` compares with `==`, so both
cases are dead:

<!-- captured:excel-switch -->
```text
ColumnType::INT      == 'int'  -> false
ColumnType::INT      == 'float' -> false
ColumnType::DECIMAL  == 'int'  -> false
ColumnType::DECIMAL  == 'float' -> false
ColumnType::TEXT     == 'int'  -> false
ColumnType::TEXT     == 'float' -> false
```
<!-- /captured:excel-switch -->

The consequence is that **every cell is written as `TYPE_STRING`**, including numeric
columns, so figures arrive in the workbook as text and will not sum. If that matters, cast
in the `$formatter` closure or set the cell types there.

## showOutput()

Builds the spreadsheet, sets the author properties, runs `$formatter`, sends the download
headers and streams the file to `php://output` through the `Xlsx` writer with
`setPreCalculateFormulas(false)`.

The headers sent, in order:

```text
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment;filename="{filename}.xlsx"
Cache-Control: max-age=0
Cache-Control: max-age=1
Expires: Mon, 26 Jul 1997 05:00:00 GMT
Last-Modified: {now} GMT
Cache-Control: cache, must-revalidate
Pragma: public
```

`Cache-Control` is sent three times and `header()` replaces by default, so only
`cache, must-revalidate` survives; the two `max-age` lines are dead. `$filename` is
interpolated into the `Content-Disposition` header unescaped - keep it to values you
generate, never a request parameter, or a newline in it splits the response.

There is no `ob_end_clean()` before the write, so anything already echoed - a stray blank
line after a `?>`, a warning from the undefined-key access above - lands in the file and
corrupts the archive.

## A download route

The generator needs the same table the HTML view uses, minus the pagination, since an export
is normally the whole result set:

```php
<?php

public static function export($filterData = '', $sortData = '')
{
    $table = new SQLTable(self::columns(), '/admin/users/');
    $table->initData($filterData, $sortData, null);
    $table->sqlFilter->prepareQueries();

    $sql = 'SELECT * FROM users u'
        . $table->sqlFilter->querySql()
        . $table->sqlSort->sortQuery();
    $rows = Db::fetchAll($sql, $table->sqlFilter->params());
    $table->setRows($rows);

    $output = new Excel($table);
    $output->filename = 'users';
    $table->outputGenerator = $output;
    $table->showOutput();
}
```

Passing `null` as the third argument of `initData()` skips the `Pagination` and
`SQLPagination` objects, which the Excel generator never touches. It reads the rows through
`getRows()`, so they must have been set.
