---
title: Url
description: Three static string helpers for assembling url paths.
sidebar:
    order: 6
---

`StaticPHP\Utils\Models\Url` is three static methods that push strings around. It parses
nothing, encodes nothing and validates nothing - for reading the current request's url, see
[the router](/staticphp-core/core/router/#url-helpers).

```php
<?php

namespace StaticPHP\Utils\Models;

class Url
{
    public static function ensureSlash(string $string);
    public static function addTo(string $url, string $part);
    public static function join(array $parts);
}
```

```text
Url::ensureSlash('/api/v1')                    => '/api/v1/'
Url::ensureSlash('/api/v1/')                   => '/api/v1/'
Url::ensureSlash('')                           => ''
Url::addTo('https://a.test', 'users')          => 'https://a.test/users'
Url::addTo('https://a.test/', 'users')         => 'https://a.test//users'
Url::join(['https://a.test', 'users'])         => 'https://a.test/users'
Url::join(['https://a.test/', '/users/', '7']) => 'https://a.test/users/7'
Url::join(['', 'users'])                       => '/users'
```

## ensureSlash()

Appends `/` unless the string already ends in one. An empty string comes back empty, because
the check is `strlen($string) > 0` first - so it cannot turn `''` into `'/'`.

## addTo()

Literally `"{$url}/{$part}"`. It does not look at what is already there, so a `$url` that
ends in a slash gives you a double slash. Use it when you know both sides are clean.

## join()

`trim($item, '/')` on every element, then `implode('/')`. This is the one to reach for when
the pieces come from different places and may or may not carry slashes - it normalises the
seams and nothing else.

Two things it does not do. Leading and trailing slashes are trimmed from the **first and
last** elements too, so `join(['https://a.test/', ...])` keeps the scheme's `//` (they are
interior) but a path built from `['/api', 'v1']` comes back as `api/v1` without its leading
slash. And an empty element stays in the list, contributing an empty segment, which is why
`join(['', 'users'])` is `/users`.

```php
<?php

use StaticPHP\Core\Models\Config;
use StaticPHP\Utils\Models\Url;

$base = Config::get('base_url');           // 'http://localhost/'
$href = Url::join([$base, 'billing', 'invoices', (string) $id]);
```

None of the three escapes anything. Values that come from user input have to be run through
`rawurlencode()` before they go in.
