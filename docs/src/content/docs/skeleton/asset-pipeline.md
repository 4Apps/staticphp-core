---
title: Asset pipeline
description: How typescript and sass under Public/assets/src become the minified bundles a view includes, one rspack compiler per application.
sidebar:
    order: 6
---

Every application in [`src/`](/staticphp-core/skeleton/project-layout/) carries its own
front end - its own typescript, its own sass, its own compiled output - because
`src/<App>/Public/assets/src` is a self-contained unit rather than a shared bundle split by
route. rspack, run once from the project root, discovers every application under `src/`
and builds each one as an entirely separate compiler, into that application's own
`Public/assets/dist`. Nothing here is shared at build time; anything meant to be shared has
to be shared at the source level, by importing it.

## `src` versus `dist`

`Public/assets/src` is the source tree: typescript, jsx and scss, committed. `Public/assets/dist`
is rspack and sass output - `dist/js`, `dist/css`, and `dist/webfonts` for the fontawesome
files `copy-fonts` places there. The skeleton's `.gitignore` excludes
`/src/*/Public/assets/dist/` (and `/src/*/Public/assets/fonts/`), so `dist` is never
committed and has to be produced by a build step before the application can serve anything
out of it. `.build_info.json`, described below, is gitignored the same way.

## Entry point discovery

`rspack.config.js` builds its entry list itself, rather than naming files: it reads every
directory under `src/`, and for each one checks whether
`src/<App>/Public/assets/src` exists. If it does, that directory is scanned for files
matching `/\.(tsx?|jsx?)$/` sitting directly in it - `.d.ts` files are excluded by name -
and **every file found becomes its own entry point**, keyed by its name with the extension
stripped:

```js title="rspack.config.js"
for (const file of fs.readdirSync(srcDir, { withFileTypes: true })) {
    if (file.isFile() === false) {
        continue;
    }
    if (/\.(tsx?|jsx?)$/.test(file.name) === false || file.name.endsWith('.d.ts')) {
        continue;
    }

    entry[file.name.replace(/\.(tsx?|jsx?)$/, '')] = './assets/src/' + file.name;
}
```

In production mode, `output.filename` is `[name].min.js`, so `assets/src/defaults.ts`
compiles to `assets/dist/js/defaults.min.js` - `defaults` is the entry key, and
`[name]` is that key. In development mode the same entry compiles to `defaults.bundle.js`
instead; see `output.filename: isProd ? '[name].min.js' : '[name].bundle.js'`. That
`.min.js` filename is what a view's `js_include` names, per the comment above
`discoverApps()` in the config.

This makes the corollary unavoidable rather than a style choice: **any file sitting
directly in `Public/assets/src` becomes a bundle**, whether that was the intent or not. The
skeleton's own `Application` app ships exactly one top-level file,
`Public/assets/src/defaults.ts`, and puts everything it imports one level down, in
`base/ts/` - `config.ts`, `customPolyfill.ts`, `interfaces.ts`, `utils.ts`. `defaults.ts`
imports from `base/config` and `base/utils` (the `base` alias is covered below) rather than
from a relative path, and re-exports `CONFIG` and `Utils`, assigning `Utils` onto `window`.
A second top-level `.ts` file placed next to `defaults.ts` would not be a shared module -
it would be a second, independent bundle, with its own vendors chunk. Shared code has to
live in a subdirectory such as `base/` and be imported, never dropped next to the entry
files.

