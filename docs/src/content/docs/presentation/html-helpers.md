---
title: HTML helpers
description: The eight free functions in the presentation helpers file, and how to load them.
sidebar:
    order: 10
---

`src/Presentation/Helpers/Html.php` holds eight plain functions in the global namespace: an
asset queue, a dropdown builder, three escaping functions and two attribute helpers. There
is no class - the file shares a name with
[`Output\Html`](/staticphp-core/presentation/output-html/) and has nothing to do with it.

The file opens with `// TODO: Needs revision`. Read the rest of this page with that in mind.

## Loading them

`composer.json` has no `files` autoload section, so these functions do not exist until
something requires the file. Load it through
[`Load::helper()`](/staticphp-core/core/load/#loading-files), naming the module and the
reserved `staticphp` project:

```php
<?php

use StaticPHP\Core\Models\Load;

Load::helper(['Html'], 'Presentation', 'staticphp');
```

Or from the bootstrap configuration, which is the same call written as a config entry:

```php
<?php

$config['autoload_helpers'][] = 'staticphp/Presentation/Html';
```

Entries are slash separated and read as `project/module/name`, which the bootstrap splits
back into the three arguments of `Load::helper()`. The last segment is the **file** name -
`Html`, not the `Helpers` directory it sits in.

:::caution[Loading twice is a fatal error]
`Load::helper()` uses `require`, not `require_once`, and these are function declarations. A
second load redeclares them and kills the request. Pick one mechanism, not both. The same
warning applies to the
[utility helpers](/staticphp-core/utilities/helpers/#they-are-not-loaded-for-you).
:::

## The functions

```php
<?php

function html_css();
function html_js();
function html_dropdown($items, $selected = null, $addons = null, $add_empty = false, $as_value = null, $as_text = null, $grouped = false);
function html_escape($value);
function html_escape_input($value);
function html_escape_textarea($value);
function html_set_selected(&$current, $needle);
function html_set_checked(&$current, $needle);
```

## The asset queue

`html_css()` and `html_js()` are the same function over different markup. Called with
arguments they append to a `static` array; called with none they echo the queue.

```php
<?php

// In a controller or a partial:
html_css('/css/app.css');
html_css('i:body { margin: 0 }');

html_js('/js/app.js');
html_js('i:console.info("ready");');

// In the layout:
html_css();
html_js();
```

<!-- captured:helpers-assets -->
```text
<link rel="stylesheet" type="text/css" href="/css/app.css" />
<style>body { margin: 0 }</style><script type="text/javascript" src="/js/app.js"></script>
<script type="text/javascript">console.info("ready");</script>
```
<!-- /captured:helpers-assets -->

A value prefixed with `i:` is emitted inline - `<style>` or
`<script type="text/javascript">` - with the prefix stripped. Everything else becomes a
`<link rel="stylesheet">` or a `<script src>`.

Neither the paths nor the inline bodies are escaped or validated. They are markup you are
writing, not data.

### Versioning

If the constant `HTML_CSS_VERSION` or `HTML_JS_VERSION` is defined, its value is appended to
every non-inline url with a `?` or, when the url already has a query string, a `&`. The
constant holds the whole query fragment, not just a number:

```php
<?php

define('HTML_CSS_VERSION', 'v=3');
define('HTML_JS_VERSION', 'v=3');
```

<!-- captured:helpers-versioned -->
```text
<link rel="stylesheet" type="text/css" href="/css/app.css?v=3" />
<link rel="stylesheet" type="text/css" href="/css/print.css?media=print&v=3" />
-- second call with no arguments:
<link rel="stylesheet" type="text/css" href="/css/app.css?v=3" />
<link rel="stylesheet" type="text/css" href="/css/print.css?media=print&v=3" />
<script type="text/javascript" src="/js/app.js?v=3"></script>
```
<!-- /captured:helpers-versioned -->

:::caution[The queue is never emptied]
Outputting does not reset the static array, as the second no-argument call above shows.
Calling `html_css()` twice in a layout emits every stylesheet twice. Call it once per
request per function.
:::

## html_dropdown()

Builds a `<select>` from an array. Every value and every label goes through `html_escape()`;
the `$addons` strings do not.

| Parameter | Purpose |
| --- | --- |
| `$items` | `[value => label]`. A label that is itself an array becomes an `<optgroup>` |
| `$selected` | The selected value, or an array of them |
| `$addons` | `[value => 'attributes']`, plus the special key `'#'` for the `<select>` tag |
| `$add_empty` | A one entry array, `[value => label]`, prepended as the first option |
| `$as_value` | Property name to read the value from when `$items` holds objects |
| `$as_text` | Property name to read the label from |
| `$grouped` | Internal - set on the recursive call, omits the `<select>` wrapper |

```php
<?php

echo html_dropdown(['lv' => 'Latvia', 'ee' => 'Estonia'], 'ee');

echo html_dropdown(
    ['lv' => 'Latvia', 'ee' => 'Estonia'],
    null,
    ['#' => 'name="country" id="country"'],
    ['' => 'Choose one']
);

echo html_dropdown(
    ['Baltics' => ['lv' => 'Latvia', 'ee' => 'Estonia'], 'Nordics' => ['fi' => 'Finland']],
    'fi'
);

echo html_dropdown(['<img src=x onerror=alert(1)>' => '<script>alert(1)</script>']);
```

<!-- captured:helpers-dropdown -->
```text
<select><option value="lv">Latvia</option><option value="ee" selected="selected">Estonia</option></select>

<select name="country" id="country"><option value="" selected="selected">Choose one</option><option value="lv">Latvia</option><option value="ee">Estonia</option></select>

<select><optgroup label="Baltics"><option value="lv">Latvia</option><option value="ee">Estonia</option></optgroup><optgroup label="Nordics"><option value="fi" selected="selected">Finland</option></optgroup></select>

<select><option value="&lt;img src=x onerror=alert(1)&gt;">&lt;script&gt;alert(1)&lt;/script&gt;</option></select>
```
<!-- /captured:helpers-dropdown -->

Four things worth noticing in that output:

- Grouped options nest by recursion, and the group key is the `<optgroup label>`. Note that
  `$add_empty` is passed as `false` on the recursive call, so the empty option is never
  repeated inside a group.
- The selection test is `is_array($selected) && in_array($value, $selected) || $selected == $value`,
  a **loose** comparison. In the second example `$selected` is `null` and the empty option's
  value is `''`, and `null == ''` is true - which is why "Choose one" came back selected.
  That is usually what you want for an empty default. The same looseness makes `'1'` match
  an option keyed `'01'`, since PHP compares two numeric strings numerically.
- `$as_value` and `$as_text` read *object properties* (`$text->{$as_value}`), not array
  keys. An array of arrays needs converting first.
- Both the key and the label are escaped, so hostile data in either is inert.

## Escaping

```php
<?php

function html_escape($value)
{
    if (is_array($value) || is_object($value)) {
        $value = '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

`ENT_QUOTES` covers both quote characters, so one function is correct for html text and for
either kind of quoted attribute. `ENT_SUBSTITUTE` replaces invalid UTF-8 rather than
returning an empty string.

`html_escape_input()` and `html_escape_textarea()` are one-line aliases that call it. They
used to escape less, and the source keeps the note explaining why that was wrong:

> Escaping only the double quote left `<`, `'` and `&` through, which is unsafe in every
> context except a double quoted attribute - `html_escape` covers all of them.

Keep using whichever name reads better at the call site; they behave identically.

## Attribute helpers

`html_set_selected()` and `html_set_checked()` return the attribute string or `null`, ready
to interpolate. Both take the current value **by reference** - so it has to be a variable,
not an expression - and both accept an array, matching either a key or a value in it.

<!-- captured:helpers-escape -->
```text
html_escape('<a href="x">&</a>')        -> '&lt;a href=&quot;x&quot;&gt;&amp;&lt;/a&gt;'
html_escape(['a'])                      -> ''
html_escape_input('a"b')                -> 'a&quot;b'
html_escape_textarea('a"b> < />')       -> 'a&quot;b&gt; &lt; /&gt;'
html_set_selected($scalar, 'ee')        -> ' selected="selected"'
html_set_selected($scalar, 'lv')        -> NULL
html_set_selected($array, 'ee')         -> ' selected="selected"'
html_set_selected($keyed, 'ee')         -> ' selected="selected"'
html_set_checked($array, 'lv')          -> ' checked="checked"'
html_set_checked($array, 'fi')          -> NULL
```
<!-- /captured:helpers-escape -->

```php
<?php

$selectedIds = [3, 7];
?>
<option value="3"<?= html_set_selected($selectedIds, 3) ?>>Three</option>
```

The scalar comparison is `==`. PHP 8 made that much safer for strings - `0 == 'abc'` is
false now - but two numeric strings are still compared numerically, so an id of `'1'`
matches an option keyed `'01'`, and `null` still equals `''`.

## Against the table generator

[`Output\Html`](/staticphp-core/presentation/output-html/) does not use any of these
functions - it has its own static `escape()` with the same flags, and its own
`inputValue()`. The two are independent; loading this file is not needed to render a table.
