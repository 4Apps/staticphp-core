---
title: Upgrading a project
description: How staticphp upgrade merges a later skeleton release into a project you have already edited, and what it will and will not overwrite.
sidebar:
    order: 8
---

`composer create-project` hands you a copy and then walks away. Every file in the tree is
yours outright, git records the first commit as though you had typed it, and there is nothing
anywhere that says `rspack.config.js` came from a template. Six months later the skeleton has
a fixed Dockerfile, a reworked `scripts/code_tests.bash` and a preset that gained a file - and
none of it can reach you, because there is no upstream to pull from. `git pull` is not
available: your project has no shared history with the skeleton at all.

There are also two trees that need this, not one. The skeleton files at the project root are
the obvious half. The other half is `src/<App>/`, which was scaffolded out of `presets/` at
creation and has been a plain directory ever since - a change to `presets/_base/` never
reaches an application that was already generated from it. Both halves are handled by the
same code with different directories.

## The idea

An upgrade is a three-way merge. The novel part is where the merge base comes from: it is the
upstream release the project currently sits on, exported fresh out of the skeleton's own
repository, not a pristine copy of your files kept somewhere on the side.

That one decision is what makes the rest work:

-   A file you never touched is byte-identical to its base, so the tool can take the new
    version without asking. Most of a release is this.
-   A file you did edit has a genuine common ancestor, so `git merge-file` has three real
    inputs and produces a real merge rather than a choice between two whole files.
-   A file both sides changed is the only thing that reaches you, and it reaches you knowing
    exactly what upstream changed since your copy diverged.

The alternative - stashing a baseline copy of the generated tree inside the project - was
rejected. It doubles the size of every project, it goes stale the moment somebody
`rm -rf`s it or a merge conflict lands in it, and it is one more thing to keep in git that
nobody understands. Exporting the base from the origin instead means the only state a project
keeps is a tag name, and even that is recoverable by comparing the tree against the published
tags.

The pieces live in `lib/Upgrade/`: `Upgrader` compares three directories and knows nothing
about git, tags or terminals, `OriginRepo` is read-only access to the skeleton's repository,
`Ownership` holds the rules, and `Cli` is the only part that talks to a person.

## Where a tag comes from

