---
title: Project layout
description: What every top-level entry in a generated project is for, and how an application is arranged under src/.
sidebar:
    order: 3
---

`composer create-project 4apps/staticphp ./` followed by the scaffold step it runs
automatically leaves you with two things layered on top of each other: the skeleton's own
tracked tooling - `lib/`, `presets/`, `scripts/`, the docker and CI setup - and one generated
application under `src/`. [Overview](/staticphp-core/skeleton/overview/) covers why the
application is generated rather than tracked from the start; this page is the map of what
you actually get, from the project root down to the file a first request runs.

The tree below is what a project made from the skeleton looks like. It is not identical to
a checkout of the skeleton repository itself: `--new-project` deletes the handful of files
that only ever described the skeleton - the release scripts, `CHANGELOG.md`, `UPGRADE.md` -
before handing control back, so none of those appear here.

## Top level

| Entry | Tracked? | What it is |
| --- | --- | --- |
| `composer.json` | tracked | Requires `4apps/staticphp-core`, autoloads `lib/` as `StaticPHP\Skeleton\` and `tests/` as `StaticPHP\Skeleton\Tests\`, and defines the `setup`, `test`, `lint` and `stan` composer scripts. |
| `composer.lock` | tracked | Locked dependency versions. |
| `lib/` | tracked | The skeleton's own php tooling - the scaffolder, the cli commands, the upgrader. Covered further down this page. |
| `src/` | tracked | Your application(s). One directory per application, laid down from `presets/` by the scaffolder at creation time. Its anatomy is the rest of this page. |
| `presets/` | tracked | The source `src/*` is built from: `_base` plus a flavour (`twig`, `react`) copied on top. See [applications and presets](/staticphp-core/skeleton/applications-and-presets/). |
| `scripts/` | tracked | Bash and php utilities: `build_info.bash`, `clear_cache.bash`, `code_tests.bash`, the git hooks, `post_create_project.php`, `i18n_integration.php`, `migrations_integration.php`, `phpstan_bootstrap.php`, `watch_files_dev.bash`. `version.bash`, `release_tag.bash` and `upgrade_v2_namespaces.bash` only ever described the skeleton itself and are gone by the time you see this tree. |
| `staticphp` | tracked | The cli entry point - an executable php file with no extension. See [the cli](/staticphp-core/skeleton/cli/). |
| `tests/` | tracked | Tests for `lib/` - the scaffolder, the merge engine, ownership rules - not for your application. Your application's tests live under `src/<App>/Tests/`. |
| `docker/app/` | tracked | `Dockerfile`, a supervisord config and the container's own scripts (`build.bash`, `run.bash`, `serve.bash`, ...). See [the dev environment](/staticphp-core/skeleton/dev-environment/). |
| `docker-compose.yml` | tracked | `develop`, `testing` and `build` services built from the staged Dockerfile. |
| `.dockerignore` | tracked | What `docker build` never sends as context. |
| `.devcontainer/` | tracked | `devcontainer.json`, pointing VS Code's Dev Containers extension at the `develop` compose service. |
| `.github/workflows/ci.yml` | tracked | The CI pipeline copied in from the skeleton - five jobs, each starting with a scaffold step that is a no-op once an application is already tracked. See [the five CI jobs](/staticphp-core/skeleton/testing-and-ci/#the-five-ci-jobs). |
| `.vscode/launch.json` | tracked | Editor debug configuration. |
| `resources/` | tracked | `logo_50.png` and `logo_1024.png` - artwork from the skeleton's own `README.md`. The generated `README.md` no longer embeds them, but the files are not stripped either. |
| `README.md` | tracked | Rewritten by `post_create_project.php` at creation time - see [creating a project](/staticphp-core/skeleton/creating-a-project/). An `upgrade.json` "once" file afterwards - `staticphp upgrade` reports it but never writes it, present or absent. See [upgrading a project](/staticphp-core/skeleton/upgrading-a-project/). |
| `LICENSE` | tracked | Whatever license the skeleton shipped with. |
| `.gitignore` | tracked | Standard ignores, plus the per-application patterns for `Cache/*/`, `Public/assets/dist/`, uploads and the like. |
| `.editorconfig`, `.prettierrc`, `eslint.config.mjs` | tracked | Editor and JS/TS style config. See [the asset pipeline](/staticphp-core/skeleton/asset-pipeline/). |
| `phpcs.xml`, `phpstan.neon`, `phpunit.xml` | tracked | php style, static analysis and the tooling test suite. The root `phpunit.xml` runs `tests/` against `lib/` only - each application carries its own, covered further down this page. See [testing and CI](/staticphp-core/skeleton/testing-and-ci/). |
| `tsconfig.base.json` | tracked | Shared TypeScript config every application's own `tsconfig.json` extends. |
| `rspack.config.js`, `package.json`, `package-lock.json` | tracked | The asset bundler and its npm dependencies, shared across every application under `src/`. |
| `upgrade.json` | tracked | The ownership rules `staticphp upgrade` reads: what it never touches, what it writes once, what a fresh project strips. See [upgrading a project](/staticphp-core/skeleton/upgrading-a-project/). |
| `.version` | tracked | `major.minor` only - reset to `0.1` at creation. `scripts/build_info.bash` appends the commit count as the patch number. Also an `upgrade.json` "once" file. |
| `.env.example` | tracked | Template for the root `.env` - container user ids, git identity for the development image. |
| `.env` | generated, gitignored | Created from `.env.example` by `composer setup`, with `LOCAL_USER_ID`/`LOCAL_GROUP_ID` filled in from the running user so bind-mounted files do not end up root-owned. |
| `.staticphp/manifest.json` | generated at creation, tracked afterwards | Written by the scaffolder: the project's origin repository, and per application which preset and release it is on. Read and updated by every `staticphp upgrade`. |
| `.build_info.json` | generated, gitignored | Written by `scripts/build_info.bash`: version, commit hash, commit date, asset version. Read by every application's `Config/App.php`. |
| `vendor/`, `node_modules/` | generated, gitignored | Composer and npm dependencies. |
| `.phpcs.cache`, `.phpunit.cache/` | generated, gitignored | Tool caches for phpcs and phpunit. |

## `lib/` vs `src/`

This is the pairing that trips people up, because both look like "the code", and they are
not the same kind of thing at all.

**`lib/` is the skeleton's own tooling.** It is PSR-4 autoloaded under `StaticPHP\Skeleton\`
- `composer.json` maps the namespace straight to the directory, and `tests/` the same way
under `StaticPHP\Skeleton\Tests\` in `autoload-dev`. Five files:

```text
lib/
├── Cli.php                    StaticPHP\Skeleton\Cli::commands() - the skeleton's cli registry
├── Manifest.php                .staticphp/manifest.json - load, save, pin, unpin
├── App/
│   ├── Cli.php                 `staticphp app ...` - add, list, dispatches upgrade
│   ├── Presets.php              Which preset to use: SP_PRESET, a prompt, or the default
│   └── Scaffolder.php           Copies _base then a preset into src/<Name>
└── Upgrade/
    ├── Cli.php                 `staticphp upgrade` / `staticphp app upgrade` - the human-facing part
    ├── Upgrader.php             Three-way comparison of a current, base and target tree
    ├── OriginRepo.php           Read-only access to the skeleton's own git repository
    └── Ownership.php            The ignore/once/strip lists from upgrade.json
