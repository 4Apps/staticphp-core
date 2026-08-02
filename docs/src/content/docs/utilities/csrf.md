---
title: CSRF
description: Issuing and checking cross-site request forgery tokens, and where each belongs in a request.
sidebar:
    order: 3
---

`StaticPHP\Utils\Models\Csrf` issues a per-session token and checks one back. It is
entirely static, and it does not protect anything on its own - the framework has no idea
which of an application's routes change state, so wiring the check in is your job.

## The class

```php
<?php

namespace StaticPHP\Utils\Models;

class Csrf
{
    public const SESSION_KEY = '__csrf_token';
    public const FIELD_NAME = '__csrf';
    public const HEADER_NAME = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string;
    public static function fieldName(): string;
    public static function field(): string;
    public static function validate(?string $token): bool;
    public static function validateRequest(): bool;
    public static function reset(): void;
    public static function registerTwig(): void;
}
```

Everything it does, captured:

```text
session_status() === PHP_SESSION_ACTIVE    => true
strlen(Csrf::token())                      => 64
Csrf::token() is stable within a session   => true
Csrf::SESSION_KEY                          => '__csrf_token'
Csrf::FIELD_NAME                           => '__csrf'
Csrf::HEADER_NAME                          => 'HTTP_X_CSRF_TOKEN'
Csrf::fieldName()                          => '__csrf'
Csrf::field()                              => '<input type="hidden" name="__csrf" value="1e5ebccb370a52b3e02a018e041743d78bb238674cb73f06e79449933a4aebec">'
Csrf::validate($token)                     => true
Csrf::validate(substr($token, 0, 32))      => false
Csrf::validate(null)                       => false
Csrf::validateRequest() with the field in $_POST => true
Csrf::validateRequest() with the header only => true
Csrf::validateRequest() with an array in $_POST => false
Csrf::validateRequest() with neither       => false
after Csrf::reset(), token() differs       => true
```

The token is 32 bytes from `random_bytes()` rendered as 64 hex characters, stored in
`$_SESSION['__csrf_token']`, and reused for the life of the session. It is generated on the
first `token()` call, so a page that never renders a form never pays for one.

`validate()` compares with `hash_equals()`, in constant time, so a rejection does not leak
how much of the guess was right. It returns `false` - rather than throwing - for a null or
empty candidate, for a session that is not active, and for a session slot holding anything
other than a string.

`validateRequest()` reads `$_POST['__csrf']` first and falls back to
`$_SERVER['HTTP_X_CSRF_TOKEN']`, which is the `X-CSRF-Token` request header. A non-string in
`$_POST` - `__csrf[]=a&__csrf[]=b`, say - falls through to the header rather than being
compared, and ends up rejected.

:::caution[`token()` throws without a session]
`token()` raises `RuntimeException('A session must be started before using CSRF tokens')`
when `session_status()` is not `PHP_SESSION_ACTIVE`. `validate()` and `validateRequest()`
return `false` in the same situation. Start a session first - see
[sessions](/staticphp-core/utilities/sessions/).
:::

## Where each call belongs

**Issue when rendering.** `token()` and `field()` belong in the view layer, at the point a
state-changing form is built. `field()` returns a complete hidden input with the token
html-escaped:

```html
<input type="hidden" name="__csrf" value="...">
```

**Check before the controller runs.** `validateRequest()` belongs in a
`$config['before_controller']` hook, which the router calls after resolving a controller and
before invoking it:

```php
<?php

use StaticPHP\Core\Exceptions\ErrorMessage;
use StaticPHP\Utils\Models\Csrf;
use StaticPHP\Utils\Models\Fv;

$config['before_controller'][] = function () {
    if (Fv::isPost() && Csrf::validateRequest() === false) {
        throw new ErrorMessage(message: 'Invalid CSRF token', httpStatusCode: 403);
    }
};
```

`Fv::isPost()` is the request-method check from [validation](/staticphp-core/utilities/validation/#form-helpers).
A hook is the right place because it runs once for every route rather than being repeated at
the top of each controller, where the one you forget is the one that matters. The router
calls each hook with `$file`, `$module`, `$class` and `$method` by reference; the closure
above ignores them, which php permits.

**Rotate on privilege change.** `reset()` unsets the session slot so the next `token()` call
issues a new one. Do it on login and on logout, alongside
`Sessions::regenerate()` - the token is only as good as the session it lives in.

## Templates

The bootstrap calls `Csrf::registerTwig()` for you when a twig environment was built, so
these three functions are available in templates with no further setup.

The ready-made field, which is what most forms want:

```twig
<form method="post">
    {{ csrfField() }}
</form>
```

Or build the input yourself, when the markup has to differ:

```twig
<form method="post">
    <input type="hidden" name="{{ csrfFieldName() }}" value="{{ csrfToken() }}">
</form>
```

Use one or the other. Both together put two `__csrf` inputs in the same form, which is not a
security problem - php keeps the last and the values are identical - but it is not what you
meant to write.

`csrfField()` is registered with `['is_safe' => ['html']]`, so twig does not escape the tag
it returns; the token inside it was already escaped by `field()`. The other two return plain
strings and twig escapes them normally.

`registerTwig()` reads `Config::viewEngine()` and returns without doing anything when that is
empty, which is the case in an application that left twig out. Registering the functions makes the
token available to templates and nothing more; incoming requests are still unchecked until
you add the hook.

## The trade-off it makes

One token per session, not one per form. Multiple tabs and the back button keep working, at
the cost of not binding a token to a single submission. That is the usual choice, and the
source says so outright.
