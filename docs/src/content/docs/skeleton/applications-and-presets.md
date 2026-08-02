---
title: Applications and presets
description: How staticphp app add turns a name into a running application, and how presets decide what it contains.
sidebar:
    order: 5
---

An application under `src/` is not tracked by the skeleton - nothing is committed there, and
a fresh checkout starts empty. What is tracked is `presets/`: a set of directory trees that
`staticphp app add` copies into place. A preset is not a template engine with placeholders to
fill in; it is files, copied as themselves, laid down in a fixed order. That is the whole
mechanism, and it is worth understanding the order before looking at what either shipped
preset contains, because the order is what makes the contents make sense.

## `staticphp app add`

```bash
staticphp app add <Name> [--preset=<name>] [--force]
```

`<Name>` becomes the directory under `src/` and a namespace segment, so it is checked against
one pattern before anything is written:

```php
<?php

private const NAME_PATTERN = '/^[A-Z][A-Za-z0-9]*$/';
```

Start with a capital letter, then letters and digits only - no underscore, no hyphen, no
leading lower-case. A name that fails it never reaches the filesystem: `Scaffolder::create()`
throws a `RuntimeException` reading `Invalid application name "<name>" - start with a capital
and use letters and digits only`, and `app add` prints that to stderr and exits `1`.

