---
title: Overview
description: Why StaticPHP ships as a library and a separate project template, and what the template gives you that composer require does not.
sidebar:
    order: 1
---

StaticPHP is two composer packages. `4apps/staticphp-core` is the framework itself - the
`StaticPHP\` namespace, PSR-4, no application, no tooling, `"type": "library"`.
`4apps/staticphp` is the **skeleton**: a `"type": "project"` template that depends on core
and adds everything a working site needs around it, from the `staticphp` cli down to the
docker compose file. The split is the same one `laravel/framework` and `laravel/laravel`
make, and for the same reason - a library that an application depends on cannot also be the
application, because the application is the part you edit and the library is the part you
replace with `composer update`.

The consequence worth internalising before anything else on this page: **the skeleton
repository ships no application at all.** `src/` contains one file, `.gitkeep`. The
application is generated from `presets/` when you create a project, and it is generated
again by every CI job that needs one. That single decision is what most of the rest of this
section is downstream of.

## The two packages

| Package                | Type      | What it holds                                                          | How you install it                    |
| ---------------------- | --------- | ---------------------------------------------------------------------- | ------------------------------------- |
| `4apps/staticphp-core` | `library` | The framework. Router, bootstrap, config, db, i18n, audit, presentation | `composer require 4apps/staticphp-core` |
| `4apps/staticphp`      | `project` | This skeleton. Cli, presets, asset pipeline, docker, CI, upgrader       | `composer create-project 4apps/staticphp ./` |

`composer require` is the right command when you already have an application tree, or when
you intend to build one by hand - see
[installation](/staticphp-core/getting-started/installation/), which covers the library on
its own including its requirements and its optional dependencies.
`composer create-project` is the right command when you are starting a site, and it is what
the rest of this section documents.

A site created from the skeleton still gets core through composer like any other
dependency. The skeleton's `composer.json` requires it by version:

```json title="composer.json (skeleton)"
"require": {
    "php": ">=8.4",
    "ext-intl": "*",
    "ext-mbstring": "*",
    "ext-pdo": "*",
    "4apps/staticphp-core": "^2.1.364",
    "twig/twig": "^3.0",
    "symfony/dotenv": "^8.0"
}
```

So an existing site no longer vendors a copy of the framework, and picking up a framework
release is `composer update` rather than a merge. Picking up a *skeleton* release is a
different problem and has its own machinery - see
[upgrading a project](/staticphp-core/skeleton/upgrading-a-project/).

The skeleton also autoloads a small namespace of its own, `StaticPHP\Skeleton\` from
`lib/`. That is where the cli commands the skeleton adds live, and it is deliberately
independent of core.

### Working on both at once

The split has one cost: a change that spans the framework and the skeleton is a change in
two repositories. Composer's `path` repository type covers it - point the project at a local
checkout of core instead of at Packagist and composer symlinks it into `vendor/`, so edits
in the core checkout are live:

```bash
git clone git@github.com:4Apps/staticphp-core.git ../staticphp-core
composer config repositories.core path ../staticphp-core
composer require 4apps/staticphp-core:dev-develop
```

Undo both halves before committing - `composer require` rewrote the version constraint as
well as adding the repository:

```bash
composer config --unset repositories.core
composer require 4apps/staticphp-core:^2.0
```

Neither belongs in a committed `composer.json`. The path would not exist for anyone who
clones only the one repository, and `dev-develop` is not a release.

## What the skeleton adds

Everything below exists only in the skeleton. Nothing in `4apps/staticphp-core` knows about
any of it.

**The `staticphp` cli.** An executable php file at the project root. It merges three command
registries - the application's own, the skeleton's `StaticPHP\Skeleton\Cli::commands()`
(`app` and `upgrade`), and the framework's - and anything it does not recognise as a command
it dispatches as a url, so scheduled work runs through an ordinary controller. See
[the cli](/staticphp-core/skeleton/cli/).

**Presets and the scaffolder.** An application is created from a preset - `presets/_base`
copied first, then a flavour on top. `twig` gives server-rendered views; `react` gives an
SPA plus a json api. `./staticphp app add` lays one down and `./staticphp app list` shows
what exists. See
[applications and presets](/staticphp-core/skeleton/applications-and-presets/).

**The asset pipeline.** `package.json` drives rspack for js and ts, `sass` for scss, `tsc`
for typechecking and eslint plus prettier for style. Every script globs `src/*`, so a second
application is picked up with no configuration. See
[the asset pipeline](/staticphp-core/skeleton/asset-pipeline/).

**The dev environment.** A `docker-compose.yml` with `develop`, `testing` and `build`
services built from staged `docker/app/Dockerfile`, plus a devcontainer definition and the
`.env` handling. See [the dev environment](/staticphp-core/skeleton/dev-environment/).

**Tests and CI.** `scripts/code_tests.bash` is the single gate, used identically from the
shell, the pre-commit hook and GitHub Actions, running `php -l` first and then phpcs,
phpstan, phpunit, tsc, eslint and prettier. See [testing and CI](/staticphp-core/skeleton/testing-and-ci/).

**The upgrade mechanism.** Because a generated project owns its files outright, moving it to
a later skeleton release is a three-way merge rather than a download. `./staticphp upgrade`
does the skeleton and every application the manifest records, and `.staticphp/manifest.json`
is what records where each tree came from. See
[upgrading a project](/staticphp-core/skeleton/upgrading-a-project/).

## `src/` is empty

This is the part that surprises people, so it gets its own section.

```bash
$ git ls-files src
src/.gitkeep
```

That is the entire tracked contents of `src/` in the skeleton repository. A fresh clone has
no controllers, no views, no `Config/`, no `Public/index.php` and therefore nothing to
serve. The same is true immediately after composer unpacks the package during
`create-project`, which is why `post_create_project.php` runs a scaffold before it hands you
back the prompt.

The reasoning is in the repository itself: in this repository an application is a **build
artifact**. `presets/` is the source. Tracking a generated copy alongside its source would
mean every fix landing twice, and would put `git pull` in permanent conflict with whatever
your application had become since it was laid down.

The practical shape of that: nothing works until something scaffolds. `.github/workflows/ci.yml`
makes the point better than prose does - the `php`, `js` and `presets` jobs each begin with
an explicit scaffold step before they run anything:

```yaml title=".github/workflows/ci.yml"
# No application is tracked - presets/ is the source - so lay one down first
- name: Scaffold the default application
  run: ./staticphp app add Application --preset=twig
```

The `presets` job does the same per matrix entry with `--preset=${{ matrix.preset }}`, and
the `upgrade` job generates a whole project with
`SP_PRESET=twig php scripts/post_create_project.php --new-project` before it has anything to
upgrade. Only `build-info`, which just runs `scripts/build_info.bash`, needs no application.

In a project generated by `composer create-project` none of this applies. The application
was laid down at creation time, it is tracked in git as ordinary source, and it is yours.

## The SKELETON ONLY gitignore block

The flip between "ignored here, tracked there" is done by one clearly marked block in
`.gitignore`, which the create-project script deletes on its way out:

```bash title=".gitignore"
# Applications
###################################################################################################
# SKELETON ONLY - scripts/post_create_project.php deletes this block when it generates a
# project, so that a real application tracks its own src/ as usual.
#
# In this repository an application is a build artifact: presets/ is the source and
# `composer setup` lays one down. Tracking a copy as well would mean every fix landing
# twice, and would make `git pull` fight with whatever the application had become.
/src/*/
!/src/.gitkeep
#
# Same reasoning for the provenance manifest: it records which release a project's files
# came from, and this repository is that release. Generated projects track theirs.
/.staticphp/
# END SKELETON ONLY
```

Two paths, one rule each:

| Path            | In the skeleton repository                                    | In a generated project                       |
| --------------- | -------------------------------------------------------------- | -------------------------------------------- |
| `src/*/`        | Ignored. Generated from `presets/`, never committed             | Tracked. It is the application                |
| `.staticphp/`   | Ignored. This repository *is* the release, so it has no origin  | Tracked. It records which release files came from |

`untrackSkeletonIgnores()` in `scripts/post_create_project.php` strips the block with a
single multiline `preg_replace()` matched between the two markers, and prints
`reset .gitignore (src/ is tracked in a real project)` when it changed something. It is one
of several one-way cleanups that run under `--new-project` and not under `composer setup`;
what else goes is on [creating a project](/staticphp-core/skeleton/creating-a-project/).

The two paths turn up again in `upgrade.json`, whose `ignore` list is what the upgrader is
forbidden to touch:

```json title="upgrade.json"
"ignore": [
    "/src/*/",
    "/.env",
    "/.staticphp/",
    "/vendor/",
    "/node_modules/",
    "/composer.lock",
    "/package-lock.json"
]
```

Same two entries, same reasoning from the other direction: the upgrader must not overwrite
your application with the skeleton's idea of one, and must not overwrite the manifest that
tells it which release your files came from. Applications are upgraded separately, through
their preset. See
[upgrading a project](/staticphp-core/skeleton/upgrading-a-project/).

Everything above means that if you clone `4apps/staticphp` itself - to work on the skeleton
rather than to start a site - you have to scaffold before anything runs, and `git status`
will never show you the application you scaffolded. That is intentional, not a broken
checkout.

## Twig is required here, suggested there

`4apps/staticphp-core` lists `twig/twig` under `suggest` so that an api-only application can
leave it out and not pay for a view layer it never renders - what that costs, and what
`Load::view()` does without it, is on
[installation](/staticphp-core/getting-started/installation/#twig-is-a-suggestion-not-a-requirement)
and [running without Twig](/staticphp-core/guides/without-twig/).

The skeleton requires it outright because both shipped presets render html through it - the
`react` preset's shell is a twig view too, so php still owns the page and can inject the csrf
token before the first request. If your project later turns out not to need twig, it is your
`composer.json` at that point and you can drop the requirement.

## Versions

`.version` at the skeleton root holds `major.minor` - currently `2.0` - and is the only part
edited by hand; the patch number is the commit count, so a release tag never needs a commit
of its own. `.version` also appears in `upgrade.json`'s `once` list, alongside `README.md`,
which means the upgrader reports it and then writes nothing, ever - both files are replaced
outright at creation, so there is never a version of them worth carrying forward. See
[upgrading a project](/staticphp-core/skeleton/upgrading-a-project/).

## Where to go next

[Creating a project](/staticphp-core/skeleton/creating-a-project/) is the next page: what
`composer create-project` actually runs, what `post_create_project.php --new-project` deletes
and why, and what `composer setup` is for afterwards. After that,
[project layout](/staticphp-core/skeleton/project-layout/) walks the tree you end up with.

If you are not starting from the skeleton at all - adding the framework to an existing
application, or serving several applications from one repository - start from
[installation](/staticphp-core/getting-started/installation/) and
[multiple applications](/staticphp-core/guides/multiple-applications/) instead.
