---
title: Dev environment
description: The docker compose services, the Dockerfile's build stages, what supervisord runs inside the develop container, and the route for working without docker.
sidebar:
    order: 7
---

The skeleton's README calls docker the "suggested" way in, and the reason is mundane: PHP
8.4 with the right extensions, node, and a container user whose files land on the host owned
by you rather than by root. Everything in this page comes from `docker-compose.yml`,
`docker/app/Dockerfile` and the scripts and config those two pull in - there is no separate
provisioning step, no Ansible, nothing else to read.

The other thing worth knowing before any of the mechanics: a project generated from this
skeleton starts with an empty `src/`. The container comes up before an application exists,
and several of the scripts below exist specifically to cope with that.

## The `.env` files

There are two files named `.env`, at different paths, read by different things, and they do
not overlap.

The **root `.env`** (`<project>/.env`, from `.env.example`) is a docker-only file:

| Key               | Meaning                                                    |
| ----------------- | ----------------------------------------------------------- |
| `LOCAL_USER_ID`    | Passed as a build arg; becomes the `appuser` uid in the image |
| `LOCAL_GROUP_ID`   | Same, for the group                                          |
| `GIT_NAME`         | Baked into the development image's global `git config`       |
| `GIT_EMAIL`        | Same                                                          |

The container's non-root user, `appuser`, is created in the `base` Dockerfile stage with
whatever uid and gid these two carry. Match them to your host account (`id -u` / `id -g`)
and a file the container writes into the bind-mounted project - a controller, a migration, a
composer-generated file - lands on the host owned by you instead of by some arbitrary
in-image id. `composer setup` and `composer create-project` fill both in automatically from
the current user when they generate `.env`, via PHP's `posix_getuid()`/`posix_getgid()`, so
in the ordinary case you never edit them by hand; see
[creating a project](/staticphp-core/skeleton/creating-a-project/).

Only the `develop` service's build passes these along as build args. `testing` and `build`
do not, so their images always build `appuser` at the Dockerfile's own defaults
(`LOCAL_USER_ID=1000`, `LOCAL_GROUP_ID=1000`) regardless of what is in `.env`. That matters
for `build`, which does bind-mount a host directory (`/srv/app_mounted`) - which is why
`docker/app/scripts/build.bash` `chmod 777`s the dist directory it writes into rather than
relying on a uid match.

`.env` is also declared as `env_file:` for all three compose services, which injects its
keys as ordinary environment variables at container runtime - independent of, and in
addition to, the build-arg wiring above.

The **per-application `.env`** (`src/<App>/.env`, generated from
`presets/_base/.env.example`) is a different thing entirely: an application config file,
read by the framework itself. `presets/_base/Config/Config.php` loads it through
`symfony/dotenv`, `overload`ing it over `BASE_PATH . '/.env'` (`src/.env` - a file that does
not normally exist, so that load is a no-op) before the application config runs:

```php
<?php

$dotenv = new Dotenv();
if (file_exists(BASE_PATH . '/.env')) {
    $dotenv->load(BASE_PATH . '/.env');
}
if (file_exists(APP_PATH . '/.env')) {
    $dotenv->overload(APP_PATH . '/.env');
}
```

Its keys - `APP_ENV`, `LOGGING_DISPLAY_LEVEL`, `LOGGING_LOG_LEVEL`, `LOGGING_REPORT_LEVEL`,
`LOGGING_REPORT_EMAIL` in the shipped example - feed `$config` directly, for instance
`$config['logging']['display_level']` from `$_ENV['LOGGING_DISPLAY_LEVEL']`. The root `.env`
is never read by the php application at all: `src/.env` is where `Config.php` would look for
a project-wide one, and nothing creates that file. See
[configuration](/staticphp-core/getting-started/configuration/) for how `$config` is built
once these values are in it. `.dockerignore` reflects the split too - the root `/.env` and
`/.env.*` are excluded from every image's build context, while `src/Application/.env*` is
kept in on purpose, because the `build` and `production` stages need it.

## The three services

```yaml title="docker-compose.yml"
services:
    develop: { ... }
    testing: { ... }
    build: { ... }
```

| Service   | Target stage  | Image               | Ports         | Mounts                              | Default command                     |
| --------- | -------------- | -------------------- | ------------- | ------------------------------------ | ------------------------------------ |
| `develop` | `development`  | `staticphp_develop`  | `5500:5000`   | `./:/srv/app:cached`                 | `/srv/meta/scripts/run.bash`         |
| `testing` | `testing`      | `staticphp_testing`  | none          | none                                 | `./scripts/code_tests.bash`          |
| `build`   | `build`        | `staticphp_build`    | none          | `./:/srv/app_mounted:cached`         | `/srv/meta/scripts/build.bash`       |

