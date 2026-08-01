---
title: Filters
description: How a filter string is parsed into filter state, and what FilterType actually is.
sidebar:
    order: 3
---

`Filters` takes one string off the request and turns it into an array of filter values. It
does not build queries and it does not render inputs - the SQL layer reads its output to
make a `WHERE` clause, and the HTML output reads it to prefill the filter row.

## The interface

```php
<?php

interface FiltersInterface
{
    public function __construct(TableInterface &$tableInstance, string $urlPrefix = '', ?string $filterData = null);

    public function url(): ?string;
    public function setUrl(?string $setUrl = null): void;

    public function filterData(): string;

    public function parsedData(): array;
    public function hasFilter(string $key): bool;
    public function filterValue(string $key);

    public function parse(?string $filterData = null, ?\Closure $callback = null): void;
}
```

`Filters` implements it and adds two public methods that are not in the interface:
`setParsedData(string $key, array $value): array` and
`addFilter(string $key, string $query, ?array $params = null, ?array $data = null): void`.

:::note[The interface and the class disagree on one parameter name]
The interface calls the second parameter of `parse()` `$callback`; the class calls it
`$formatter`. Passing it by name works only with `formatter:`. Positional arguments are
unaffected.
:::

You normally do not construct `Filters` yourself.
[`Table::initData()`](/staticphp-core/presentation/tables/#assembling-a-table) does it, and
only when the filter argument is not `null`.

## The wire format

A filter string is `key=value` pairs separated by semicolons, urlencoded:

```text
name=an;state=open;note=x%3By
```

It is parsed by `Table::parseQueryString($filterData, ';')`, which urldecodes both halves of
each pair, so a value containing a semicolon has to arrive as `%3B`. Pairs with no `=` are
dropped. Keys that do not match a column id are kept in the parsed array but ignored by
everything downstream.

### It cannot arrive as a url segment

:::caution[The router rejects a segment containing `=`]
Every url segment has to match
[`Router::isSafeSegment()`](/staticphp-core/core/router/#safety-helpers) -
`/^[a-zA-Z][a-zA-Z0-9_-]*$/` - because the same segments become a file path and a class
name. A filter string does not. `/admin/users/index/name=an/name=asc/1` returns 404 before
the controller is loaded, and percent-encoding does not help: segments are `rawurldecode()`d
before the check, so `name%3Dan` fails identically. A purely numeric page segment fails the
same test.

Bring the string in on the query string instead, and pass it to
[`Table::initData()`](/staticphp-core/presentation/tables/#assembling-a-table) yourself.
:::

That is only half the problem, because `initData()` also builds the urls the output
generator links to, and it builds them by concatenating the filter and sort strings into a
path:

```php
<?php

$this->filter = new Filters($this, "{$this->urlPrefix}%filter/{$sortData}", $filterData);
$this->sort = new Sort($this, "{$this->urlPrefix}{$filterData}/%sort", $sortData);
$this->pagination = new Pagination($this, "{$this->urlPrefix}{$filterData}/{$sortData}/%pagination", $page);
```

So a table given a `$urlPrefix` of `/admin/users/index` emits sort links the router will
404 on. `Filters::setUrl()` and `Sort::setUrl()` can re-point two of the three afterwards;
`Pagination` has no `setUrl()` at all, so the only lever that reaches all three is
`$urlPrefix` itself.

The shape that works is a `$urlPrefix` ending in a query parameter, which turns the three
concatenated fragments into that parameter's value rather than into path segments. The
worked example is on
[tables](/staticphp-core/presentation/tables/#from-script-to-controller). Until the
component learns to build query strings of its own, that is the way in.

## Parsing

`parse()` does three things in order:

1. Splits the string into `['title' => $value, 'value' => $value]` entries.
2. Adds an entry for every column with a `$filterDefaultValue` that the string did not
   already supply. If that default is an array it is used as the entry verbatim, otherwise
   it fills both `title` and `value`.
3. If a formatter closure was given, calls `$formatter($key, $value)` for each entry and
   replaces the entry with what comes back.

The result is `parsedData()`. Every entry is a two key array: `title` is what the user sees
in the input, `value` is what the query binds. They start out identical and diverge when
something rewrites one of them - a `$filterData` closure on the column, or the `data` an SQL
filter returns.

Given the columns `name` (plain), `state` (`filterDefaultValue: 'open'`) and `note`, and the
filter string `name=an;note=x%3By`:

```text
filterData() -> 'name=an;note=x%3By'
parsedData() -> array (
  'name' => 
  array (
    'title' => 'an',
    'value' => 'an',
  ),
  'note' => 
  array (
    'title' => 'x;y',
    'value' => 'x;y',
  ),
  'state' => 
  array (
    'title' => 'open',
    'value' => 'open',
  ),
)
hasFilter('name')  -> true
hasFilter('other') -> false
filterValue('state') -> 'open'
filterValue('other') -> false
```

Two things to read out of that:

- `state` is present although the url never mentioned it, because of its default value.
- `filterValue()` returns `false`, not `null`, for a key that is not there.

### hasFilter() only tests presence

`hasFilter()` is written as `empty($this->filterDataParsed[$key]) === false`, and the thing
it tests is the two key **array**, not the value inside it. An array holding
`['title' => '', 'value' => '']` is not empty, so the method reports `true` for any key that
was parsed, whatever its value:

```text
filter string: 'a=0;b=;c=1'

hasFilter('a') -> true    filterValue('a') -> '0'
hasFilter('b') -> true    filterValue('b') -> ''
hasFilter('c') -> true    filterValue('c') -> '1'
hasFilter('d') -> false   filterValue('d') -> false
```

Read it as "was this filter supplied", not "does this filter have a usable value". For the
latter, test `filterValue($key)` yourself - remembering that it is `false` for a missing key
and `''` for a supplied but empty one.

### Rewriting values as they are parsed

The formatter is how a filter value gets translated before anything else sees it - resolving
a name to an id, say, so the `title` keeps showing the name while the `value` carries the id:

```php
<?php

$table->filter->parse($filterData, function (string $key, array $entry) {
    if ($key !== 'owner') {
        return $entry;
    }

    return [
        'title' => $entry['value'],
        'value' => Db::query('SELECT id FROM users WHERE name = ?', [$entry['value']])->fetchColumn(),
    ];
});
```

Calling `parse()` a second time discards the previous result entirely, including anything
`setParsedData()` had written, so do it before `prepareQueries()` rather than after.

## The url

`url()` returns the prefix with `%filter` appended when the prefix does not already contain
it:

```php
<?php

public function url(): ?string
{
    if ($this->urlPrefix === null) {
        return null;
    }

    return (strpos($this->urlPrefix, '%filter') === false ?
        $this->urlPrefix . '%filter' :
        $this->urlPrefix
    );
}
```

`initData()` always supplies a prefix that already contains the placeholder, so in practice
`url()` returns it unchanged - for a table built with `initData('name=an', 'age=desc', 3)`
and a prefix of `/users/`, that is `/users/%filter/age=desc`. Replace `%filter` with a new
filter string and you have the url that applies it while keeping the current sort.

Nothing in `src/` generates filter links; the filter row is a form and submitting it is the
application's job. `setUrl()` replaces the prefix.

:::note[The null branch in url() cannot be reached]
`$urlPrefix` is declared `protected string $urlPrefix = ''` - not nullable. `setUrl()`
assigns its argument straight into it, so the `?string` in its signature buys nothing: passing
`null` raises a `TypeError` at the assignment, not later.

```text
filter->setUrl(null): TypeError: Cannot assign null to property StaticPHP\Presentation\Models\Tables\Filters::$urlPrefix of type string
sort->setUrl(null):   TypeError: Cannot assign null to property StaticPHP\Presentation\Models\Tables\Sort::$sortUrlPrefix of type string
```

Nothing else writes the property, so `$urlPrefix` is never `null` and the
`if ($this->urlPrefix === null) return null;` guard at the top of `url()` is dead code.
`url()` always returns a string, whatever its `?string` return type suggests.

`Sort::setUrl()` has the same shape - `protected string $sortUrlPrefix = '/'` and a
`?string` parameter assigned straight in - and, as the capture shows, the same `TypeError`.
`Sort::url()` is declared `: string` and has no null branch to be dead.
:::

## Writing to the filter state

`setParsedData($key, $value)` overwrites one entry. The SQL layer uses it to push the `data`
part of a filter result back into the filter state, which is what makes a translated `title`
show up in the rendered input. See
[SQL tables](/staticphp-core/presentation/sql-tables/#preparequeries).

`addFilter()` appends to three protected arrays - `$filterQuery`, `$filterParams` and
`$filterParamsByKey` - and optionally writes an entry into the parsed data. Nothing in
`src/` calls it and nothing reads the first two arrays back; the source carries a
`TODO: Not sure if we need this` above it. The equivalent working path is
`SQLFilters::prepareQueries()`.

## FilterType

`Enums/FilterType.php`, a backed string enum with **11** cases:

| Case | Value |
| --- | --- |
| `TEXT` | `text` |
| `TEXT_EXACT` | `text_exact` |
| `INT8` | `int` |
| `DECIMAL` | `decimal` |
| `BOOLEAN` | `boolean` |
| `DATE` | `date` |
| `DATETIME` | `datetime` |
| `DATEINTERVAL` | `dateinterval` |
| `DATE_NATIVE` | `date_native` |
| `DATETIME_NATIVE` | `datetime_native` |
| `DATEINTERVAL_NATIVE` | `dateinterval_native` |

:::caution[FilterType is not wired to anything]
No property on `Column`, no parameter and no branch anywhere in `src/` or `tests/` is typed
as or compared against `FilterType`. Grep the tree and the only hits are the enum's own
file. Nine of its eleven cases duplicate `ColumnType`, which *is* what the SQL filter layer
switches on, and `$filterFieldType` - the thing that decides what the filter input looks
like - is a [`FieldType`](/staticphp-core/presentation/columns/#fieldtype).

Setting a column up for filtering means setting `$type` and `$filterFieldType`.
`FilterType` looks like an earlier design that the rest of the code moved past. Note the
case named `INT8` whose value is `int`, and the three `DATEINTERVAL*` cases that have no
`ColumnType` counterpart.
:::

## Where the values are used

- The HTML filter row prefills inputs from `parsedData()` through
  `Html::filterInputValue()`, comparing against `value` for selects and checkboxes and
  writing `title` into the `value` attribute of text inputs. When the two differ it also
  emits `data-value="..."`. See
  [the HTML output](/staticphp-core/presentation/output-html/#the-filter-row).
- `SQLFilters::prepareQueries()` walks `parsedData()`, matches each key to a column and
  turns the `value` into a query fragment plus bound parameters. See
  [SQL tables](/staticphp-core/presentation/sql-tables/).
