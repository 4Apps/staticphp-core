---
title: Bitwise flags
description: The abstract base class for packing a set of booleans into one integer.
sidebar:
    order: 7
---

`StaticPHP\Utils\Models\BitwiseFlag` is an abstract base for value objects that keep a set of
booleans in a single integer - the shape you end up with when a `permissions` or `options`
column is a bitmask rather than a row per flag.

```php
<?php

namespace StaticPHP\Utils\Models;

abstract class BitwiseFlag
{
    protected int $flags;

    protected function isFlagSet(int $flag): bool;
    protected function setFlag(int $flag, bool $value): void;

    public static function hasFlag(int $flag, int $inFlags): bool;
    public static function registerTwig(): void;
}
```

The class is credited in its own header to a comment on the php manual's bitwise operators
page.

## Subclassing it

`isFlagSet()` and `setFlag()` are deliberately `protected`: the point is that outside code
cannot set arbitrary bits, only the named operations a subclass exposes. `$flags` is
`protected int` with no default and no constructor, so a subclass has to assign it before
anything reads it.

```php
<?php

use StaticPHP\Utils\Models\BitwiseFlag;

class Permissions extends BitwiseFlag
{
    public const READ = 1;
    public const WRITE = 2;
    public const ADMIN = 4;

    public function __construct(int $flags = 0)
    {
        $this->flags = $flags;
    }

    public function value(): int
    {
        return $this->flags;
    }

    public function canWrite(): bool
    {
        return $this->isFlagSet(self::WRITE);
    }

    public function setWrite(bool $on): void
    {
        $this->setFlag(self::WRITE, $on);
    }
}
```

That class, run:

```text
$p = new Permissions(READ); value() => 1
$p->canWrite()                     => false
$p->setWrite(true); value()        => 3
$p->canWrite()                     => true
$p->setWrite(false); value()       => 1
```

`setFlag($flag, true)` is `|=`, `setFlag($flag, false)` is `&= ~`, so turning a flag off
leaves the others alone.

## hasFlag()

The one public operation, for testing a raw integer without wrapping it:

```php
<?php

BitwiseFlag::hasFlag(int $flag, int $inFlags): bool;
```

Argument order is flag first, haystack second, which is the reverse of what `&` reads like -
`hasFlag(2, 6)` asks whether bit 2 is present in 6.

```text
BitwiseFlag::hasFlag(2, 6)     => true
BitwiseFlag::hasFlag(6, 6)     => true
BitwiseFlag::hasFlag(8, 6)     => false
BitwiseFlag::hasFlag(6, 2)     => false
BitwiseFlag::hasFlag(0, 4)     => true
```

The test is `($inFlags & $flag) == $flag`, so `$flag` may be a combination and the answer is
"all of these bits", not "any". `hasFlag(6, 6)` is true and `hasFlag(6, 2)` is false. As a
consequence `hasFlag(0, ...)` is always true, since zero bits are trivially all present -
guard against a zero flag yourself if that matters.

`hasFlag()` is `public static` and the class is `abstract`, so calling it on the base class
directly, as above, is fine.

## Twig

```php
<?php

BitwiseFlag::registerTwig();
```

Registers one function, `BitwiseHasFlag(flag, inFlags)`, mapping straight to `hasFlag()`.
Nothing calls this for you - unlike [the CSRF helpers](/staticphp-core/utilities/csrf/#templates),
which the bootstrap registers. It returns without doing anything when
`Config::viewEngine()` is `null`, because twig is a suggestion rather than a requirement of
this package.

```twig
{% if BitwiseHasFlag(2, user.permissions) %}
    <a href="/edit">Edit</a>
{% endif %}
```
