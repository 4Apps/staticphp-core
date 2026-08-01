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

The file opens with three imports from a library the package does not require:

```php
<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
```

`phpoffice/phpspreadsheet` is declared in `composer.json`, but twice over and in neither
place as a hard dependency - once in `require-dev`, once in `suggest`:

```json
"require-dev": {
    "phpoffice/phpspreadsheet": "^3.0 || ^4.0",
    "phpunit/phpunit": "^13.0",
    "squizlabs/php_codesniffer": "^4.0",
    "twig/twig": "^3.0"
},
"suggest": {
    "phpoffice/phpspreadsheet": "Required by Presentation\\Models\\Tables\\Output\\Excel. Nothing else in the package touches it, so a table that only renders html does not need it."
}
```

What each of those means for an application that depends on this package:

-   `require-dev` is the package's own development environment. Composer ignores a
    dependency's `require-dev`, so requiring `4apps/staticphp-core` never pulls
    phpspreadsheet in. It is declared there so this class can be exercised by the package's
    own test suite - `tests/Presentation/Models/Tables/ExcelOutputTest.php` - and so CI
    installs it.
-   `suggest` is advisory. Composer prints it after an install and `composer suggest` lists
    it; it installs nothing and constrains nothing.

`require` is untouched by either, and is still php plus three extensions:

```json
"require": {
    "php": ">=8.4 <9",
    "ext-intl": "*",
    "ext-mbstring": "*",
    "ext-pdo": "*"
}
```

So an application that wants an `.xlsx` export requires the library itself:

```bash
composer require phpoffice/phpspreadsheet
```

Nothing else in `src/` references it, so an installation without it runs perfectly well and
only this one class is unusable - the failure is a fatal "class not found" the moment
`makeOutput()` is reached, not a degraded fallback.

Its own extension requirements are its to declare; consult its documentation rather than
this page. This package's CI workflow installs `intl, mbstring, xml, zip, gd, bcmath` and
the PDO drivers, and `gd` is there on phpspreadsheet's account so that the Excel output test
can run.

:::note[What on this page was executed]
The library is installed in this repository as a dev dependency, so the captured block under
[Cell types](#cell-types) is a real `makeOutput()` run. The download path below - the
headers and the write to `php://output` - is read off the source rather than observed, since
it only makes sense inside a request.
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
    case ColumnType::INT:
    case ColumnType::DECIMAL:
        $cell->setValueExplicit($cellValue ?? 0, DataType::TYPE_NUMERIC);
        break;

    default:
        $cell->setValueExplicit($cellValue ?? '', DataType::TYPE_STRING);
        break;
}
```

The cases are `ColumnType` cases rather than their backing strings, and that matters:
`switch` compares with `==`, and a backed enum is never loosely equal to its own value, so
`case 'int':` would match nothing and send every number to the string branch. The two
numeric types are `INT` and `DECIMAL` - there is no `FLOAT` case on the enum.

Everything else - text, dates, booleans, and every other
[`ColumnType`](/staticphp-core/presentation/columns/#columntype) - is written as
`TYPE_STRING`. A `null` becomes `0` in a numeric column and `''` everywhere else.

A real run over three columns and two rows, the second one carrying `null` in both numeric
cells and an ordinary string in the text cell:

```text
phpoffice/phpspreadsheet 3.10.7

cell   column type  cell data type         value
A1     header       DataType::TYPE_STRING  'Name'
B1     header       DataType::TYPE_STRING  'Qty'
C1     header       DataType::TYPE_STRING  'Total'
A2     TEXT         DataType::TYPE_STRING  'Widget'
B2     INT          DataType::TYPE_NUMERIC 3
C2     DECIMAL      DataType::TYPE_NUMERIC 10.5
A3     TEXT         DataType::TYPE_STRING  'Gadget'
B3     INT          DataType::TYPE_NUMERIC 0
C3     DECIMAL      DataType::TYPE_NUMERIC 0
```

Row 1 is the header, written with `setValue()` rather than `setValueExplicit()`, so
phpspreadsheet infers its type instead of being told one.

`testNumericColumnsAreWrittenAsNumbersRatherThanText()` in
`tests/Presentation/Models/Tables/ExcelOutputTest.php` is the regression test for the
numeric branch, so a workbook whose figures arrive as unsummable text is a test failure
rather than something to discover in a spreadsheet.

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
