---
title: Validation
description: Fv, the form validator - every rule it actually implements, its filters and its form helpers.
sidebar:
    order: 4
---

`StaticPHP\Utils\Models\Fv` validates an array of submitted values against a set of rules,
filters those values in place, and helps repopulate the form afterwards. The rules are not a
generic expression language: they are a fixed list of static methods on the class, listed in
full below.

:::caution[The class docblock describes an API that does not exist]
The comment at the top of `Fv.php` shows `Fv::init($_POST)`, `Fv::addRules(...)`,
`Fv::$errors_all` and `Fv::getError(...)`. There is no `init()` method, and `addRules()`,
`validate()`, `errors`, `errors_all`, `getError()` and `setInputValue()` are all instance
members. The code is what is documented here; the docblock is stale.
:::

## Using it

```php
<?php

use StaticPHP\Utils\Models\Fv;

$fv = new Fv($_POST);

$fv->addRules([
    'email' => [
        'title' => 'E-mail',
        'valid' => ['required', 'email'],
    ],
    'age' => [
        'valid' => ['integer', 'length[1,3]'],
    ],
]);

if ($fv->validate() === false) {
    foreach ($fv->errors_all as $message) {
        // ...
    }
}
```

The constructor takes any number of arrays and `array_merge`s all of them into
`$fv->post`, so `new Fv($_POST, $_FILES)` is a single input set. Non-array arguments are
skipped.

`addRules()` merges into whatever was there, so it can be called more than once - but at the
top level only. Two calls naming the same field replace that field's whole rule array.

`validate()` walks the rules, not the input. A field with rules but no key in the input gets
a `missing` error and is neither filtered nor validated. A field in the input with no rules
is left alone. It returns `empty($this->errors)`.

The two halves it calls per field are public in their own right:

```php
<?php

public function filterField(string $name): void;
public function validateField(string $name): void;
```

`filterField()` runs that field's `filter` list and writes the result back to
`$fv->post[$name]`; `validateField()` runs its `valid` list and calls `setError()` for each
rule that returns `false`. Both return nothing and both no-op when the field has no list of
that kind. Call them directly to filter a field before deciding whether to validate it at
all, or to re-validate one field after changing it.

Two things they do not do, because `validate()` does them: neither records a `missing`
error, and neither checks that the key is in the input at all - called for a field that is
not there, they read `$fv->post[$name]` anyway and php warns about the undefined key. Each
also stops at the first empty entry in its list rather than skipping it, so a padded rule
array silently drops everything after the gap.

### The rule array

| Key       | Meaning                                                          |
| --------- | ---------------------------------------------------------------- |
| `valid`   | list of validation rules, applied in order after the filters      |
| `filter`  | list of filters, applied in order, each rewriting `$fv->post[$field]` |
| `title`   | what `!name` expands to in an error message; the field name otherwise |
| `errors`  | per-field overrides for the default messages, keyed by rule name  |

A rule or filter entry is a string naming something callable, or a callable itself.
`callFunc()` resolves a string in three steps and takes the first that matches: anything
`is_callable()` accepts, then a method of `Fv`, then a global function. Since
`is_callable('trim')` is true for any global function, a global function always wins over an
`Fv` method of the same name. An entry that matches none of the three is silently skipped -
`callFunc()` returns `null`, which is not `false`, so no error is recorded. A typo in a rule
name means that rule does not run.

Arguments go in square brackets and are split on commas. A literal comma is written
`&#44;`:

```php
<?php

'valid' => ['length[3,10]', 'equal[yes]', 'uploadExt[pdf png jpg]'],
```

Only entries that are not already callable are scanned for brackets, and the pattern is
`/(\w+)\[(.*)\]/` - greedy, and matched anywhere in the string rather than anchored.

