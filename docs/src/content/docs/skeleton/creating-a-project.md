---
title: Creating a project
description: What composer create-project actually runs, and precisely what it changes about the files it hands you.
sidebar:
    order: 2
---

`composer create-project 4apps/staticphp ./` does two jobs, not one. Composer's part is a
download and an install: unpack the tagged archive, resolve dependencies, write
`vendor/`. Everything that turns that unpacked archive into *your* project - not a copy of
the skeleton with your name typed over it - happens afterwards, in
`scripts/post_create_project.php`, which composer runs as `post-create-project-cmd`. That
script is also the one you run again yourself, as `composer setup`, whenever a project needs
its local files re-created rather than its identity reset. The two are almost the same
script; the difference between them is the whole subject of this page.

## Requirements

`composer.json` requires:

-   PHP 8.4 or newer
-   `ext-intl`, `ext-mbstring`, `ext-pdo`
-   `twig/twig` `^3.0` and `symfony/dotenv` `^8.0` - both required outright here, unlike
    `4apps/staticphp-core` itself, which only suggests twig so an api-only application can
    leave it out. This skeleton is not api-only: it ships views, so it requires the engine
    that renders them.

`package.json` additionally declares `"engines": { "node": ">=22" }` for the scss and
typescript toolchain, and you need composer itself to run the create-project command in the
first place. See [requirements](/staticphp-core/getting-started/installation/#requirements)
for what the core library needs beneath all of this.

## `composer create-project`

```bash
composer create-project 4apps/staticphp ./
```

`dev-develop` in place of the version gets the latest development branch instead of the
newest tag. Composer installs `vendor/` first - dependencies before hooks, always - so by
the time anything of the skeleton's own code runs, its autoloader is already in place.
`composer.json`'s `scripts` block is what runs next:

```json title="composer.json"
"post-create-project-cmd": [
    "@php scripts/post_create_project.php --new-project",
    "@composer update --lock --no-interaction"
]
```

`--new-project` is what marks this run as the one-way kind. Running the same script without
that flag - which is what `composer setup` does - performs only the parts that are safe to
repeat; running it inside a checkout of the skeleton itself with `--new-project` would delete
the skeleton's own tooling, which is why nothing in the skeleton's own workflow ever passes
it.

## What `--new-project` strips and rewrites

Everything below runs once, in the order it appears in the script, and only when
`--new-project` was passed.

**The `.gitignore` `SKELETON ONLY` block is removed.** In the skeleton, `/src/*/` and
`/.staticphp/` are ignored because an application is a build artefact there - `presets/` is
the source, and tracking a generated copy as well would mean every fix landing twice. In a
generated project the application *is* the point, so the whole block - bounded by
`# SKELETON ONLY` and `# END SKELETON ONLY` - is cut out with a regex, and both paths become
trackable. See [the overview](/staticphp-core/skeleton/overview/) for why the skeleton keeps
`src/` empty in the first place.

**Every path in `upgrade.json`'s `strip` list is deleted.** That covers
`scripts/version.bash` and `scripts/release_tag.bash` - the scripts that tag and publish
`4apps/staticphp` to Packagist - `scripts/upgrade_v2_namespaces.bash`, and the skeleton's own
`CHANGELOG.md`, `UPGRADE.md` and `docs/superpowers/`. None of it describes an application
built from the skeleton. The list is read out of `upgrade.json` rather than hardcoded in the
php script because `staticphp upgrade` needs the identical list later, to make sure it never
offers one of these files back into a project that deliberately does not have it; two copies
would drift, and the failure mode would be a stripped release script quietly reappearing on
an upgrade. The full list and how the upgrader uses it are on
[upgrading a project](/staticphp-core/skeleton/upgrading-a-project/). `scripts/build_info.bash`
is not on the list and survives - a generated project still stamps its own build info.

**`composer.json`'s identity is reset.** `name` becomes `vendor/app`, `description` is
cleared, `license` becomes `proprietary`, and `authors` is dropped entirely - none of that
is yours to inherit from the skeleton's own Packagist listing. The `post-create-project-cmd`
entry is removed too, since it can only ever fire once and leaving it in place would be
dead weight at best. `name` happens to be one of the keys composer folds into
`composer.lock`'s content hash, so rewriting it invalidates the lock file; the second line of
`post-create-project-cmd`, `composer update --lock --no-interaction`, exists purely to
recompute that hash without touching a single dependency version.

**`.version` is reset to `0.1`.** `scripts/build_info.bash` reads it to report
`v<major.minor>.<commit count>`; left at the skeleton's own number, a brand new application
would introduce itself as a 2.x release of something it never was.

**`README.md` is replaced outright**, with a short file that points at this documentation
site instead of describing the skeleton's own installation and contribution workflow:

```md title="README.md (generated)"
# Application

Built on [StaticPHP](https://github.com/gintsmurans/staticphp) - framework documentation,
configuration reference and upgrade notes live there.

## Running it

    composer setup            # creates .env and src/Application/.env when missing
    npm install               # scss and typescript toolchain
    docker compose up -d develop

Or without docker, serve `src/Application/Public` directly:

    php -S 0.0.0.0:8081 -t src/Application/Public

## Checks

    composer test             # phpunit
    composer lint             # phpcs
    ./scripts/build_info.bash # stamps .build_info.json from .version and git
```

`composer test` runs `phpunit.xml` at the root, then every `src/<App>/phpunit.xml` it finds;
`composer lint` runs phpcs over `lib`, `src`, `scripts` and `tests`. Full coverage of the
test and lint setup is on
[testing and CI](/staticphp-core/skeleton/testing-and-ci/).

## The first application

Before any of the stripping above, and in both modes, the script asks what your first
application should be:

```php title="scripts/post_create_project.php"
<?php

createFromExample($root, '.env', 'applyLocalIds');
createFirstApp($root, Presets::choose(new Scaffolder($root), DEFAULT_PRESET));
```

`Presets::choose()` resolves the answer from `SP_PRESET`, an interactive prompt, or the
`twig` default, in that order - the full precedence, and why a `--no-interaction` install
never blocks waiting for an input it cannot get, is on
[applications and presets](/staticphp-core/skeleton/applications-and-presets/), along with
what each preset contains and how to add more applications afterwards with
`./staticphp app add`. Setting `SP_PRESET=react` before running create-project is the way to
answer it in advance.

The application is always scaffolded as `src/Application` - the name is hardcoded in
`createFirstApp()`, not asked for. Re-running is harmless: `Scaffolder::apps()` looks for a
`Public/index.php` under `src/*`, and if it finds one the script prints a skip line and does
nothing further, rather than overlaying onto a directory you may have already changed.

## `composer setup`: the re-runnable form

```json title="composer.json"
"setup": "@php scripts/post_create_project.php"
```

Same script, no `--new-project`, so only the idempotent half runs: create `.env` if it is
missing, and lay down the first application if `src/` has none. Nothing here mutates
`composer.json`, `.version`, `README.md` or `.gitignore`, and nothing here can run twice
destructively - `createFromExample()` refuses to overwrite a file that already exists, and
`Scaffolder::create()` refuses to touch a `src/<name>` that already exists.

Run it after a fresh `git clone` of a project someone else generated. The `.env` files are
gitignored, so they never travelled with the clone, and without them the first
`docker compose up` fails on a missing env file and the application boots with no `APP_ENV`.
In that situation the application itself is already tracked in git, so `createFirstApp()`
finds `src/Application` present and only the `.env` creation actually does anything.

## The two `.env` files

Two different `.env.example` files exist, and this script is what turns each of them into a
real file - at different moments, through different code:

| Created | From | To | By |
| --- | --- | --- | --- |
| Every run, `--new-project` or `composer setup` | `.env.example` (root) | `.env` | `createFromExample($root, '.env', 'applyLocalIds')` |
| Whenever an application is scaffolded | `presets/_base/.env.example` | `src/Application/.env` | `Scaffolder::create()` |

`applyLocalIds()` is what fills in the root file's ids as it copies: it calls
`posix_getuid()` / `posix_getgid()` and substitutes them, so the values are already correct
for whoever ran `composer create-project` rather than left at a placeholder. The
per-application file is copied unmodified. Neither write happens if the target already
exists - `createFromExample()` prints a skip line, and `Scaffolder::create()` only copies
when `src/<App>/.env` is absent.

They are not two halves of one configuration: the root file answers to `docker compose` and
the per-application file to `Config.php` through symfony/dotenv, and neither reads the
other's. What each key means, and which process picks it up, is on
[the dev environment](/staticphp-core/skeleton/dev-environment/). Both files are gitignored -
`.env` is matched unqualified in `.gitignore`, which catches it at any depth, including
`src/Application/.env`.

## First run

With docker, [the dev environment](/staticphp-core/skeleton/dev-environment/) covers the
compose services, the Dockerfile stages and what supervisord runs inside `develop`. Without
it, `package.json`'s `start` script is the direct route:

```json title="package.json"
"start": "php -S 0.0.0.0:8081 -t src/${APP:-Application}/Public"
```

```bash
npm install     # scss and typescript toolchain
npm start       # or: php -S 0.0.0.0:8081 -t src/Application/Public
```

`$APP` lets `npm start` serve an application other than `Application` when a project has
more than one; unset, it defaults to the one create-project always scaffolds first.

## What to commit first

With the `SKELETON ONLY` block gone, `src/` and `.staticphp/` are both trackable, and
`.staticphp/manifest.json` is the one worth committing deliberately: it is what
`Scaffolder::create()` writes to record which preset each application came from, and it is
what a later `staticphp upgrade` reads to know which applications exist and what to compare
their files against. Losing it is not a lost confirmation, it is a lost upgrade:
`Manifest::load()` treats an absent or corrupt file as an empty one, and `upgradeApps()`
iterates `$manifest->apps`, so with no manifest there is nothing to iterate and **no
application is upgraded at all** - the skeleton files still move, quietly, and `src/` does
not. Re-adding the entry by hand, or re-running `./staticphp app add` for a name that already
exists, is the way back.

Committing it does not spare you the version-detection prompt on the first upgrade, though.
`Scaffolder::create()` records the preset and `null` for the version - it knows which preset
it copied, not which release that preset was - and nothing writes `skeleton` at creation
either, so the first run of `staticphp upgrade` has no recorded tag for either tree and
scores them against the origin's tags before asking you to confirm the match. From then on
the manifest carries real tags. See
[upgrading a project](/staticphp-core/skeleton/upgrading-a-project/). The `.env` files stay
out of git in both cases, generated project or not.