```

None of it has anything to do with your application. It has nothing to do with routing,
controllers or requests - `lib/` never runs during a web request at all, only from the
`staticphp` cli. [The cli](/staticphp-core/skeleton/cli/) and
[upgrading a project](/staticphp-core/skeleton/upgrading-a-project/) cover what each of these
classes does; this page only needed you to know where they live and that they are a separate
namespace from anything under `src/`.

**`src/` is where applications live, and it is deliberately not PSR-4.** There is no
`autoload` entry for it in `composer.json` at all. A module's namespace root is its own name
- `Defaults\Controllers\Welcome` - resolved at runtime against whichever application's
`Modules/` directory the current request's front controller pointed at, which is how one
repository can serve several applications side by side without their namespaces colliding or
their classes needing to be told apart by a shared prefix. That resolution is core's, not the
skeleton's, and it is exactly what makes multiple applications possible in the first place -
see [running multiple applications](/staticphp-core/guides/multiple-applications/) for the
mechanism rather than have it re-explained here.

## The anatomy of `src/<App>/`

Everything below is one application - `Application` is the name the default preset choice
gives it, but nothing pins the name to that; `staticphp app add <Name>` takes any name
matching `/^[A-Z][A-Za-z0-9]*$/`.

```text
src/Application/
├── Cache/                      Empty, gitkept. Holds compiled twig templates under Views/
│                                when twig is enabled and debug is off; i18n's internal
│                                catalog cache and the file cache backend land here too,
│                                each only once its own config is loaded. Ignored by git
│                                below the top level.
├── Config/
│   ├── App.php                  Version and build info - see below
│   ├── Config.php                The main $config array - see below
│   └── Routing.php               $config['routing'] - see below
├── Files/                      Empty, gitkept. Not read by any framework code - a place for
│                                the application's own file storage to exist from the start
│                                rather than being created on first upload.
├── Helpers/
│   └── Bootstrap.php             Autoloaded via $config['autoload_helpers'] - headers,
│                                  sessions, twig registration; whatever runs once per request
│                                  before routing
├── Migrations/                 Empty, gitkept - see below
├── Modules/
│   └── Defaults/                One example module - see below
├── Public/
│   ├── assets/src/               Source js/ts/scss - see below
│   ├── dev-router.php
│   ├── favicon.ico
│   ├── .htaccess
│   └── index.php                 The front controller - see below
├── Tests/
│   ├── autoload.php               Bootstrap for this application's own test suite
│   ├── status_probe.php            Runs the front controller in a subprocess to assert real
│   │                                 HTTP status codes
│   └── Modules/Defaults/Controllers/
│       ├── DebugTest.php
│       └── WelcomeTest.php
├── Views/
│   └── components/                Menu partials shared across the whole application, outside
│                                    any one module - menu_type_100.html and friends
├── phpunit.xml                  This application's own test suite - bootstrap="Tests/autoload.php"
├── tsconfig.json                 Extends ../../tsconfig.base.json, scoped to this app's assets
└── .env                         Generated by the scaffolder from .env.example - APP_ENV,
                                   LOGGING_* - gitignored