:::caution[A php builtin as a filter is a fatal error]
Every rule and filter is called as `(value, ...bracket args, fieldName, wholePostArray)`.
Extra arguments are harmless for `Fv`'s own methods and for your own closures, because php
ignores surplus arguments to userland functions - but a builtin with a fixed arity rejects
them. The class docblock's own example, `'filter' => ['trim']`, is a fatal error:

```text

Fatal error: Uncaught ArgumentCountError: trim() expects at most 2 arguments, 3 given in /srv/app/src/Utils/Models/Fv.php:270
Stack trace:
#0 [internal function]: trim()
#1 /srv/app/src/Utils/Models/Fv.php(270): call_user_func_array()
#2 /srv/app/src/Utils/Models/Fv.php(167): StaticPHP\Utils\Models\Fv->callFunc()
#3 /srv/app/src/Utils/Models/Fv.php(132): StaticPHP\Utils\Models\Fv->filterField()
#4 /srv/scratch/fvtrim.php(7): StaticPHP\Utils\Models\Fv->validate()
#5 {main}
  thrown in /srv/app/src/Utils/Models/Fv.php on line 270
```

Wrap the builtin in a closure instead: `'filter' => [fn ($v) => trim($v)]`.
:::

### Errors

Errors accumulate in two places at once, by reference, so a message written once appears in
both: `$fv->errors[$field][]` and `$fv->errors_all[]`. `errors_all` is a flat list of
strings with no field names in it.

`getError($name)` returns that field's array of messages, or `false` when there are none -
so `if (($e = $fv->getError('email')) !== false)`. `hasError($name)` returns a bool.

Messages come from `$rules[$field]['errors'][$rule]`, falling back to the class defaults,
falling back to `''`. `!name` expands to the field's `title` or its name, and `!value` to
the rejected value; both are run through `htmlspecialchars()` with
`ENT_QUOTES | ENT_SUBSTITUTE` on the way in, because these strings get echoed into pages.
An array or object value expands `!value` to an empty string.

```text
validate()                 => false
post['name'] after filter  => 'Jo'
hasError('age')            => true
getError('name')           => array (
                                0 => 'Field "Name" has not correct length',
                              )
getError('email')          => array (
                                0 => 'Field "email" is missing',
                              )
getError('nothing')        => false
errors_all                 => array (
                                0 => 'Field "Name" has not correct length',
                                1 => 'Field "age" must be integer',
                                2 => 'Field "code" has not correct length',
                                3 => 'Field "email" is missing',
                              )
```

The default messages, verbatim from the source:

| Rule             | Default message                                                    |
| ---------------- | ------------------------------------------------------------------ |
| `missing`        | `Field "!name" is missing`                                          |
| `required`       | `Field "!name" is required`                                         |
| `email`          | `"!value" is not a correct e-mail address`                          |
| `date`           | `"!value" is not a correct date format`                             |
| `ipv4`           | `"!value" is not a correct ipv4 address`                            |
| `ipv6`           | `"!value" is not a correct ipv6 address`                            |
| `creditCard`     | `"!value" is not a correct credit card number`                      |
| `length`         | `Field "!name" has not correct length`                              |
| `equal`          | `Field "!name" has wrong value`                                     |
| `format`         | `Field "!name" has not a correct format`                            |
| `integer`        | `Field "!name" must be integer`                                     |
| `float`          | `Field "!name" must be float number`                                |
| `string`         | ``Field "!name" can contain only letters, []$/!.?()-'" and space chars`` |
| `uploadRequired` | `Field "!name" is required`                                         |
| `uploadSize`     | `Uploaded file is to large`                                         |
| `uploadExt`      | `File type is not allowed`                                          |

`missing` has no rule behind it - it is what `validate()` records for a field that is not in
the input at all. Calling `$fv->errors([...])` merges replacements into this table for the
whole instance. Note that `errors` is both a public property and a method on this class:
`$fv->errors` reads the accumulated per-field messages, `$fv->errors(...)` overrides the
default message table.