`--preset=<name>` picks the preset directly. Without it, `app add` asks - see
[preset selection](#choosing-a-preset-preset-selection-order) below. `--force` overlays onto
an existing `src/<Name>` instead of refusing; without it, a directory that already exists is a
hard stop (`src/<Name> already exists - pass --force to overlay onto it`). `--force` does not
delete anything first, it just lets `copyTree()` run against a non-empty target - see
[composition](#how-composition-works) for what that means for files already there.

On success it prints what was copied, then a `Next:` section built from the preset's
`preset.json` plus one line the scaffolder always appends itself:

```text
Created src/Shop from the twig preset:
  copied  presets/_base (21 files)
  copied  presets/twig (19 files)
  created src/Shop/.env from .env.example
  recorded .staticphp/manifest.json

Next:
  npm run build:dev      # builds this app's css and js
  APP=Shop npm start   # serve it on 127.0.0.1:8081
```

The `.env` line only appears when `src/<Name>/.env` does not already exist and
`.env.example` does - `_base` ships the example, never the real file, since it is gitignored
and a missing `APP_ENV` reads as an unknown environment (debug off, minified bundle names
expected that a brand new application has not built yet).

`staticphp app list` prints every application already under `src/` - discovered by `is_file`
on `src/<name>/Public/index.php`, not by reading the manifest - alongside its javascript entry
points, found by globbing `Public/assets/src/*.{ts,tsx,js,jsx}`:

```text
Applications:
  Shop                 bundles: app
  Docs                 (no js entry)
```

`staticphp app upgrade <Name>` re-applies a preset's changes to an application already created
from it; it is covered on [upgrading a project](/staticphp-core/skeleton/upgrading-a-project/).

## How composition works

Creating an application composes exactly two layers, in this order:

```php
<?php

foreach (['_base', $preset] as $layer) {
    $counts[$layer] = self::copyTree("{$presetsDir}/{$layer}", $into);
}
```

`_base` is copied first, then the chosen preset on top of it - into the same target
directory, file by file. `copyTree()` does not overwrite: `if (is_file($target)) { continue; }`
skips any file that is already there. So the overlay can only *add* files a base layer did
not place; it can never replace one. In practice this means `_base` owns any path a preset
also happens to ship, and a preset that genuinely needs to change base behaviour has to do it
by adding a new file (a bootstrap, a config key set from application code) rather than by
shadowing an existing one.

`_base` is excluded from `presets()` - the list `app add` offers to choose from - because it
is not a choice, it is the floor every application stands on regardless of preset.

After both layers are copied, `preset.json` is deleted from the result:

```php
<?php

// preset.json describes the preset, it is not part of the application
$leftover = "{$into}/preset.json";
if (is_file($leftover)) {
    unlink($leftover);
}
```

It is metadata about the preset, not application content, so a scaffolded application never
carries it. `compose()` itself is a static method, separate from `create()`, precisely so the
upgrader can reproduce a preset exactly as it composed at some earlier tag - into a scratch
directory, with no `.env` and no `package.json` involved - without a second implementation of
"what a preset expands to" that could drift from this one.

## What `_base` provides

Every application gets `_base` regardless of preset. It supplies:

| Provides | Detail |
| --- | --- |
| The front controller | `Public/index.php`, which requires the composer autoloader, defines `PUBLIC_PATH`, and requires `StaticPHP\Core\Bootstrap::FILE`. This is what makes an application discoverable as one at all: `Scaffolder::apps()` checks for exactly this file. |
| Config | `Config/Config.php` (base url, debug, logging, allowed hosts, proxy trust, module paths, autoload lists) and `Config/App.php` (version and build info, read from the gitignored `.build_info.json` with a `.version`-based fallback). See [configuration](/staticphp-core/getting-started/configuration/) for how these load. |
| `.env.example` | `APP_ENV=dev` and the logging levels; copied to `.env` on creation as described above. |
| Empty, gitkept directories | `Cache/`, `Files/`, `Migrations/` - so the tree an application needs exists from the start rather than being created lazily on first use. |
| `phpunit.xml` and `Tests/autoload.php` | A `Tests/` suite pointed at this application's own directory, plus `Tests/status_probe.php`, which runs the front controller as a subprocess against a given url and reports the response body and HTTP status - used by both presets' own controller tests. |
| Base frontend scaffolding | `Public/assets/src/base/` (`scss/theme.scss`, `ts/config.ts`, a small polyfill and utils module, `interfaces.ts`), `index.scss`, `globals.d.ts` and `tsconfig.json`. The `twig` preset's own entry, `defaults.ts`, imports `base/customPolyfill`, `base/config` and `base/utils` directly; `index.scss` pulls in `base/scss/theme.scss` after fontawesome and bootstrap. |
| `Public/dev-router.php`, `Public/.htaccess`, `favicon.ico` | The front controller's neighbours for `php -S` and for Apache respectively. |

## The `twig` preset

`preset.json` describes it as *"Server rendered app using twig views"*. It adds one module,
`Defaults`, with two controllers:

- **`Welcome`** - the default route's target. `index()` renders `Defaults/Views/index.html`;
  passing `error` as the first url segment throws a demonstration `ErrorMessage` so a fresh
  checkout has something to click that exercises the error pages. `example()` renders a second
  view listing every file php has included, as a starting point for exploring the request
  lifecycle.
- **`Debug`** - `/defaults/debug`, returning a json report (application, request, php,
  every configured database's own clock, and the session with a fixed list of keys redacted -
  `access`, `api_key`, `password`, `pin_code`, `secret`, `token`). It throws `NotFound` rather
  than a 403 when `$config['debug']` is off, so the endpoint does not confirm its own
  existence to a client that is not supposed to see it.

The default route:

```php
<?php

$config['routing'] = [
    '' => 'Defaults/Welcome/index',
];
```

`Helpers/Bootstrap.php` sends the content-type header and registers the twig `Menu`
extension against `Defaults/Data/MainMenu`; the session block (`session_name('MY_SESSION_NAME')`)
is present but commented out, left as an example for whoever needs one rather than started by
default - the `react` preset is the one that starts a session out of the box, under the cookie
name `APP_SESSION`. The preset also ships `Views/components/menu_type_*.html` - the menu
partials `Menu` renders against - and one frontend entry, `Public/assets/src/defaults.ts`.

## The `react` preset

`preset.json` describes it as *"React single page app with a json api, served by a small twig
shell"*. It is two modules: `Spa`, which serves the shell the react app mounts into, and
`Api`, a small json endpoint the react app talks to.

The routing is what makes it a single page application rather than a set of server-rendered
pages:

```php
<?php

$config['routing'] = [
    '' => 'Spa/Index/index',
    '^(?!api/)(.*)$' => 'Spa/Index/index',
];
```

Every url that is not under `api/` falls through to the same shell controller, so a deep link
or a page reload lands on the react app rather than a 404, and client-side routing owns the
url from there. There is no separate rewrite rule for `/api/...` here - it does not need one.
`/api/items` reaches `Api\Controllers\Items` through the router's ordinary segment resolution,
the same way any controller is found without a rewrite rule at all; the negative lookahead's
job is only to stay out of that path's way rather than rewriting it into the shell too.

`Helpers/Bootstrap.php` starts the session outright - `session_name('APP_SESSION')` then
`session_start()`, worth changing before running two applications from this preset on the same
host - because the csrf token below lives in it.

`Spa\Controllers\Index::index()` renders `Spa/Views/index.html`, which extends a layout and
mounts a `<div id="react-root">` carrying two data attributes:

```php
<?php

self::render(['index.html'], [
    // Handed to the browser in a data attribute rather than fetched, so the very
    // first POST does not need a round trip to get a token
    'csrf_token' => Csrf::token(),
    'api_base' => '/api/items',
]);
```

`assets/src/react/api.ts` reads both straight off the DOM node (`root.dataset.csrfToken`,
`root.dataset.apiBase`) and sends the token back as `X-CSRF-Token` on every request, alongside
`Content-Type: application/json` - which is what makes the router merge a posted body into
`$_POST` at all. On the php side, `Api\Controllers\Items::construct()` checks
`Csrf::validateRequest()` for anything but a `GET`, throwing a 403 `ErrorMessage` when it
fails. See [csrf](/staticphp-core/utilities/csrf/) for the token itself; the pattern here is
specifically that the controller injects the token into the shell it already has to render,
rather than the frontend fetching one separately before it can do anything.

`Items` demonstrates the api shape end to end against the session (no database needed for the
demo): `GET /api/items` lists, `POST /api/items/create` appends. `assets/src/app.tsx` is the
bundle entry, mounting `react/App.tsx` into `#react-root`.

`preset.json` declares its own npm dependencies - `react`, `react-dom` and their `@types`
packages - which is why its `next` steps list `npm install` before the build; the `twig`
preset needs neither and lists none.

## `preset.json`

Read by `Scaffolder::presetMeta()`, and entirely optional in what it declares - a preset with
no `preset.json` at all still composes, just with no description, no npm dependencies and no
extra next steps. Three keys are read:

```json
{
    "description": "React single page app with a json api, served by a small twig shell",
    "npm": {
        "dependencies": {
            "react": "^19.2.0",
            "react-dom": "^19.2.0"
        },
        "devDependencies": {
            "@types/react": "^19.2.0",
            "@types/react-dom": "^19.2.0"
        }
    },
    "next": [
        "npm install            # pulls in react",
        "npm run build:dev      # builds this app's css and js"
    ]
}
```

- **`description`** - shown next to the preset's name in `App\Cli::usage()` - reached via
  `staticphp app --help` or an unrecognised `app` subcommand, not `staticphp app list`, which
  prints only names and javascript bundles - and in the interactive picker.
- **`npm`** - `dependencies` and `devDependencies` merged into the *root* `package.json`, not
  kept per-preset. Applications share one `node_modules`, so `mergeNpmDependencies()` reads
  the root `package.json`, adds any entry not already present under either section, leaves
  existing entries alone rather than downgrading them, and rewrites the file sorted by key.
  Nothing is installed for you - the `next` step doing that is what the `react` preset uses
  its own list to say, since the plain `Application/Public/assets/src` layout `_base` ships
  has no dependency of its own to install.
- **`next`** - lines appended, verbatim, to what `app add` prints after creation. `Scaffolder`
  always appends one line of its own after them: `APP={name} npm start   # serve it on
  127.0.0.1:8081`.

## Writing your own preset

There is no registry to update. `Scaffolder::presets()` lists every directory under
`presets/` except `_base` and anything dotfile-prefixed, so creating `presets/api/` makes
`api` a valid `--preset=api` and a new line in the interactive picker, with nothing else to
wire up.

What it needs: nothing, strictly - an empty directory is a legal preset that composes to
exactly what `_base` already provides. What makes it useful is a tree of files under it that
mirrors the target application layout (`Config/`, `Modules/<Name>/Controllers/`, `Public/`,
and so on), copied in on top of `_base` by the same `copyTree()` rule - so a file it ships at
a path `_base` already occupies is silently skipped rather than replacing it.

What is optional: `preset.json`, for a description, npm dependencies and next steps as above.
There is no schema enforcement beyond the three keys `presetMeta()` reads - an unrecognised
key is ignored, not rejected.

## Choosing a preset: preset selection order

**`--preset=<name>`**, passed directly to `app add`, short-circuits everything below -
`Presets::choose()` is not even called when it is set.

Without it, `choose()` is what decides - shared by `app add` and by the composer
`post_create_project.php` hook that lays down the project's first application - and it
resolves in this order:

1. **`SP_PRESET`** - `getenv('SP_PRESET')`, trimmed; used if non-empty.
2. **The interactive prompt**, only when `stream_isatty(STDIN)` is true - numbered list of
   available presets, default marked, accepting either the number or the name typed back.
   An empty answer or one that matches neither takes the default.
3. **`twig`** - the `$default` argument `app add` passes to `choose()`, and also what a
   non-interactive prompt (no tty, and `SP_PRESET` unset) falls straight back to without
   asking anything.

No environment sniffing beyond that tty check: the class notes that composer detaches stdin
itself when `--no-interaction` is given, so `stream_isatty(STDIN)` alone is enough to know
whether asking is possible, and there is no path on which an automated run blocks waiting for
an answer. `.github/workflows/ci.yml`'s `presets` job exercises both `twig` and `react` this
way - `--preset=` explicitly, never the prompt - scaffolding, installing, building and curling
each one so neither preset can go stale unnoticed.

## Several applications in one repository

Nothing about presets is single-application. `app add` targets `src/<Name>`, and everything
downstream that has to notice an application does so by globbing `src/*` rather than reading a
list - `Scaffolder::apps()` itself, and the module resolution behind `Load::` and autoloading
covered in [multiple applications](/staticphp-core/guides/multiple-applications/). Asset
builds are per application too, each with its own entry points under
`src/<Name>/Public/assets/src`; see
[the asset pipeline](/staticphp-core/skeleton/asset-pipeline/) for how that is built and
served.