```

Each application carries its own `phpunit.xml`, `tsconfig.json` and `.env` because
`PUBLIC_PATH` is a constant, not derived: one php process is exactly one application, so
nothing about config, tests or the asset build can be shared at that level even when several
applications sit in the same repository. See
[why a constant rather than a method](/staticphp-core/getting-started/front-controller/#why-a-constant-rather-than-a-method)
for the reasoning, and [the front controller](/staticphp-core/getting-started/front-controller/)
for how `PUBLIC_PATH` derives `APP_PATH`, `APP_MODULES_PATH` and the rest. `Migrations/` sits empty
until you load the migrations config - its shipped default is `APP_PATH . '/Migrations'` -
see [migrations](/staticphp-core/database/migrations/).

## The three config files

`Config::load(['Config', 'Routing'])` is what the bootstrap calls, resolving to
`Config/Config.php` and `Config/Routing.php` under `APP_PATH`; `App.php` is pulled in
separately, by name, through `$config['autoload_configs'] = ['App']` in `Config.php` itself.
[Configuration](/staticphp-core/getting-started/configuration/) is the reference for how
`Config::load()` resolves file names in general and what the framework reads back out of
`$config`; this section only covers what the three shipped files actually contain.

### `Config/App.php`

Version and build metadata, and nothing else. It reads the gitignored `.build_info.json`
first and falls back to `.version` with a `.dev` suffix when that file is absent, which is
the state of a plain checkout with no build step run. What writes `.build_info.json`, and
when, is [the asset pipeline's](/staticphp-core/skeleton/asset-pipeline/) subject; the
fallback branch here is the whole of what this page needs to say about it:

```php title="src/Application/Config/App.php (fallback branch)"
<?php

$versionFile = __DIR__ . '/../../../.version';
$majorMinor = (
    is_file($versionFile)
    ? trim((string) file_get_contents($versionFile))
    : '0.0'
);

$config['version'] = "v{$majorMinor}.dev";
$config['git_commit_hash'] = '';
$config['git_commit_date'] = '';

// No build to pin assets to, so bust the cache on every request
$config['asset_version'] = time();
```

`asset_version` is what a `<link>` or `<script>` tag's cache-busting query string uses -
`layout.html` in the default module reads it as `config.asset_version`. It comes from the
build info when there is one, falls back to the commit hash, and is forced to `time()` in an
explicit dev environment even when build info exists, so an asset edited between requests is
never served stale from a developer's browser.

### `Config/Config.php`

The main `$config` array. Before assigning anything it loads `.env` through
`Symfony\Component\Dotenv\Dotenv` twice - `BASE_PATH . '/.env'` first, then
`APP_PATH . '/.env'` as an overload. `BASE_PATH` is `src/`, not the project root, so the
first call is a no-op in a fresh project; the second is what actually reads the
scaffolder-created `src/Application/.env`. It then assigns every key the bootstrap and
router read. The keys that actually appear in the shipped file:

`base_url`, `disable_twig`, `environment`, `debug`, `debug_check`, `logging` (`display_level`,
`log_level`, `report_level`, `report_email`, `report_webhook`, `report_email_func`,
`report_webhook_func`), `error_pages`, `request_uri`, `query_string`, `script_name`,
`client_ip`, `allowed_hosts`, `trust_proxy_headers`, `trusted_proxy_hops`, `view_env_keys`,
`url_prefixes`, `module_paths`, `autoload_configs`, `autoload_helpers`, `before_controller`.

[Configuration](/staticphp-core/getting-started/configuration/#keys-the-framework-reads) and
[the config api](/staticphp-core/core/config/) cover what each of these does and how `Config`
itself works; what is worth knowing here is what the shipped defaults actually are.
`environment` reads `SP_ENVIRONMENT` if a front controller defined it, otherwise
`$_ENV['APP_ENV']`; `debug` follows from it - anything other than `dev` is not debug.
`autoload_configs` is `['App']` and `autoload_helpers` is `['Bootstrap']`, which is the whole
reason `App.php` and `Helpers/Bootstrap.php` run without anything calling them by name.

### `Config/Routing.php`

`$config['routing']`, nothing more. The shipped file has one entry, the default route:

```php title="src/Application/Config/Routing.php"
<?php