Every tag on this page - `v2.0.332`, `v99.1.0-ci`, whatever `--to` takes - is cut from the
skeleton repository itself, not from a generated project. `.version` at the repository root
holds a hand-edited `major.minor`; `scripts/version.bash` appends the current commit count to
that, so a version reads as `<major.minor>.<commit-count>` and moves forward on every commit
without anyone maintaining a number by hand. `scripts/release_tag.bash` runs that derivation
and creates the annotated tag on the current commit, then deliberately stops - it prints the
`git push` that would make the tag public rather than running it itself, so publishing stays a
separate act. Both scripts are in `upgrade.json`'s
[`strip` list](#upgradejson-and-the-three-ownership-classes), so they exist in the skeleton
repository and not in a project generated from it - which is exactly why a reader of this page
consumes tags but has no way to create one.

## `.staticphp/manifest.json`

The entire persisted state of the feature. It is written by `StaticPHP\Skeleton\Manifest`,
lives at `.staticphp/manifest.json`, and is **tracked in git in a generated project** -
`.gitignore`'s `/.staticphp/` entry sits inside the `# SKELETON ONLY` block that
`scripts/post_create_project.php` deletes, because the skeleton repository *is* the release
and has nothing to record, while your project does.

```json title=".staticphp/manifest.json"
{
    "version": 1,
    "origin": "https://github.com/gintsmurans/staticphp.git",
    "skeleton": "v2.0.332",
    "apps": {
        "Application": {
            "preset": "twig",
            "version": "v2.0.332"
        }
    },
    "pinned": {
        "rspack.config.js": "v2.0.328"
    }
}
```

| Field | Meaning |
| --- | --- |
| `origin` | Where to fetch releases from. Defaults to `https://github.com/gintsmurans/staticphp.git`; point it at a fork and the whole feature follows. |
| `skeleton` | The tag the root files are currently on. The merge base for `staticphp upgrade`. |
| `apps.<Name>.preset` | Which preset the application was scaffolded from, so an upgrade knows which tree to compose. |
| `apps.<Name>.version` | The tag that application's preset is currently on. |
| `pinned` | Per-file merge base overrides, keyed by path relative to the project root. |
| `version` | Written as `1`. `Manifest::load()` does not currently read it back - it is a placeholder for a future format change, not a working migration. |

Straight after `create-project`, both version fields are `null`:

```json title=".staticphp/manifest.json, as generated"
{
    "version": 1,
    "origin": "https://github.com/gintsmurans/staticphp.git",
    "skeleton": null,
    "apps": {
        "Application": {
            "preset": "twig",
            "version": null
        }
    },
    "pinned": {}
}
```

`Scaffolder::create()` writes the application entry with `version: null` on purpose. A project
unpacked by composer genuinely does not know which tag it came from - `.version` holds only
`major.minor`, and the archive carries no ref - so guessing would be worse than admitting it.
`skeleton` is never written at creation at all; it stays `null` until the first upgrade
resolves it by [detection](#when-there-is-no-manifest) and then records the tag it moved to.

`pinned` is the one per-file entry, and it exists so that skipping a conflict is a deferral
rather than a decision. A file you declined keeps the tag you declined it at as *its* merge
base, so next time the upgrader still sees your copy as a local change and offers it again,
instead of concluding that your version and the upstream one have converged. Losing the entry
costs one extra prompt.

**The manifest is an optimisation, not a dependency.** `Manifest::load()` never fails: an
absent, unreadable or corrupt file is treated identically to a missing one, because the
recovery is the same for all three and refusing to run would leave no way back. Without it,
`origin` falls back to the default and the versions are worked out by matching your tree
against the origin's tags.

## `upgrade.json` and the three ownership classes

The rules about what an upgrade may touch ship with the skeleton rather than being compiled
into the tool, and they are read from **the release being upgraded to** - via `git show`
against the cached clone, not off your disk. The incoming release is the one that knows what
it owns, so a project created before a rule existed still upgrades correctly. Your own
`upgrade.json` is only used as a fallback when the target tag has none.

```json title="upgrade.json"
{
    "ignore": [
        "/src/*/",
        "/.env",
        "/.staticphp/",
        "/vendor/",
        "/node_modules/",
        "/composer.lock",
        "/package-lock.json"
    ],
    "once": [
        "/README.md",
        "/.version"
    ],
    "strip": [
        "/scripts/version.bash",
        "/scripts/release_tag.bash",
        "/scripts/upgrade_v2_namespaces.bash",
        "/docs/superpowers/",
        "/UPGRADE.md",
        "/CHANGELOG.md"
    ],
    "app": {
        "ignore": [
            "/.env",
            "/Cache/",
            "/Files/"
        ],
        "once": [],
        "strip": []
    }
}
```

**`ignore`** - never examined. `/src/*/` is what keeps `staticphp upgrade` out of your
applications entirely; they are upgraded by their own pass, against a composed preset. Lock
files are in here because a text merge of a generated file is worse than useless.

**`once`** - examined and reported, but never written. These are the files
`post_create_project.php` replaced wholesale at creation: your `README.md` is about your
application, not about the skeleton. Without this class they would collide on every upgrade
forever, with nothing worth salvaging in the merge.

**`strip`** - removed when a project is generated, so never offered back. This is
**the same list `scripts/post_create_project.php` reads**, through
`Ownership::load(...)->stripList()`. Deliberately one list in one file: two copies would
drift, and the symptom of drift would be an upgrade quietly restoring a Packagist release
script into somebody's application. See
[creating a project](/staticphp-core/skeleton/creating-a-project/) for the deletion side.

Patterns are anchored at the tree root. A trailing slash means "this directory and everything
under it"; without one the pattern must match the whole path. `*` matches one path segment
and stops at a slash - so `/src/*/` covers `src/Shop/Config/App.php` but not `src/.gitkeep`,
and `/.env` covers `.env` but neither `.env.example` nor `docker/.env`.

The nested `app` block is the whole ruleset for an application upgrade. When the upgrader is
working on `src/<Name>/`, the root lists are discarded and only `app` applies - they describe
paths that do not exist inside an application. If `upgrade.json` is missing on both sides, the
root falls back to a deliberately conservative built-in set - `/src/*/`, `/.env`,
`/.staticphp/`, `/vendor/` and `/node_modules/` ignored, `README.md` and `.version` once - and
an application falls back to `/.env`, `/Cache/`, `/Files/`.

## The classification table

`Upgrader::classify()` walks the union of the base and target trees, skips anything `ignore`
or `strip` matches, and puts each remaining path into one of eight outcomes. Comparison is by
size then SHA-256 of the file contents - never by timestamp, and never by diffing - so "the
same" means byte-identical. Paths where there is nothing to do are absent from the plan
rather than reported as unchanged.

| In base | In your tree | In target | Relation | Outcome |
| --- | --- | --- | --- | --- |
| no | no | yes | - | `add` |
| no | yes | yes | yours == theirs | *nothing* |
| no | yes | yes | yours != theirs | `conflict` |
| yes | yes | yes | base == target, yours == base | *nothing* |
| yes | yes | yes | base != target, yours == base | `update` |
| yes | yes | yes | base == target, yours != base | `keep` |
| yes | yes | yes | base != target, yours != base, yours == theirs | *nothing* |
| yes | yes | yes | base != target, yours != base, yours != theirs | `conflict` |
| yes | yes | no | yours == base | `delete` |
| yes | yes | no | yours != base | `orphaned` |
| yes | no | yes | - | `gone` |
| yes | no | no | - | *nothing* |

Then one override: if the path matches `once` and the outcome is anything other than `gone`
or `keep`, it becomes `once`. The exclusions matter - `once` must not resurrect a file you
deleted, and a file only you changed is already being left alone, so relabelling it would just
be noise.

What each outcome does:

| Outcome | Group heading in the plan | Effect |
| --- | --- | --- |
| `update` | changed upstream, untouched here | Written. |
| `add` | new upstream file | Written. |
| `delete` | removed upstream | Removed, and a directory left empty by the removal is pruned. |
| `keep` | your change, untouched upstream | Nothing. Reported so you know it exists. |
| `orphaned` | removed upstream, but you changed it - kept | Nothing. Said out loud rather than silently dropped. |
| `gone` | you deleted it - staying deleted | Nothing. |
| `once` | yours since creation, never upgraded | Nothing, ever. |
| `conflict` | changed on both sides | The only outcome that asks you a question. |

`add` and `update` are the `Upgrader::SILENT` set. `add` is in it because a file your project
does not have cannot be destroyed by writing it. `delete` is applied alongside them under the
same single confirmation, but it is a removal rather than a write and is tracked separately.

Two details of applying: the executable bit is carried across from the target, because presets
ship runnable scripts and `copy()` does not preserve the mode; and symlinks are skipped rather
than followed. Only the base and target trees are ever walked, never your project, so
`vendor/` and `node_modules/` cost nothing.

## The commands

```bash
staticphp upgrade [--to=<tag>] [--from=<tag>] [--dry-run] [--yes]
staticphp app upgrade <Name> [--to=<tag>] [--from=<tag>] [--dry-run] [--yes]
```

| Flag | Effect |
| --- | --- |
| `--to=<tag>` | Release to move to. Defaults to the newest tag the origin has, by `git tag --list --sort=-v:refname`. An unknown tag is refused, with the available list printed. |
| `--from=<tag>` | Treat this tag as the base, overriding the manifest and skipping detection. |
| `--dry-run` | Print the plan and stop, before any file is written or removed. |
| `--yes`, `-y` | Apply the silent changes and the deletions without asking, and defer every collision. It never resolves one unattended - that is what makes it safe in a script. |
| `--help`, `-h` | The usage text. |

Anything else is rejected with `Unknown option: <arg>` before any work starts.

**`staticphp upgrade` also carries every application the manifest records onto the same tag**,
after the root files. That is one command rather than two on purpose: the clean-worktree guard
below would refuse a follow-up `staticphp app upgrade` until the first run had been committed,
which is a miserable way to find out that `presets/` moved and your application did not.
Applications with no `preset` recorded, or whose `src/<Name>/` no longer exists, are passed
over; one whose base cannot be worked out prints `Skipping src/<Name>.` and the rest continue.

`staticphp app upgrade <Name>` does one application on its own, against the composed preset -
`presets/_base/` then `presets/<preset>/`, exported from the repository at each tag rather
than read off your disk, because `staticphp upgrade` may already have moved the on-disk copy
forward and that would quietly make the merge base wrong. If the manifest has no preset
recorded for the application, the preset whose composed tree it most resembles is used, and
the guess is printed.

Both entry points sync first: a `--mirror` clone of the origin under
`$XDG_CACHE_HOME/staticphp/` (or `$HOME/.cache/staticphp/`), shared by every project on the
machine and fetched on subsequent runs. It is derived data - deleting it costs one clone - and
the sync happens before anything is written, so an unreachable origin fails the command
instead of half-upgrading the project. Trees are exported with `git archive` into `0700`
scratch directories and removed by a shutdown handler however the process ends.

Running against a tree already on the target prints `<label>: already on <tag>.` and stops.

## What it looks like

The plan is grouped by outcome, conflicts last, paths sorted, and at most eight shown per
group before the group is summarised. The shape of it, for an invented pair of releases:

```text
Syncing https://github.com/gintsmurans/staticphp.git

Upgrading skeleton files v2.0.328 -> v2.0.332

  update     12  changed upstream, untouched here
                    docker-compose.yml
                    docker/app/Dockerfile
                    package.json
                    presets/_base/Public/dev-router.php
                    presets/_base/Public/index.php
                    scripts/clear_cache.bash
                    scripts/code_tests.bash
                    tsconfig.base.json
                    ... and 4 more
  add         1  new upstream file
                    docker/app/scripts/serve.bash
  delete      1  removed upstream
                    scripts/watch_files_dev.bash
  keep        2  your change, untouched upstream
                    .env.example
                    scripts/git_pre_commit.bash
  once        1  yours since creation, never upgraded
                    README.md
  conflict    1  changed on both sides
                    rspack.config.js

Apply the 14 non-conflicting changes? [Y/n]
```

Answering anything other than empty, `y` or `yes` aborts with `Aborted.` and exit code 1 -
before anything is written.

Then one prompt per collision:

```text
rspack.config.js - changed upstream and locally
  [d] diff  [m] merge  [t] take theirs  [k] keep mine  [s] skip (default):
```

| Key | What it does |
| --- | --- |
| `d` | `git diff --no-index --color` between your file and the target's, then asks again. Not a decision. |
| `m` | A real three-way merge: `git merge-file -L mine -L base -L theirs`, writing in place. **It can leave conflict markers** - `<<<<<<< mine`, `\|\|\|\|\|\|\| base`, `>>>>>>> theirs` - and when it does, the file is listed again at the end and the command exits non-zero. Clears any pin. |
| `t` | Takes the target's version wholesale. Clears any pin. |
| `k` | Your copy stands, untouched. A decision rather than a deferral, so no pin is recorded and the next run compares against the new base instead of re-opening this one. |
| `s` | Defers. Records the file in `pinned` at the tag you are upgrading *from*, so the next run still sees it as a local change and offers it again. |

`s` is the default: anything that is not `d`, `m`, `t` or `k` is treated as a skip, so hitting
return is always the safe outcome. `--yes` behaves as though every collision were skipped.

The summary:

```text
Done: 13 written, 1 removed.

Run next:
  npm install
```

If a merge left markers, they are listed under `Conflict markers left in:` with full paths,
followed by `Resolve them before committing.`, and the command exits 1.

`Run next:` is derived from the paths that were applied: `composer.json` prints
`composer update`, `package.json` prints `npm install`. The upgrader changes the manifests,
never `vendor/` or `node_modules/`. One gap to know about - a conflict resolved with `m` is
merged in place rather than applied through that set, so merging `composer.json` yourself does
not produce the reminder. Run it anyway.

One consequence worth knowing: a non-zero exit from the skeleton pass stops the run before the
applications are reached, and a non-zero exit from one application stops the ones after it.
Resolve the markers, commit, and run the command again.

## Guards

Checked in this order, before any tree is exported. None of them is overridable, and there is
no `--force`:

| Condition | Why there is no escape hatch |
| --- | --- |
| `git` or `tar` missing from PATH | Both are how the work is done: `git` clones, archives, diffs and merges; `tar` unpacks the archives. There is no fallback implementation to fall back to. |
| Run inside the skeleton itself | Detected by `composer.json`'s `"name"` being `4apps/staticphp`. There is nothing upstream of the skeleton to merge into it. |
| Not a git repository | `git checkout .` is the documented undo. Without a repository there is no undo, so the command refuses rather than making an unrecoverable change. |
| Uncommitted changes in the worktree | Same reason, from the other side: if your own edits are uncommitted, `git checkout .` would discard those too. The first ten paths from `git status --porcelain` are printed so you can see what is in the way. |

This is why there is no backup directory, no `.bak` files and no rollback code anywhere in
`lib/Upgrade/`. The guard makes the repository the backup, and a second mechanism would only
be a second thing to get wrong. If an upgrade goes badly:

```bash
git checkout .
```

One more behaviour that is not a guard but works like one: if the run is not attached to a
terminal and `--yes` was not passed, nothing is written and the command says so -
`Not a terminal - nothing written. Re-run with --yes to apply the silent changes.`

## When there is no manifest

If `--from` was not given and the manifest records no version for the tree - a project created
before any of this existed, or one whose manifest was lost - the upgrader scores the tree
against every tag the origin has. For each candidate it exports the tag (or composes the
preset out of it), counts how many of the files the two trees have in common are identical,
and takes the best ratio. Paths that are ignored, and paths the reference has but your tree
does not, count for neither side, so deleting a file does not make your project look like a
different release. Tags that predate the preset simply cannot be composed and are passed over.

```text
No recorded version for skeleton files - matching it against the origin's tags.
Best match: v2.0.328 (241 of 247 files identical)
Use it as the upgrade base? [Y/n]
```

Against the real skeleton the winner is unambiguous - a correct tag scores an order of
magnitude better than a neighbouring release. Declining aborts that tree. Note the exception:
in a non-interactive run there is nobody to confirm with, so the best match is used as-is; the
place to be careful is a scripted `--yes` upgrade of a project with no recorded version. If
nothing matches at all, the command stops and tells you to pass `--from=<tag>`.

If you know the answer, `--from` skips the whole search.

## What this does not do

It does not upgrade the framework. `4apps/staticphp-core` is an ordinary composer dependency
of your project and moves with `composer update` like any other package - the skeleton
upgrader only ever touches files the skeleton itself ships. The two are deliberately
independent: see [overview](/staticphp-core/skeleton/overview/) for the split.

It also has nothing to say about application code. Moving an application from StaticPHP 1.x to
2.0 is a source-level migration - namespaces, renamed classes, changed signatures - and is
covered by [upgrading from 1.x](/staticphp-core/guides/upgrading/). A different problem
entirely, with a different tool.

## After an upgrade

`git diff` against the commit the guard forced you to make is the entire record of what
changed, which is the point of refusing to run without one. Then, when the summary asked for
them:

```bash
composer update    # composer.json was merged
npm install        # package.json was merged
```

The tool prints whichever apply, so you do not have to remember which files were involved.
