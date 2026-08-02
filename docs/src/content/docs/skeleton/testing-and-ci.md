---
title: Testing and CI
description: What the quality gate runs, in what order, and how the shell, the pre-commit hook and CI all end up running exactly the same thing.
sidebar:
    order: 9
---

`scripts/code_tests.bash` is the entire quality gate. It is called with no wrapping and no
extra flags from a terminal, from the pre-commit hook, and from every job in
`.github/workflows/ci.yml` that runs php or javascript checks. There is exactly one place
that decides what counts as green, and the hook and CI both defer to it rather than
reimplementing it - so there is no separate "CI config" to keep in sync with what runs on
your machine, and a script that passes locally passes in the pipeline by construction.

Every stage inside it is fatal. It does not print advice and continue; a failed step sets a
flag, the run carries on to show you everything else that is wrong, and the script exits
non-zero at the end if anything failed.

## One command, three ways to call it

```bash
./scripts/code_tests.bash          # everything
./scripts/code_tests.bash php      # php only
./scripts/code_tests.bash js       # js only
```

Any other first argument prints a usage line and exits 2. `all` runs `run_php` then
`run_js`, in that order.

**`php` runs, in order:**

1. `php -l` over every `.php` file under `lib`, `src`, `scripts` and `tests`, plus the
   `staticphp` entry point by name - it has no `.php` extension, so the glob above misses
   it. `scripts/` is included even though nothing under `tests/` exercises it, specifically
   so a rename in `src/` that breaks one of the integration scripts is caught here rather
   than the next time somebody runs it by hand.
2. `phpcs` against `phpcs.xml`, over `lib src scripts tests`.
3. `phpstan analyse`.
4. `phpunit -c phpunit.xml` - the skeleton's own tooling suite.
5. `phpunit -c src/<App>/phpunit.xml`, once per application under `src/`.

**`js` runs, in order:**

1. `npm ci --no-audit --no-fund`, but only if `node_modules` does not already exist.
2. `tsc` typechecking, via `npm run typecheck`.
3. `eslint` over `src/*/Public/assets/src` - its `files` glob in `eslint.config.mjs` matches
   `.js`, `.jsx`, `.ts` and `.tsx`.
4. `prettier --check` over `src/*/Public/assets/src/**/*.{ts,tsx,scss}` - narrower than
   eslint's reach, and narrower than `npm run format:check`, which adds `.js` and `.jsx`. A
   misformatted `.js` or `.jsx` file passes this gate but fails `format:check`.

The ordering is deliberate in both halves: syntax before style before static analysis
before tests, because a file that fails `php -l` will fail every later step too, and there
is no reason to wait for `phpstan` to tell you that. The javascript half is the same idea -
`tsc` first, because a type error usually also trips `eslint`'s type-aware rules, and there
is no point running the slower checks twice.

`code_tests.bash` resolves its own location with `dirname`/`pwd` and `cd`s to the repository
root by default, so it is safe to invoke with a relative path from anywhere inside a
checkout. That default is an `APP_DIR` environment variable away from being overridden - set
`APP_DIR` and the script runs against that directory instead.

`composer.json` also exposes three of these steps individually, as thinner convenience
wrappers rather than an alternative gate: `composer test` runs the root `phpunit.xml` then
loops `src/*/phpunit.xml` (skipping silently if a given application has none, rather than
failing the way `code_tests.bash` does), `composer lint` is the `phpcs` invocation, and
`composer stan` is the `phpstan analyse` invocation, flags and all. None of the three run
`php -l`, `eslint` or `prettier` - reach for `code_tests.bash` for the full gate.

## Two levels of phpunit config, and why

`PUBLIC_PATH` is a constant, defined once per request by
[the front controller](/staticphp-core/getting-started/front-controller/), so a single php
process can only ever be one application - there is nowhere to put a second `PUBLIC_PATH`,
which rules out one phpunit config covering two applications at once. The skeleton carries a
config at each of the two levels that actually needs one:

- **`phpunit.xml`** at the repository root. Its testsuite is named `Tooling`, covers the
  `tests/` directory, and bootstraps nothing but composer's autoloader - nothing in `tests/`
  boots the framework, so there is no `PUBLIC_PATH` to define. This is the scaffolder and
  the upgrader under test: `CliTest`, `MergeTest`, `OwnershipTest`, `TempTree`,
  `UpgradeIntegrationTest` and `UpgraderTest`.
