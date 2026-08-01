---
title: Pagination
description: The pagination model, what calculate() works out, and the page window it produces.
sidebar:
    order: 5
---

`Pagination` is arithmetic and nothing else. It is handed a record count and a current page
and it fills in a set of public integers that the SQL layer turns into `OFFSET`/`LIMIT` and
the HTML layer turns into page links.

## The interface

```php
<?php

interface PaginationInterface
{
    public function __construct(
        TableInterface &$tableInstance,
        string $urlPrefix = '',
        int $currentPage = 1,
        int $limitPerPage = 50,
        int $pagesToShow = 10
    );
    public function url(): string;
    public function calculate(int $recordCount, int $currentPage): void;
}
```

The class widens the second parameter of `calculate()` to `?int $currentPage = null`, so it
can be called with the record count alone, keeping whatever page it already has.

[`Table::initData()`](/staticphp-core/presentation/tables/#assembling-a-table) constructs it
with the page number only, which means `$limitPerPage` and `$pagesToShow` always start at
their defaults of 50 and 10. Change them on the object afterwards:

```php
<?php

$table->initData($filterData, $sortData, (int) $page);
$table->pagination->limitPerPage = 25;
$table->pagination->pagesToShow = 7;
$table->pagination->calculate($total, (int) $page);
```

## The properties

All public, all `int`.

| Property | Set by | Meaning |
| --- | --- | --- |
| `$limitPerPage` | constructor | Rows per page. Default 50 |
| `$pagesToShow` | constructor | Width of the page-number window. Default 10 |
| `$currentPage` | constructor, then `calculate()` | The active page, one-based |
| `$recordCount` | `calculate()` | Total rows matching the filter |
| `$pageCount` | `calculate()` | `ceil($recordCount / $limitPerPage)` |
| `$limitFrom` | `calculate()` | Row offset for the query |
| `$nextPage` | `calculate()` | Next page, or `0` when there is none |
| `$prevPage` | `calculate()` | Previous page, or `0` when there is none |
| `$pagesFrom` | `calculate()` | First page number in the window |
| `$pagesTo` | `calculate()` | Last page number in the window |

`$recordCount` is declared with a default of `10` and `$limitPerPage` with `50`, so an
object that never had `calculate()` called on it reports a nonsensical one-page table rather
than an empty one. Always call `calculate()`.

:::note[nextPage and prevPage are 0, not false]
The source assigns `false` to both when there is no such page. They are typed `int`, so PHP
coerces that to `0`. Test them with a plain falsy check, not `=== false`.
:::

## calculate()

```php
<?php

public function calculate(int $recordCount, ?int $currentPage = null): void
```

It sets `$recordCount`, optionally replaces `$currentPage`, then:

1. `$pageCount = (int) ceil($recordCount / $limitPerPage)`.
2. Clamps `$currentPage` to `1` when it is empty **or greater than `$pageCount`**. An
   out-of-range page silently becomes page one rather than an empty result set.
3. `$limitFrom = ($currentPage - 1) * $limitPerPage`.
4. `$nextPage` and `$prevPage`, or `0` at the ends.
5. The page window, `$pagesFrom` to `$pagesTo`, in one of three branches: near the start,
   near the end, or centred on the current page with `floor($pagesToShow / 2)` pages to the
   left.

613 records, 50 per page, a window of 10:

<!-- captured:pagination -->
```text
page  recordCount pageCount  limitFrom  prevPage  nextPage  pagesFrom  pagesTo
1     613         13         0          0         2         1          10
2     613         13         50         1         3         1          10
3     613         13         100        2         4         1          13
4     613         13         150        3         5         1          13
7     613         13         300        6         8         2          11
12    613         13         550        11        13        4          13
13    613         13         600        12        0         4          13
1     613         13         0          0         2         1          10
```
<!-- /captured:pagination -->

The last row is page 99, which does not exist and so came back as page 1. Page 13 is the
last real page, so `$nextPage` is `0` and the window is pulled back to end at 13.

The window is not a fixed width. Pages 1 and 2 give ten numbers, pages 3 and 4 give
thirteen, page 7 gives ten again. That is the first branch: once
`$currentPage + $pagesToShow >= $pageCount` it sets `$pagesTo = $pageCount` and the window
runs to the end of the table. Render `$pagesFrom` to `$pagesTo` and let it be as wide as it
is; do not size anything on `$pagesToShow`.

## The url

`url()` appends `%pagination` to the prefix when it is not already there:

```php
<?php

return (strpos($this->urlPrefix, '%pagination') === false ?
    $this->urlPrefix . '%pagination' :
    $this->urlPrefix
);
```

`initData()` supplies `"{$urlPrefix}{$filterData}/{$sortData}/%pagination"`, so the
placeholder is always present and the filter and sort segments are carried along. For
`/users/` with `initData('name=an', 'age=desc', 3)` that is
`/users/name=an/age=desc/%pagination`.

`Html::paginationUrl($url, $page)` does the substitution, and
[`Html::paginationLinks()`](/staticphp-core/presentation/output-html/#pagination-links)
builds the Bootstrap `<ul class="pagination">` out of it. It returns an empty string when
`$pageCount <= 1`.

## Feeding the query

`SQLPagination` reads two of the properties and returns a clause:

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

Both values are integers computed by `calculate()`, never anything from the request, so the
concatenation is safe. `OFFSET` before `LIMIT` is PostgreSQL syntax - see
[SQL tables](/staticphp-core/presentation/sql-tables/#the-dialect-is-postgresql).

The order of operations in a controller is the awkward part: the count query has to run
before `calculate()`, and `calculate()` has to run before `limitQuery()` is any use.

```php
<?php

$table->sqlFilter->prepareQueries();
$where = $table->sqlFilter->querySql();
$params = $table->sqlFilter->params();

$total = Db::query("SELECT count(*) FROM users u{$where}", $params)->fetchColumn();
$table->pagination->calculate((int) $total, (int) $page);

$sql = "SELECT * FROM users u{$where}"
    . $table->sqlSort->sortQuery()
    . $table->sqlPagination->limitQuery();
```

Both queries take the same `$params`, because the filter clause is identical in each.
