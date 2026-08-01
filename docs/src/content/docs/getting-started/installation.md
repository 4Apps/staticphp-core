---
title: Installation
description: Installing StaticPHP Core with Composer.
sidebar:
    order: 1
---

StaticPHP Core is the framework as a library. It is the package an application depends on,
and it is installed with composer:

```bash
composer require 4apps/staticphp-core
```

Everything it ships lives under the `StaticPHP\` namespace, resolved by PSR-4 from the
package's `src/` directory, so composer's autoloader finds it with no further setup.

## Requirements

`composer.json` requires:

-   PHP 8.4 or newer
-   `ext-intl`
-   `ext-mbstring`
-   `ext-pdo`

Composer refuses to install the package without them, so there is nothing to check by
hand.

## Twig is a suggestion, not a requirement

Twig 3.0 or newer is listed under `suggest`, not `require`. Composer's `files` autoload is
eager: twig plus the symfony polyfills it pulls in load eight files on every request
whether or not a template is ever rendered. Leaving the library out of an api-only
application's dependencies is what removes that cost - lazy class loading alone would not.

If the application renders templates, require it explicitly:

```bash
composer require twig/twig:^3.0
```

Without twig installed the view engine is simply not built, `Load::view()` falls back to
plain php templates, and the `registerTwig()` helpers return quietly. If the library is
installed but a particular application should not use it, `$config['disable_twig'] = true`
skips building the environment.

## Relationship to gintsmurans/staticphp

This package is the library. The application skeleton - the demo app, the asset pipeline,
the dev container, the `staticphp` cli - lives in
[gintsmurans/staticphp](https://github.com/gintsmurans/staticphp) and is what
`composer create-project` gives you.

Starting a new project from that skeleton is the usual route; `composer require` is for
adding the framework to an application that already exists, or for building the
application tree by hand.

## Next

Nothing runs yet. A front controller has to say where the application is before the
framework can boot - see
[the front controller](/staticphp-core/getting-started/front-controller/).