### Writing an error yourself

`setError()` is the write side of the same pair, and it is public:

```php
<?php

public function setError(mixed $type, string $name, mixed $value = ''): void;
```

`validateField()` calls it for every rule that returns `false`, and an application calls it
for the checks a rule cannot express - a uniqueness lookup, a cross-field comparison, a
rejection the api came back with. The message is resolved exactly as it is for a builtin:
`$rules[$name]['errors'][$type]`, then the class defaults, then `''`. `$value` fills
`!value` and is escaped here, so a rejected value straight out of the request is safe to
pass. A `$type` that is not a string is read as `default`.

The catch is the last fallback. There is no default message for a type you invented, so
`setError('taken', ...)` with nothing declared for `taken` records an empty string - the
field counts as having an error and prints as nothing:

```text
validate()                         => true
hasError('email') after setError   => true
getError('email')                  => array ( 0 => '', )
getError('email') with a message   => array ( 0 => '"taken@example.com" is already registered to someone else', )
errors_all                         => array ( 0 => '"taken@example.com" is already registered to someone else', )
!value is escaped                  => array ( 0 => 'name / &lt;b&gt;x&lt;/b&gt;', )
non-string type                    => array ( 0 => '', )
```

So declare the message alongside the rules, either per field or on the instance:

```php
<?php

$fv->addRules([
    'email' => [
        'title' => 'E-mail',
        'valid' => ['required', 'email'],
        'errors' => ['taken' => '"!value" is already registered to someone else'],
    ],
]);

if ($fv->validate() === true && $users->emailExists($fv->post['email'])) {
    $fv->setError('taken', 'email', $fv->post['email']);
}
```

`validate()` has already returned by then, so re-read `$fv->errors` or `hasError()` rather
than its return value - the message lands in `$fv->errors['email']` and `$fv->errors_all`
together, by reference, like any other.

## Every validation rule

Fifteen of them, and this is the complete list:

| Rule                            | Implementation                                                    |
| ------------------------------- | ----------------------------------------------------------------- |
| `required`                      | `trim()` then `!empty()`                                          |
| `email`                         | `filter_var(..., FILTER_VALIDATE_EMAIL)`                          |
| `date[$format]`                 | delegates to `format`, with a default ISO-ish pattern             |
| `ipv4`                          | dotted-quad regex                                                 |
| `ipv6`                          | a large hand-written regex                                        |
| `creditCard`                    | strips non-digits, then an issuer-prefix regex                    |
| `length[$from,$to]`             | `strlen()` against one bound or two                               |
| `equal[$equal,$cast]`           | `===`, or `==` when `$cast` is truthy                             |
| `format[$regex]`                | `preg_match("/$format/")`, with `/` escaped first                 |
| `integer`                       | `/^\d+$/x`                                                        |
| `float[$delimiter]`             | `/^\d+<delimiter>?\d+$/`, delimiter defaults to `.`               |
| `string`                        | `/^[a-z\p{L}]+$/iu`                                               |
| `uploadRequired`                | `name`, `tmp_name` and `size` all non-empty                       |
| `uploadSize[$bytes]`            | `size <= $bytes`, only when `uploadRequired` passes               |
| `uploadExt[$extensions]`        | last `.`-separated part of `name` is in the space-separated list  |

How each one behaves, run against the class:

```text
required   ------------------------------
Fv::required('abc')                      => true
Fv::required('   ')                      => false
Fv::required('0')                        => false
email      ------------------------------
Fv::email('a@example.technology')        => true
Fv::email('a@b')                         => false
date       ------------------------------
Fv::date('2026-08-01')                   => true
Fv::date('2026/08/01')                   => true
Fv::date('2026-13-01')                   => false
Fv::date('1899-01-01')                   => false
ipv4       ------------------------------
Fv::ipv4('192.168.0.1')                  => true
Fv::ipv4('999.0.0.1')                    => false
ipv6       ------------------------------
Fv::ipv6('::1')                          => true
Fv::ipv6('fe80::1')                      => false
Fv::ipv6('2001:db8::ff00:42:8329')       => false
creditCard ------------------------------
Fv::creditCard('4111 1111 1111 1111')    => true
Fv::creditCard('4111111111111112')       => true
length     ------------------------------
Fv::length('abc', 3)                     => true
Fv::length('abcd', 3)                    => false
Fv::length('abcdef', 3, '>')             => true
Fv::length('abc', 5, '<')                => true
Fv::length('abcd', 3, 5)                 => true
Fv::length('abcdef', 3, 5)               => false
Fv::length('abc' with a macron, 3)       => false
equal      ------------------------------
Fv::equal('1', 1)                        => false
Fv::equal('1', 1, true)                  => true
format     ------------------------------
Fv::format('AB-12', '^[A-Z]{2}-[0-9]{2}$') => true
integer    ------------------------------
Fv::integer('123')                       => true
Fv::integer('-1')                        => false
Fv::integer('1.5')                       => false
float      ------------------------------
Fv::float('1.5')                         => true
Fv::float('10')                          => true
Fv::float('1')                           => false
Fv::float('-1.5')                        => false
Fv::float('1,5', ',')                    => true
string     ------------------------------
Fv::string('Riga')                       => true
Fv::string('New York')                   => false
Fv::string('Riga 1')                     => false
uploads    ------------------------------
Fv::uploadRequired($upload)              => true
Fv::uploadRequired([])                   => false
Fv::uploadSize($upload, 200)             => true
Fv::uploadSize($upload, 50)              => false
Fv::uploadSize([], 50)                   => NULL
Fv::uploadExt($upload, 'pdf png')        => true
Fv::uploadExt($upload, 'png jpg')        => false
```

Several of those results are worth reading twice:

-   **`required('0')` is false.** It is `!empty()` after trimming, and `'0'` is empty in
    php. A checkbox or a quantity of zero fails this rule.
-   **`ipv6` rejects ordinary addresses.** `::1` passes, but `fe80::1` and
    `2001:db8::ff00:42:8329` do not. The regex only accepts a `::` contraction in specific
    positions. Use `filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)` instead.
-   **`creditCard` has no Luhn check.** It matches the issuer prefixes and lengths, so
    `4111111111111112` - one digit off a valid test number - is accepted.
-   **`length` counts bytes.** It is `strlen()`, so a three-character latvian word can be
    four or five bytes and fail `length[3]`.
-   **`float` needs at least two digits.** The pattern is `\d+` `.`? `\d+`, so `10` passes
    and `1` does not. Neither validator accepts a leading `-`; there is no rule for a
    negative number.
-   **`string` allows letters and nothing else** - not spaces, not digits. Its default error
    message advertises `[]$/!.?()-'"` and spaces, which the pattern does not accept. The
    message is wrong, not the pattern.
-   **`uploadSize` and `uploadExt` return `null` when there is no upload.** `validate()`
    only records an error when a rule returns exactly `false`, so an absent file passes both.
    Pair them with `uploadRequired` when the file is mandatory.
-   **`date`'s default pattern** is
    `^(19|20)[0-9]{2}[- /.](0[1-9]|1[012])[- /.](0[1-9]|[12][0-9]|3[01])$` - years 1900-2099,
    any of `-`, `/`, `.` or a space as separator, and no calendar check, so `2026-02-31`
    passes. Pass your own pattern as `date[...]` to narrow it.

## Filters

Filters run before the validators for the same field and rewrite `$fv->post[$field]`, so a
validator sees the filtered value and so does `setInputValue()`. They are also usable
standalone:

```text
Fv::setPlain('Riga, 12/A!')            => 'Riga 12A'
Fv::setPlain('a.b', '\\.')             => 'a.b'
Fv::setClean(" <b>hi</b>\n")           => 'hi'
Fv::setClean('5 < 6')                  => '5 &lt; 6'
Fv::translit('Riga' with a macron)     => 'Riga'
Fv::setFriendly('Riga City 2026!')     => 'riga-city-2026'
Fv::setIntOrNull('12abc')              => 12
Fv::setIntOrNull('0')                  => NULL
Fv::xss('<script>alert(1)</script>')   => 'alert(1)'
Fv::xss('<scr<script>ipt>x')           => 'x'
Fv::xss('<img src=x onerror=a(1)>')    => '<img src=x >'
```

| Filter                       | What it does                                                      |
| ---------------------------- | ----------------------------------------------------------------- |
| `setPlain($s, $valid = '')`  | strips everything outside `a-z_-0-9`, space and unicode letters; `$valid` is spliced into the character class |
| `setClean($s)`               | `strip_tags`, `stripslashes`, `<`/`>` to entities, then trims whitespace. Non-strings pass through untouched |
| `translit($s)`               | `iconv('UTF-8', 'ASCII//TRANSLIT')` under a temporarily forced `en_US.UTF8` locale |
| `setFriendly($s)`            | `translit`, lowercase, spaces and apostrophes to `-`, strip the rest - a url slug |
| `setIntOrNull($v)`           | `(int)` cast, then `null` when the result is `0`                  |
| `xss($s)`                    | deprecated denylist sanitizer, below                              |

### xss() is deprecated

The source marks it `@deprecated` and explains why: a denylist cannot be completed, and each
newly invented element, attribute or encoding is a bypass until someone adds it. Two
concrete defects have been fixed - an alternation that used U+00A6 instead of `|`, and a
stripping pass that ran once so that `<scr<script>ipt>` reassembled into a working tag - but
the approach is still weaker than the alternatives.