`develop` bind-mounts the whole project over `/srv/app`, so edits on the host are live in the
container and vice versa - `composer install` and `npm install` run inside it, writing
`vendor/` and `node_modules/` onto that same mount. `testing` and `build` instead `COPY
--chown=appuser:appuser . .` the tree into the image at build time (per the Dockerfile,
below), so they run a fixed snapshot rather than whatever happens to be on disk when you
start them - `.dockerignore`'s exclusion of `/vendor` and `/node_modules` from the build
context is what makes that a clean reinstall rather than a copy of the host's own copies.
`build`'s mount is separate from the copied-in source: it exists only so
`docker/app/scripts/build.bash` has somewhere on the host to write its output archive.

The table's `develop` and `build` commands are the paths as they run inside the image,
`/srv/meta/scripts/...` - the `base` stage `COPY`s the repository's own `docker/app/scripts/`
into `/srv/meta/scripts/` (see the Dockerfile stages below), so from here on this page uses
the repository path, `docker/app/scripts/<name>.bash`, when talking about a script as a file
to read or edit, and the `/srv/meta/scripts/<name>.bash` form only where the distinction
between source and installed copy actually matters.

`testing`'s default command is `./scripts/code_tests.bash`, the same gate used everywhere
else in the project - see [testing and CI](/staticphp-core/skeleton/testing-and-ci/) for what
it actually runs. `build`'s default command is `docker/app/scripts/build.bash`, covered
below with the rest of the build stage.

## Starting it

```bash
docker compose build develop
docker compose up -d --remove-orphans develop
./staticphp app add Application     # or --preset=react
```

The first two build and start the `develop` service; the third is necessary because the
container starts with nothing under `src/` at all - `app add` is [the cli](/staticphp-core/skeleton/cli/)'s
skeleton command, covered in full at
[applications and presets](/staticphp-core/skeleton/applications-and-presets/).
`--remove-orphans` clears out containers left over from services no longer in the compose
file, which matters here because `develop`, `testing` and `build` are meant to be run one at
a time rather than left running together.

Between step two and step three, `docker/app/scripts/serve.bash` - the program supervisord
runs for `php`, see below - has nothing to serve. Read literally, it does this: source
`app_name.bash`, call `resolve_app_name /srv/app`, and if that comes back empty, print a
short hint (`./staticphp app add Application`, and the `APP=<Name>` variant for when there
will be more than one), sleep ten seconds to keep the log readable while you are still
typing, and `exit 1`. Supervisor's default restart policy treats that non-zero exit as
unexpected and starts the program again, so this loop repeats - slowly - until an
application exists. Once `./staticphp app add` has written `src/Application`,
`resolve_app_name` finds it on the very next retry and `serve.bash` `exec`s the real php
server instead. Nothing about the container restarts for this to happen; only the `php`
supervisor program does, and supervisor was already doing that on its own.

## What supervisord runs

`docker/app/conf/supervisord.services.conf` defines three programs, each run from
`/srv/app`, each with `stopasgroup`/`killasgroup` set so the whole process tree stops
together, and each writing straight to `/dev/fd/1` so `docker compose logs` shows all three
interleaved on one stream:

| Program | Command                    | What it is                                                            |
| ------- | --------------------------- | ----------------------------------------------------------------------- |
| `php`   | `/srv/meta/scripts/serve.bash` | The dev server - wrapped rather than run directly because there may be no application yet, as above |
| `css`   | `npm run css:watch`         | Rebuilds stylesheets on change (`scripts/watch_files_dev.bash css`)     |
| `js`    | `npm run js:watch`          | `rspack build --watch --mode development`                               |

The npm scripts themselves - and the rest of the pipeline they belong to - are covered under
[the asset pipeline](/staticphp-core/skeleton/asset-pipeline/); what matters here is that all
three start under supervisord as soon as `develop` comes up, so editing a `.scss` or `.ts`
file rebuilds it without any command of your own.

## Finding the application: `app_name.bash`

Applications are not tracked in the skeleton repository - `presets/` is the source and
`./staticphp app add` or `composer setup` lays one down - so several scripts need to work out
which application under `src/` they are acting on before they can do anything.
`docker/app/scripts/app_name.bash` is that logic, sourced by `serve.bash`, `run-prod.bash`
and `build.bash` alike:

```bash
resolve_app_name() {
    local base="${1:-.}"

    if [ -n "${APP:-}" ]; then
        echo "$APP"
        return 0
    fi

    local found=()
    local dir
    for dir in "$base"/src/*/; do
        [ -f "${dir}Public/index.php" ] || continue
        found+=("$(basename "$dir")")
    done

    if [ "${#found[@]}" -eq 1 ]; then
        echo "${found[0]}"
    fi
}
```

An explicit `APP=Shop` environment variable always wins. Failing that, it globs `src/*/` for
directories holding a `Public/index.php` and, if there is exactly one, prints its name. Zero
matches and more than one match are treated the same way: the function prints nothing at
all, and it is left to whichever script called it to decide what that means - `serve.bash`
retries quietly, while `run-prod.bash` and `build.bash` both call `echo_error` and stop. Set
`APP` once a project holds more than one application; it is the only thing that
disambiguates.

## The Dockerfile stages

`docker/app/Dockerfile` is one file with five stages, built from a shared `base`.

**`base`** starts from `debian:trixie-slim` and installs everything every other stage needs:
locales (`lv_LV.UTF-8`, `en_US.UTF-8`), PHP 8.4 from the Sury apt repository with the
extensions the framework and its drivers need (`curl`, `bcmath`, `xml`, `zip`, `mbstring`,
`gd`, `intl`, `pgsql`, `mysql`, `sqlite3`, `ldap`, `sybase`), a shared `php-app.ini`
(memory, execution time, upload size, `date.timezone = Europe/Riga`), and the non-root
`appuser` built from `LOCAL_USER_ID`/`LOCAL_GROUP_ID`. It also copies `docker/app/conf`,
`docker/app/data` and `docker/app/scripts` into `/srv/meta`, which is where every script
referenced above actually lives inside the container.

**`development`** (`FROM base`) is what `develop` builds. It adds the tools a working
copy needs that a deployed one does not: `git`, `supervisor`, `inotify-tools`, Node.js 22
and npm, and Composer, installed with its published sha384 checked against the installer
before running it. It rewires supervisor's socket, pid and log paths to somewhere
`appuser` can write, renders `supervisord.services.conf` into `/etc/supervisor/conf.d/`
with `envsubst`, switches to `appuser`, and sets the global git identity from `GIT_NAME`
and `GIT_EMAIL`. Its `CMD` is `run.bash`.

**`testing`** (`FROM development`) is everything CI runs, packaged as its own stage
specifically so it does not have to share a build with `build` and the two can run in
parallel. It `COPY --chown=appuser:appuser`s the whole tree in, runs `composer install`
and `npm ci` once at build time, and its `CMD` is `./scripts/code_tests.bash`. That copy is
filtered by `.dockerignore`, which excludes `.vscode/`, `.devcontainer/`, `.agent/` and
`.claude/` but deliberately leaves `.prettierrc` and `.editorconfig` out of the exclusion
list - without them Prettier falls back to its own defaults and the format check this stage
runs would flag every file as misformatted.

**`build`** (also `FROM development`) copies the tree into `/srv/app/build`, runs
`composer install --no-dev -o`, then `npm ci`, `npx update-browserslist-db@latest` and
`npm run build` before deleting `node_modules` - nothing downstream needs the node
toolchain once the assets are compiled. Its `CMD` is `docker/app/scripts/build.bash`,
which is a separate, later step: everything up to `rm -rf node_modules` happens once, when
the image is built; `build.bash` runs every time the `build` service is actually started.
It resolves the application name, requires `src/<App>/.env.prod` to exist, reads a version
number out of `.bumpversion.cfg`, `php -l`s every file under `src/`, and tars `LICENSE`,
`README.md`, `scripts/`, `src/` and `vendor/` into `/srv/app_mounted/dist/pm-<version>.tgz`.
**`.bumpversion.cfg` does not exist anywhere in this repository** - `build.bash` reads a
file that is not there, and `scripts/git_post_receive.bash`, which is not part of the
compose setup at all, hardcodes an `Application/` layout rather than resolving the
application name the way the scripts above do. Both look like leftovers from before the
current release and application-name tooling; neither is exercised by `docker compose up
build` as shipped.