$config['routing'] = [

    // Default Controller and Method names
    '' => 'Defaults/Welcome/index',

    // Rest of the routing
    # '^([0-9]+)$' => 'orders/details/$1'
        # Example rewrite: http://example.com/1234 -> http://example.com/orders/details/1234
];
```

How the array is read - which key wins when several match, how a rewrite interacts with url
prefixes, what `urlToFile()` does with the default entry - is
[the router's](/staticphp-core/core/router/#the-rewrite-rules) job to explain, not this
page's.

## A module's shape

`Modules/Defaults/` is the one module every generated application starts with - `Defaults`
because that is what an unqualified `/` resolves to via the default route above.

```text
Modules/Defaults/
├── _bootstrap.php               Runs once per request that reaches this module - sets
│                                  view_data.js_include here
├── Controllers/
│   ├── Welcome.php                Serves / - Defaults/Welcome/index
│   ├── Debug.php                   /defaults/debug - environment and db report, debug mode only
│   └── Test/Test.php                A controller nested one directory deeper, for reference
├── Data/
│   └── MainMenu.php               Extends StaticPHP\Presentation\Models\Menu\Menu
├── Helpers/
│   └── Bootstrap.php               Empty example - a module-level equivalent of the
│                                    application-level Helpers/Bootstrap.php, included when
│                                    the router walks into this module
└── Views/
    ├── layout.html
    ├── index.html
    └── example.html
```

`_bootstrap.php` and `Helpers/Bootstrap.php` are both include-if-present, not registered
anywhere - `Router::loadController()` walks from the matched controller's directory up to
`APP_MODULES_PATH`, including a `_bootstrap.php` at every level that has one, and separately
includes `Helpers/Bootstrap.php` for the resolved module before that walk starts. Adding
either file to a new module is enough; there is no list to update. See
[controllers](/staticphp-core/core/controllers/) for what a controller class itself is
expected to look like and what `construct()`/`destruct()` do around it.

## `Public/`

**`index.php`** is the front controller - the one file that has to exist before anything
else does, because it is what defines `PUBLIC_PATH` and requires the framework bootstrap:

```php title="src/Application/Public/index.php"
<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('PUBLIC_PATH', __DIR__);

require StaticPHP\Core\Bootstrap::FILE;
```

[The front controller](/staticphp-core/getting-started/front-controller/) is the full
reference for what defining `PUBLIC_PATH` here derives and in what order.

**`dev-router.php`** exists for `php -S` - the built-in development server, which has no
rewrite rules of its own. It refuses to run under anything else (`SERVER_SOFTWARE` has to
contain `Development Server`), resolves the request path against the filesystem, serves an
existing file as-is or through the php interpreter, serves an existing directory's
`index.php`/`index.html`, and otherwise rewrites to `index.php` with `$_SERVER['SCRIPT_NAME']`
reset so the router works out the right base url. It also refuses to serve dotfiles or itself.
`docker/app/scripts/serve.bash` - what the `develop` compose service runs - passes it as the
router argument: `php -S 0.0.0.0:5000 -t ./src/<App>/Public/ ./src/<App>/Public/dev-router.php`.
A real web server uses `.htaccess` instead.

**`.htaccess`** denies dotfiles and common backup/editor extensions outright, sets three
security headers when `mod_headers` is available, and rewrites anything that is not an
existing file to
`index.php/$1` - with one carve-out ahead of it that sends an existing file under `Config/`
through the router instead of serving it directly, so a request that happens to collide with
that directory name cannot read the application's config off disk.

**`assets/src/` vs `assets/dist/`.** `assets/src/` is tracked, and every application gets the
same floor from `_base`: the `base/` subtree (`scss/theme.scss`, and the `ts/` config,
polyfill, interfaces and utils), `globals.d.ts` and `index.scss`. The flavour preset adds its
own entry point on top - `twig` adds `defaults.ts`, `react` adds `app.tsx` and a `react/`
subtree instead. `assets/dist/` is where the build output lands and is gitignored
(`/src/*/Public/assets/dist/` in `.gitignore`); it does not exist until you run the asset
pipeline, and neither does `assets/fonts/`. What builds `src/` into `dist/`, and the npm
scripts that drive it, are
[the asset pipeline's](/staticphp-core/skeleton/asset-pipeline/) subject, not this one's.