The deprecation note points elsewhere for both jobs. To render untrusted text, escape it:
twig does that by default, and
[`html_escape()`](/staticphp-core/presentation/html-helpers/#escaping) from
`src/Presentation/Helpers/Html.php` does it in php. To allow a subset of markup, use a parser-based, allowlist sanitizer such as
`symfony/html-sanitizer`.

## Record helpers

Eight static methods that apply one coercion to a named set of keys of an array and return
the array. They are meant for scrubbing a row on its way into the database rather than for
validating a form:

```text
setStringOrNullForRecord($r, ['a'])['a']     => NULL
setIntOrNullForRecord($r, ['a'])['a']        => NULL
setIntOrNullForRecord($r, ['b'])['b']        => 0
setIntForRecord($r, ['a'])['a']              => 0
setDecForRecord($r, ['c'])['c']              => 1.5
setDecOrNullForRecord($r, ['a'])['a']        => NULL
setCleanForRecord($r, ['d'])['d']            => 'x'
setPlainForRecord($r, ['e'])['e']            => 'Riga'
setValueToBinaryForRecord($r, ['b'])['b']    => 0
```

| Method                                      | Per key                                              |
| ------------------------------------------- | ---------------------------------------------------- |
| `setStringOrNullForRecord($record, $keys)`  | `''` becomes `null`                                  |
| `setIntOrNullForRecord($record, $keys)`     | `(int)`, but `null` when empty and not `0`           |
| `setIntForRecord($record, $keys)`           | `(int)`                                              |
| `setDecOrNullForRecord($record, $keys)`     | `fixFloat()`, but `null` when empty and not `0`      |
| `setDecForRecord($record, $keys)`           | `fixFloat()`                                         |
| `setCleanForRecord($record, $keys)`         | `setClean()`                                         |
| `setPlainForRecord($record, $keys)`         | `setPlain()`                                         |
| `setValueToBinaryForRecord($record, $keys)` | `0` when empty, `1` otherwise                        |

The two decimal ones call `fixFloat()`, a free function from
[the helpers file](/staticphp-core/utilities/helpers/), which is **not** loaded
automatically. Load it before using them or you get an undefined-function error.

## Form helpers

Two static request checks:

```php
<?php

Fv::isGet(): bool;
Fv::isPost(array|string|null $isset = null): bool;
```

Both compare `$_SERVER['REQUEST_METHOD']` case-insensitively. `isPost()` optionally also
requires that every name in `$isset` - a string or an array of strings - is present in
`$_POST`; note that it looks at the real `$_POST`, not at the instance's own `post` array.

Four instance methods repopulate a form from the input, each returning `false` when the field
is missing - or, as below, when its value is merely falsy:

```text
setInputValue('name')          => ' value="a&quot; onfocus=&quot;x"'
setInputValue(['tags', 0])     => ' value="a"'
setInputValue('nope')          => false
setSelected('colour', 'red')   => ' selected="selected"'
setSelected('colour', 'blue')  => ''
setSelected('tags', 'b')       => ' selected="selected"'
setChecked('agree')            => ' checked="checked"'
setValue('colour')             => 'red'
```

| Method                        | Returns                                                       |
| ----------------------------- | ------------------------------------------------------------- |
| `setInputValue($name)`        | ` value="..."`, html-escaped                                  |
| `setSelected($name, $test)`   | ` selected="selected"` when the value equals `$test`, or is an array containing it |
| `setChecked($name)`           | ` checked="checked"`, unconditionally once the guard below is past      |
| `setValue($name)`             | the raw value, unescaped                                      |

`$name` is a string, or an array of keys that walks into nested input:
`setInputValue(['tags', 0])` reads `$post['tags'][0]`, which is what `name="tags[]"` sends.

See also [`html_set_selected()` and
`html_set_checked()`](/staticphp-core/presentation/html-helpers/#attribute-helpers), which
do the same job for a value you already hold rather than for one read out of `$_POST`.

:::caution[A falsy value is treated as a missing field]
All four guard with `if (($field = $this->getField($name)) == false)` - a loose comparison,
not a check for the `false` that `getField()` returns when the key is absent. So `'0'`, `''`,
`0` and `null` all take the early return, and a quantity of zero or a cleared text field
silently loses its repopulated value:

```text
php 8.4.24

htmlspecialchars("a' b\" c")         => 'a&#039; b&quot; c'
setInputValue('name')                => ' value="O&#039;Brien &quot;Bob&quot;"'

with post = ['qty' => '0', 'note' => '', 'ok' => 'y']

setInputValue('ok')                  => ' value="y"'
setInputValue('qty')                 => false
setInputValue('note')                => false
setInputValue('absent')              => false
setValue('qty')                      => false
setChecked('qty')                    => false
setSelected('qty', '0')              => false
```

This is the same php falsiness that makes `required('0')` fail, one section up. There is no
flag to turn it off; if a form has a legitimately zero or empty value to preserve, read
`$fv->post[$name]` directly.
:::

The first two rows above are the other thing worth knowing here. `setInputValue()` calls
`htmlspecialchars()` with no flags, and since php 8.1 the default is
`ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401` - this package requires php 8.4, so single quotes
are escaped too and the output is safe in a single- or double-quoted attribute alike. It
builds a double-quoted one, which is where it is meant to go. `setValue()` escapes nothing;
escape it yourself.

## Twig filters

```php
<?php

Fv::registerTwig();
```

Registers `fvPlain`, `fvFriendly` and `fvXSS`, mapping to `setPlain()`, `setFriendly()` and
`xss()`. Unlike [the CSRF helpers](/staticphp-core/utilities/csrf/#templates), the bootstrap
does **not** call this - do it yourself if you want them. It returns without doing anything
when there is no view engine.

`fvPlain` and `fvXSS` declare a second `$valid` parameter that they then ignore, so
`{{ x|fvPlain('.') }}` is the same as `{{ x|fvPlain }}`.