**`production`** (`FROM base`, and labelled `EXAMPLE` in the file itself) is a template to
adapt, not something to deploy as-is. It installs `php-fpm` for the matching PHP version,
points its pool at `appuser` and a tcp `listen = 9100` instead of the default socket, then
copies the compiled output of the `build` stage in with `COPY --from=build`. The layer after
that strips everything a running deployment does not need: `composer.json`/`.lock`,
`phpcs.xml`, `package.json`/`-lock.json`, `eslint.config.mjs`, `rspack.config.js`,
`tsconfig.base.json`, `README.md`, `LICENSE`, `AGENTS.md`, `CLAUDE.md`, the whole `docker/`
and `resources/` directories, and each application's asset sources
(`src/*/Public/assets/src`, `src/*/tsconfig.json`) now that they are already compiled into
`assets/dist`. Its `CMD` is `run-prod.bash`, which resolves the application name, `rsync`s
its `Public/` tree into `./static/` and its `uploads/` into `/srv/media/uploads/`, and then
runs `php-fpm -F -R --force-stderr` in the foreground. `EXPOSE 9100` is fastcgi, not http -
something in front still has to speak that to a browser; see
[without docker](#without-docker) below for the nginx block this stage is meant to sit
behind.

What this page covers stops at that stage. `production` is a starting point to copy and
adapt for your own infrastructure, not a deployable image on its own - there is no reverse
proxy, tls, process supervision beyond `php-fpm` itself, or orchestration here, and none of
that is this page's subject. The strip list above exists because everything on it is
either build-time-only tooling (`composer.json`, `package.json`, `rspack.config.js`,
`tsconfig.base.json`) or source the compiled `assets/dist` has already superseded
(`Public/assets/src`) - none of it does anything at runtime, and leaving it in the image
would only be attack surface and wasted layers.

## The devcontainer

`.devcontainer/devcontainer.json` attaches to the `develop` service and sets
`workspaceFolder` to `/srv/app` - the same path `develop` bind-mounts the project onto, so
opening the folder in VS Code via Remote Containers puts you inside the running container
with the project already there. `shutdownAction` is `none`, so closing the editor window
does not stop the container.

A few of its settings are worth knowing about because they are easy to miss: `*.html` files
are associated with the `twig` language rather than plain HTML, `phpcs.enable` is on and
points at `vendor/bin/phpcs` directly (ignoring `vendor/`), and format-on-save is wired for
PHP (`intelephense`), JavaScript/TypeScript (`prettier`) and Python (`black`) - but
deliberately left off for Twig and HTML files, since Prettier's html formatter does not
understand Twig syntax. The extension list installs `intelephense`, `phpcs`, `php-cs-fixer`,
a Twig grammar, ESLint, Prettier and a couple of others alongside it.

`.vscode/launch.json` ships three debug configurations; the "Attach to Chrome" one, pointed
at `http://local.test/*`, is an untested example value rather than something wired to this
project's actual dev url - adjust it to whatever host you actually use before relying on it.

## Without docker

The alternative is a plain `php -S`, either directly or through the npm wrapper that reads
the same `APP` variable as the container scripts above:

```bash
php -S 0.0.0.0:8081 -t src/Application/Public
npm start          # php -S 0.0.0.0:8081 -t src/${APP:-Application}/Public
```

This needs PHP 8.4+ with `ext-intl`, `ext-mbstring` and `ext-pdo` on the host, plus node and
npm for the asset pipeline, all installed and kept current yourself - none of the version
pinning or extension list the Dockerfile builds is doing anything for you here. It also
means running the css/js watchers and the pre-commit hook's own dependencies (below)
manually rather than getting them from supervisord.

For an actual deployment - as opposed to the built-in server - the skeleton's own README
documents an nginx server block to put in front of a `php-fpm` pool such as the one the
`production` Dockerfile stage sets up; see [Basic Nginx
configuration](https://github.com/gintsmurans/staticphp#basic-nginx-configuration) in the
project README rather than adapting one from scratch.

## The pre-commit hook

`run.bash` - the `develop` service's default command - ends its setup work with:

```bash
ln -sf /srv/app/scripts/git_pre_commit.bash /srv/app/.git/hooks/pre-commit
```

So every time the `develop` container starts, `.git/hooks/pre-commit` is (re-)linked to
`scripts/git_pre_commit.bash` on the host. What that hook actually runs -
`code_tests.bash`, and where it fits alongside CI - is covered under
[testing and CI](/staticphp-core/skeleton/testing-and-ci/); this page only owns the fact
that the container, not you, is what wires it up.