- **`presets/_base/phpunit.xml`**, copied into every generated application as
  `src/<App>/phpunit.xml` when the application is scaffolded (see
  [applications and presets](/staticphp-core/skeleton/applications-and-presets/)). Its
  testsuite is named `All`, covers `Tests/`, and bootstraps through `Tests/autoload.php` -
  which does define `PUBLIC_PATH`, ahead of requiring the framework's own autoloader.

Both configs set `failOnWarning`, `failOnDeprecation` and `failOnNotice`, so a test that
merely triggers a deprecation notice fails the suite rather than passing with noise in the
output.

The consequence for `code_tests.bash` is that it cannot run one phpunit invocation for the
whole tree - it runs the root suite first, then loops `src/*/phpunit.xml` and invokes
phpunit again for each match. `composer.json`'s `test` script does the same loop. Because
applications are generated from `presets/` rather than tracked in the skeleton repository, a
fresh checkout of the skeleton itself legitimately has none under `src/`. Rather than let an
empty loop pass silently as a green run, the script checks for at least one
`src/*/phpunit.xml` before entering the loop and, finding none, fails outright with:

```text
no application under src/ - run: composer setup
```

The same check and the same message appear in `run_js`, guarding on
`src/*/Public/assets/src` instead.

## Static analysis

```bash
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

`phpstan.neon` analyses `lib`, `src`, `scripts`, `tests` and the `staticphp` entry point, at
**level 9**. The comment in the config is explicit about why it stops there rather than at
the top level: `Config::$items` is a bare array in the framework, so every read off it is
`mixed`, and that caps what level 10 could usefully report here - worth revisiting once core
carries better annotations.

`src/*/Cache/*` is excluded from both analysis and scanning - Twig's compiled templates,
generated and gitignored.

**There is no baseline file, on purpose.** The comment in `phpstan.neon` gives the reasoning
directly: the skeleton is small enough to keep genuinely clean, and an application generated
from it inherits this same config as its own starting point - a baseline the application did
not create would just be inherited debt from day one.

`scripts/phpstan_bootstrap.php` is loaded through `bootstrapFiles`, and only by phpstan -
never by the application itself. `StaticPHP\Core\Helpers\Autoload` defines `PUBLIC_PATH`,
`APP_PATH`, `APP_MODULES_PATH`, `BASE_PATH` and `VENDOR_PATH` at runtime, each derived from
the `PUBLIC_PATH` the front controller declares. Nothing static can follow that chain, so
without the bootstrap file every config file that references one of those constants reads to
phpstan as "constant not found", and worse, expressions built from them degrade to `mixed`
and hide the findings that actually matter. The bootstrap defines the same five constants
with placeholder values - the values are irrelevant, only the names and the fact that
they're strings matter.

One `ignoreErrors` entry excludes a strict-comparison warning in
`scripts/i18n_integration.php` and `scripts/migrations_integration.php`, where `$checks` and
`$failures` are globals incremented by helper functions - phpstan only analyses the top
level scope, where they are still the literal `0` they were assigned.

## Code style

```bash
./vendor/bin/phpcs --standard=phpcs.xml lib src scripts tests
```

`phpcs.xml` builds on `PSR12` (not `PSR2` - the ruleset's own comment notes that including
both used to reject the multi-line `if()` conditions PSR-12 explicitly allows, which is most
of this codebase), plus `Zend.Files.ClosingTag`, `Generic.Arrays.DisallowLongArraySyntax`,
`Generic.Commenting.Todo`, `Generic.PHP.DeprecatedFunctions`, `Generic.PHP.LowerCaseKeyword`
and `Generic.WhiteSpace.IncrementDecrementSpacing`. Line length is capped at 120 with no
absolute limit (`Generic.Files.LineLength`, `absoluteLineLimit="0"`). `*/vendor/*`,
`*/node_modules/*`, `*/Cache/*` and `*/assets/dist/*` are excluded. `i18n.php` is
individually exempted from the missing-namespace and not-PascalCase sniffs, since it is an
established abbreviation and renaming the class would break every application referencing
it.

**Warnings do not fail the run.** `<arg value="n"/>` combined with
`ignore_warnings_on_exit="1"` means phpcs itself exits 0 on warnings alone - the ruleset's
own comment is blunt about it: `code_tests.bash` decides what is fatal, not phpcs. In
practice that means only errors block the gate; a warning-level finding is visible in the
output but will not turn a commit or a CI run red.

## The javascript side

Covered in full at [the asset pipeline](/staticphp-core/skeleton/asset-pipeline/); here is
just where it sits in the gate. `run_js` installs dependencies with `npm ci` first, but only
when `node_modules` does not already exist - CI always starts clean, so it always pays that
cost, while a local run after the first one skips straight to the checks. Typechecking is
per application (`npm run typecheck` loops `src/*/tsconfig.json`), because each
application's `tsconfig.json` resolves `base/*` against its own copy of the shared assets,
not a shared one. `eslint` and `prettier --check` then run once each, globbed over every
application's `Public/assets/src` in a single invocation.

## The pre-commit hook

`scripts/git_pre_commit.bash` is installed by the `develop` container's startup script,
which symlinks it to `.git/hooks/pre-commit` on every container start - see
[the dev environment](/staticphp-core/skeleton/dev-environment/) for the container setup.
It runs three checks, in order:

1. **Non-ascii filenames.** Every added filename (`git diff --cached --name-only
   --diff-filter=A`) is checked against the printable ascii range; anything outside it
   aborts the commit with a message about portability across platforms.
2. **`code_tests.bash`**, unconditionally - the comment in the hook is explicit that this is
   "the same checks CI runs, so a green commit means a green pipeline." It used to also
   build assets, which the hook's own history notes made every commit take 20+ seconds and
   put generated files into the commit; that responsibility now belongs to the build, not
   the hook.
3. **Whitespace errors**, via `git diff-index --cached --check`.

Any of the three failing aborts the commit.

The hook detects whether it is already running inside the container by checking for
`/.dockerenv`. Inside the container it calls `./scripts/code_tests.bash` directly. Outside
it - committing from the host - it falls back to
`docker compose run --rm develop /srv/app/scripts/code_tests.bash`, so the checks still run
against the same toolchain even if you never opened a shell in the container yourself.

## The five CI jobs

`.github/workflows/ci.yml` triggers on pushes and pull requests against `master` and
`develop`, with `concurrency` cancelling a job's own superseded runs. There is no deploy or
publish workflow in this repository - CI is the gate, nothing pushes an artefact anywhere
from here.

### `php`

Runs on a matrix of PHP 8.4 and 8.5 - 8.4 because that is the floor in `composer.json`
(`"php": ">=8.4"`) and what the Dockerfile builds, 8.5 to keep the skeleton honest against
the current release. Since no application is tracked in the repository - `presets/` is the
source of truth, `src/` is gitignored in the skeleton itself - the job scaffolds one first
(`./staticphp app add Application --preset=twig`) before it can run `code_tests.bash php` at
all. Extensions installed match `docker/app/Dockerfile`, plus `pdo_sqlite`, which the
migration engine's integration tests need for a real (if temporary) database file - no
service container required for that.

### `js`

Installs npm dependencies, scaffolds the same default `Application` (twig preset) so there
is something under `src/*/Public/assets/src` to check, runs `code_tests.bash js`, then
builds the assets for real (`npm run css:build` and `npx rspack build --mode production`) as
a final proof that a production build actually completes.

### `presets`

Matrixed over `twig` and `react` - the comment in the workflow explains why this job exists
separately from `php` and `js` at all: preset sources sit outside `src/`, so nothing else in
the workflow ever compiles them, and without a dedicated job the preset nobody reached for
this quarter could break quietly. For each preset it scaffolds a `Probe` application
(`./staticphp app add Probe --preset=<preset>`), installs whatever the preset added to
`package.json`, builds its assets (`npm run build:dev`), runs its own phpunit suite
(`php vendor/bin/phpunit -c src/Probe/phpunit.xml`), then proves the application actually
answers over http: it starts `php -S 127.0.0.1:8123 -t src/Probe/Public` in the background,
polls it with `curl` until it responds, and asserts a successful `GET /`.

### `upgrade`

The most involved job, and the one worth reading closely if you are relying on
`staticphp upgrade` in a real project - see
[upgrading a project](/staticphp-core/skeleton/upgrading-a-project/) for the command itself.
It checks out full history (`fetch-depth: 0`, since the upgrader reads tags from the
origin), then builds two synthetic tags off the current commit rather than using any real
historical release: `v99.0.0-ci` on the tree as it stands, then a commit appending an
"upstream change" marker to a root config (`rspack.config.js`), a shipped script
(`scripts/clear_cache.bash`) and a preset file that an already-scaffolded application would
otherwise never see again (`presets/_base/Public/dev-router.php`), tagged `v99.1.0-ci`. The
job's own comment is direct about the intent: the unit tests already prove the
classification logic; this job proves that logic survives contact with the actual
repository tree, and a synthetic pair keeps that honest as the tree moves on rather than
rotting against one fixed historical tag.

From there it clones the `v99.0.0-ci` tag into a scratch directory, copies in `vendor`
(rather than symlinking it, so the generated project's autoloader genuinely resolves
`StaticPHP\Skeleton\` to its own `lib/` and the test exercises the generated project, not
the workspace), runs `post_create_project.php --new-project`, edits `rspack.config.js`
locally to simulate a developer's own change, records the manifest as if a real
`create-project` had run against that origin and tag, commits the lot as a developer's
checkout would look, and then runs `staticphp upgrade --to=v99.1.0-ci --yes`.

What it then asserts: the upstream changes to `clear_cache.bash` and `dev-router.php`
(inside `src/Application/`, since it landed in the app-level preset file) came through; the
local edit to `rspack.config.js` is still the local one, and the upstream change to that
same file did **not** land, since `--yes` on a file changed on both sides has to leave it
alone entirely rather than guess; there are no leftover `<<<<<<< mine` conflict markers
anywhere in the tree; and running the exact same upgrade command a second time is a
verified no-op - it prints `already on v99.1.0-ci` and leaves a clean `git diff`. That last
check is what would catch a manifest writer that silently failed to record the new version,
which would otherwise only surface as a mysteriously repeating upgrade prompt for everyone
downstream.

### `build-info`

Checks out full history for the same reason as `upgrade` - `scripts/build_info.bash` derives
its patch number from the commit count, and a shallow clone would silently produce `.1` every
time - then runs the script and prints `.build_info.json`. `scripts/build_info.bash` is not
in `upgrade.json`'s `strip` list, so unlike `scripts/version.bash` or
`scripts/release_tag.bash`, it ships in every generated project.

## Writing tests for your own application

Application tests live under `Tests/` inside the application, mirroring the module tree -
`presets/twig/Tests/Modules/Defaults/Controllers/WelcomeTest.php` and
`DebugTest.php` in the same location are what a generated `Application` (twig preset)
starts with, and they land at `src/Application/Tests/...` once scaffolded.

`Tests/autoload.php` is the suite's bootstrap, referenced from `phpunit.xml`'s `bootstrap`
attribute. It requires `vendor/autoload.php` three directories up, defines `PUBLIC_PATH` as
the application's own `Public/` directory, then requires
`StaticPHP\Core\Bootstrap::AUTOLOAD` - the same module autoloader the front controller uses
- so a test resolves `Defaults\Controllers\Debug` the same way a real request would. Its own
comment notes this replaced an earlier copy of the autoloader that had drifted from the
real one.

`Tests/status_probe.php` exists for the handful of assertions that need a real HTTP status
code rather than a return value - `http_response_code()` is per-process state and the front
controller calls `exit()`, so reading it back means running the front controller in a
separate process. `WelcomeTest` shells out to it with `exec()`, passing a URL and
optionally `--prod`, and the probe wraps `require .../Public/index.php` in output buffering
plus a shutdown function, printing the captured body followed by the numeric status on its
own final line - which is exactly the format `WelcomeTest::request()` parses back out.

The two shipped test classes show the shape worth following: `WelcomeTest` covers the
default route resolving, an explicit url resolving to the same controller, an unresolvable
url reported as an error, and then - through `status_probe.php` - that resolved, missing and
wrong-method requests come back as 200 and 404 respectively, that a production 404 carries
no internal detail (no file path, no exception class name), and that the same 404 in debug
mode does. `DebugTest` covers a controller more directly, calling `Debug::index()` in-process
and asserting against `Config::$items` fixtures it sets up and tears down itself, alongside
one call through `Request::internal()` to prove the url resolves through the router too.