The scss side works the same way in spirit even though sass, not rspack, does that build -
see [Sass](#sass) below: `base/scss/theme.scss` sits under `base/` and is pulled in with
`@import`, not compiled on its own.

## One compiler per application

`module.exports` in `rspack.config.js` is a function of `(env, argv)` that returns an
**array** of configuration objects, one per discovered application, filtered down to the
apps whose entry list came out non-empty. Returning an array is rspack's multi-compiler
form: rspack runs every element in one invocation, but each is otherwise an independent
build - its own `context` (the application's `Public` directory), its own `output.path`
(`Public/assets/dist/js`), its own `resolve.alias`, and its own `optimization.splitChunks`.

The alias is the part that actually requires separate compilers rather than one shared
config with a `paths` array:

```js title="rspack.config.js"
resolve: {
    extensions: ['.ts', '.tsx', '.js', '.jsx'],
    alias: {
        base: path.join(app.srcDir, 'base/ts'),
    },
},
```

`app.srcDir` is that application's own `Public/assets/src`, so `import { CONFIG } from
'base/config'` inside `Application` resolves to `Application`'s own `base/ts/config.ts`.
A second application's `defaults.ts` importing the same specifier has to resolve to *its*
own `base/ts`, not `Application`'s - which a single compiler, with one alias table, cannot
do for two applications at once. Output is the same story: a single compiler writing every
application's bundles into one directory would need to be trusted not to clean or
overwrite another application's output, since `output.clean: true` wipes `output.path`
before every build. Splitting into one compiler per application makes each application's
`clean`, `vendors` chunk (from `optimization.splitChunks.cacheGroups.vendors`, matching
`node_modules`) and `commons` chunk (matching `assets/src/base/ts`, `minChunks: 2`)
entirely its own.

`discoverApps()` builds every application by default. Passing `--app <Name>` to rspack, or
setting the `APP` environment variable, filters `apps` down to the one application whose
`name` matches, and the build throws `No application named "<Name>" under src/` if nothing
matches. With neither set, `only` is `''` and every discovered application is built. An
application gets added under `src/` in the first place by running `./staticphp app add`,
via [the cli](/staticphp-core/skeleton/cli/) - see
[applications and presets](/staticphp-core/skeleton/applications-and-presets/) for what
that command actually lays down.

## npm scripts

| script | command | when |
| --- | --- | --- |
| `build` | `npm run copy-fonts && npm run css:build && npm run js:build` | full production build - fonts, css, then typecheck plus a production rspack build |
| `build:dev` | `npm run copy-fonts && npm run css:build && npm run js:build:dev` | same, but `js:build:dev` runs rspack in development mode |
| `js:build` | `npm run typecheck && rspack build --mode production` | production js bundle only, typechecked first |
| `js:build:dev` | `npm run typecheck && rspack build --mode development` | development js bundle only, typechecked first |
| `js:watch` | `rspack build --watch --mode development` | rebuild js on file change; does not typecheck |
| `css:build` | see below | compile every application's `index.scss` |
| `css:watch` | `./scripts/watch_files_dev.bash css` | `inotifywait` loop that reruns `css:build` on any `.scss` change |
| `typecheck` | see [Typescript](#typescript) | loop `tsc --noEmit` over every application's `tsconfig.json` |
| `lint` | `eslint src/*/Public/assets/src` | lint every application's source tree |
| `lint:fix` | `eslint --fix src/*/Public/assets/src` | same, applying fixes |
| `format` | `prettier --write "src/*/Public/assets/src/**/*.{ts,tsx,js,jsx,scss}"` | reformat all application sources |
| `format:check` | `prettier --check "src/*/Public/assets/src/**/*.{ts,tsx,js,jsx,scss}"` | check formatting without writing, for CI |
| `setup` | `npm run composer:init && npm run mkdirs && npm run copy-fonts` | first-time project setup; installs composer dependencies if `composer.lock` is absent, then prepares asset directories and fonts |
| `mkdirs` | `for a in src/*/Public/assets; do mkdir -p "$a/dist/webfonts" "$a/dist/css" "$a/dist/js"; done` | create the `dist` subdirectories for every application |
| `copy-fonts` | `for a in src/*/Public/assets; do mkdir -p "$a/dist/webfonts"; cp -r node_modules/@fortawesome/fontawesome-free/webfonts/* "$a/dist/webfonts/"; done` | copy fontawesome's webfonts into every application's `dist/webfonts` |
| `start` | `php -S 0.0.0.0:8081 -t src/${APP:-Application}/Public` | php's built-in server against one application's `Public`, `Application` by default, overridable with `APP` |
| `build:info` | `./scripts/build_info.bash` | regenerate `.build_info.json` from `.version` and the current git state |

`test` (`./scripts/code_tests.bash`) belongs to
[testing and ci](/staticphp-core/skeleton/testing-and-ci/), not this page.

`package.json` pins `"engines": { "node": ">=22" }`. Its `dependencies` -
`@fortawesome/fontawesome-free`, `@popperjs/core`, `bootstrap`, `core-js` - are the runtime
libraries an application's bundles actually import; everything driving the build itself -
`@rspack/cli`, `@rspack/core`, `typescript`, `typescript-eslint`, `eslint` and its plugins,
`prettier`, `sass`, `dotenv` - sits in `devDependencies` instead, since none of it ends up
in a shipped bundle.

## Typescript

`tsconfig.base.json` sits at the project root and holds compiler settings only - target,
lib, module resolution, strictness - with no `include` and no `paths`. It is deliberately
not named `tsconfig.json`: a settings-only file with no `include` is not a valid standalone
project, and if it were the root `tsconfig.json` it would be the file editors and eslint's
project service resolve for every application's source, rather than that application's own
config.

Each application supplies its own `src/<App>/tsconfig.json`, generated alongside the
application, which extends the base file and adds what has to be per-application:

```json title="src/Application/tsconfig.json"
{
    "extends": "../../tsconfig.base.json",
    "compilerOptions": {
        "baseUrl": ".",
        "paths": {
            "~/*": ["./*"],
            "base/*": ["./Public/assets/src/base/ts/*"]
        }
    },
    "include": ["./Public/assets/src/**/*"],
    "exclude": ["./Public/assets/dist"]
}
```

A single root config cannot express this `base/*` mapping for more than one application:
typescript's `paths` are resolved once, globally, against whichever `tsconfig.json` is in
effect, so a `paths` entry listing several applications' `base/ts` directories would be
tried in order and the first match would win for every application, not just its own. One
`tsconfig.json` per application keeps `base/*` resolving to that application's own copy -
matching the `base` alias rspack builds per compiler, above. It is the same shape of
constraint that gives each application its own front controller and its own `PUBLIC_PATH` -
see [the front controller](/staticphp-core/getting-started/front-controller/) - a single
value in effect at a time cannot serve more than one application, so multi-application
projects end up with one of everything instead of one shared, parameterised copy.

`npm run typecheck` is the loop that ties this together:

```bash title="package.json"
for c in src/*/tsconfig.json; do [ -f "$c" ] || continue; echo "typecheck ${c%/tsconfig.json}"; tsc --noEmit -p "$c" || exit 1; done
```

It runs `tsc --noEmit` once per application config it finds, stopping at the first
application that fails, and both `js:build` and `js:build:dev` run it before invoking
rspack - so a typecheck failure blocks the bundle, not just the CI lint step.

## Sass

`css:build` finds one `index.scss` per application - `src/*/Public/assets/src/index.scss`
- and compiles each with sass directly, one invocation per application:

```bash title="package.json"
for f in src/*/Public/assets/src/index.scss; do [ -f "$f" ] || continue; sass --style=compressed --load-path=node_modules --source-map-urls=relative "$f" "${f%/src/index.scss}/dist/css/index.css"; done
```

`--load-path=node_modules` is what lets `index.scss` `@import` fontawesome and bootstrap by
package name rather than a relative `node_modules` path. Output is compressed, with a
relative source map, and lands at `Public/assets/dist/css/index.css` - the substitution
`${f%/src/index.scss}` strips `/src/index.scss` off the matched path and appends
`/dist/css/index.css` in its place, so the file has to be named `index.scss` for this to
find it at all. `Application`'s own `index.scss` pulls in fontawesome's core, regular and
solid faces, bootstrap, then `base/scss/theme.scss` last, so the application's own rules
can override the vendor defaults.

`css:watch` runs `scripts/watch_files_dev.bash css`, which is an `inotifywait` loop over
the whole project (excluding `node_modules` and `vendor`) that reruns `css:build` whenever
a changed filename matches `\.scss$`. It rebuilds every application's css on any scss
change, not just the one that changed.

## Build-time globals

`buildInfo()` in `rspack.config.js` reads `.build_info.json` from the project root. When
that file exists it is parsed as JSON and used as-is; when it is missing, or fails to
parse, the config falls back to:

```js title="rspack.config.js"
return {
    version: `v${majorMinor}.dev`,
    git_commit_hash: '',
    git_commit_date: '',
};
```

where `majorMinor` is the contents of `.version` (or `'0.0'` if that file is also absent).
`configForApp()` then injects these, plus `APP_ENV` (the rspack mode) and `APP_NAME` (the
application's own name), as compile-time constants through `DefinePlugin`:

```js title="rspack.config.js"
new rspack.DefinePlugin({
    APP_ENV: JSON.stringify(mode),
    APP_NAME: JSON.stringify(app.name),
    APP_VERSION: JSON.stringify(info.version || ''),
    APP_GIT_COMMIT_HASH: JSON.stringify(info.git_commit_hash || ''),
    APP_GIT_COMMIT_DATE: JSON.stringify(info.git_commit_date || ''),
}),
```

These are string replacements at build time, not runtime lookups - `assets/src/globals.d.ts`
declares them (`APP_ENV`, `APP_VERSION`, `APP_GIT_COMMIT_HASH`, `APP_GIT_COMMIT_DATE`) so
typescript accepts the bare identifiers, and `base/ts/config.ts` assigns them straight onto
its exported `CONFIG` object. `eslint.config.mjs` declares the same five names (including
`APP_NAME`, which `globals.d.ts` does not) as readonly globals, alongside three more that
come from the framework's own templates rather than the bundler - `BASE_URL`, `BASE_URI`
and `translateStrings` - so eslint's `no-undef`-style checks do not flag any of them.

## Linting and formatting

`eslint.config.mjs` is a flat config built on `tseslint.config()`, layering
`js.configs.recommended`, `tseslint.configs.recommended` and
`compat.configs['flat/recommended']` (checked against the `browserslist` entry in
`package.json`) before its own overrides. It ignores `node_modules/**`, `vendor/**`,
`build/**`, `dist/**`, `src/*/Public/assets/dist/**`, `src/*/Cache/**`, `presets/**`, and
`*.config.js` / `*.config.mjs`. Both the `import-x/resolver` and the typescript
`parserOptions.project` point at `./src/*/tsconfig.json` - a glob matching every
application's own config, for the same reason `typecheck` loops them: a single project
would resolve `base/*` against only the first application found. The two notable custom
rules are `import-x/no-unresolved: error` and `indent: ['error', 4, { SwitchCase: 1 }]` -
four-space indentation, enforced by eslint rather than left to prettier alone; `no-new` is
turned off.

`lint` and `lint:fix` run `eslint` against `src/*/Public/assets/src` directly, rather than
using eslint's own default discovery.

`.prettierrc` sets `trailingComma: none`, `singleQuote: true`, `printWidth: 120` and
`tabWidth: 4`. `format` and `format:check` run prettier over
`src/*/Public/assets/src/**/*.{ts,tsx,js,jsx,scss}` - the same glob, `--write` versus
`--check`.

## `scripts/build_info.bash`

`build_info.bash` is not in `upgrade.json`'s `strip` list, so it exists in a generated
project the same as in the skeleton repo. It writes `.build_info.json` at the project root
from two inputs: `.version` (hand-edited, `major.minor` only) and the current git state.
The patch component is `git rev-list --count HEAD` - the total commit count, so it advances
on every commit without needing one of its own - and it also writes the full commit hash,
a formatted commit date (`%d.%m.%Y %H:%M`), and a seven-character `asset_version` cut from
the hash:

```bash title="scripts/build_info.bash"
cat > "$OUTPUT_FILE" <<JSON
{
    "version": "v${MAJOR_MINOR}.${COMMIT_COUNT}",
    "git_commit_hash": "${COMMIT_HASH}",
    "git_commit_date": "${COMMIT_DATE}",
    "asset_version": "${SHORT_SHA}"
}
JSON
```

It fails outright on a shallow clone - it checks `git rev-parse
--is-shallow-repository` first and exits with `error: shallow clone - fetch full history
(actions/checkout needs fetch-depth: 0)` rather than silently producing a wrong patch
number from an incomplete history. Run it before a production build (`npm run build:info`)
whenever `.build_info.json` needs to reflect the commit actually being built; a working
copy with no `.build_info.json` at all still builds, falling back to the `.dev` label
described above.
