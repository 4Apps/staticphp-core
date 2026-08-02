---
title: The staticphp cli
description: How ./staticphp finds a command to run, and what it does with the arguments when there isn't one.
sidebar:
    order: 4
---

`./staticphp` is a single php script with a shebang line, run directly rather than through
`php ./staticphp`. Its first two statements define `SAPI = 'cli'` and require
`vendor/autoload.php`, in that order, so composer's autoloader is the very first thing to
run. Everything after that is the file deciding between two jobs: run a named command, or
treat the rest of the arguments as a url and boot the framework as though a browser had
asked for it.

There is no framework class behind the entry point itself - it is procedural php in the
project root, and this page is that file read top to bottom.

## The merged command registry

Three sources feed one array, `$cliCommands`, keyed by command name:

```php
<?php

$localCommands = [];

$cliCommands = $localCommands + StaticPHP\Skeleton\Cli::commands();
```

`$localCommands` is where an application registers its own commands - see
[adding a command](#adding-a-command) below. It starts empty in a generated project; it is
an extension point, not a list of anything shipped. `+` is array union, not merge, so an
entry already present in `$localCommands` wins over one of the same name coming from the
skeleton or the framework.

`StaticPHP\Skeleton\Cli::commands()`, from `lib/Cli.php`, is checked next - and,
critically, *before* the framework is even confirmed to be installed:

```php
<?php

if (isset($cliCommands[$argv[1]])) {
    $class = $cliCommands[$argv[1]];
    exit($class::run(array_slice($argv, 2), __DIR__));
}

if (class_exists(StaticPHP\Core\Cli::class) === false) {
    fwrite(STDERR, "error: this skeleton needs a framework that exports StaticPHP\\Core\\Cli\n");
    fwrite(STDERR, "run: composer update 4apps/staticphp-core\n");
    exit(1);
}

$cliCommands += StaticPHP\Core\Cli::commands();
```

That ordering is deliberate, not incidental. None of the skeleton's own commands touch the
framework, and one of them - `upgrade` - exists specifically to repair a project whose
framework dependency is broken, half-installed, or missing entirely. If dispatch had to pass
a "is core installed" check first, the one command capable of fixing that situation would be
the one command that refuses to run when you need it. Resolving skeleton commands first
means `staticphp upgrade` still works when `composer update 4apps/staticphp-core` is exactly
what's needed to fix things.

Only once neither `$localCommands` nor the skeleton's own registry has claimed the first
argument does the script check `class_exists(StaticPHP\Core\Cli::class)` and, if that
fails, exit 1 with the message above. If the framework class does exist,
`StaticPHP\Core\Cli::commands()` is merged in last and checked the same way.

Nothing in this chain is url routing. Every one of these commands runs before
`StaticPHP\Core\Bootstrap::FILE` is ever required, which is what keeps them off the web:
`$config['routing']` has no notion of a cli-only route, so a command reachable through a
controller would also answer over http - on tools whose job is to change the schema, delete
translation keys, or delete history.

## Where each command comes from

| Command    | Registered by                                | Documented at |
| ---------- | --------------------------------------------- | ------------- |
| `app`      | `StaticPHP\Skeleton\Cli` (skeleton)           | [applications and presets](/staticphp-core/skeleton/applications-and-presets/) |
| `upgrade`  | `StaticPHP\Skeleton\Cli` (skeleton)           | [upgrading a project](/staticphp-core/skeleton/upgrading-a-project/) |
| `migrate`  | `StaticPHP\Core\Cli` (framework)              | [migrations cli](/staticphp-core/database/migrations-cli/) |
| `i18n`     | `StaticPHP\Core\Cli` (framework)              | [extracting strings](/staticphp-core/i18n/extracting-strings/) |
| `audit`    | `StaticPHP\Core\Cli` (framework)              | [audit cli](/staticphp-core/audit/audit-cli/) |
| `sessions` | `StaticPHP\Core\Cli` (framework)              | - |
| `queue`    | `StaticPHP\Core\Cli` (framework)              | - |
| `crypto`   | `StaticPHP\Core\Cli` (framework)              | - |
| `doctor`   | `StaticPHP\Core\Cli` (framework)              | - |

The skeleton contributes exactly two commands: `app`, for creating and listing the
applications under `src/`, and `upgrade`, for merging a later skeleton release into the
project. Everything else - `migrate`, `i18n`, `audit`, `sessions`, `queue`, `crypto`,
`doctor` - is `StaticPHP\Core\Cli::commands()`, unpacked from the framework package. Adding
a command to the framework needs no matching skeleton release, because the skeleton only
ever calls `StaticPHP\Core\Cli::commands()` - it never lists the names itself.

## No arguments, `--help`, `-h`, `help`

Any of these short-circuit before the framework-installed check, so a broken core still
gets a usable command list rather than a fatal error:

```php
<?php

if (count($argv) === 1 || in_array($argv[1], ['--help', '-h', 'help'], true)) {
    if (class_exists(StaticPHP\Core\Cli::class)) {
        $cliCommands += StaticPHP\Core\Cli::commands();
    }

    echo StaticPHP\Skeleton\Cli::help($cliCommands);

    exit(count($argv) === 1 ? 1 : 0);
}
```

Running with no arguments prints the same text as `--help` but exits `1` - being given
nothing to do is a failure; asking for help is not. This is the real output of
`./staticphp --help` against a project with the framework installed:

```text
StaticPHP command line entry point.

Usage:
  staticphp <command> [options]   run one of the commands below
  staticphp <url> [options]       dispatch a url as though it were a request

Commands:
  app        Create, list and upgrade the applications under src/
  audit      Install the audit trail schema and prune old rows
  crypto     Generate key material and re-encrypt stored columns
  doctor     Check php, extensions, databases, migrations and the cache
  i18n       Manage translations: keys, languages, import and export
  migrate    Apply, inspect and repair database migrations
  queue      Run queued jobs, and inspect or retry the failures
  sessions   Install the database session table
  upgrade    Merge a later release of the skeleton into this project

  staticphp <command> --help      what that command takes

Dispatching a url is how scheduled work runs: the controller sees an ordinary
request, so nothing has to be written twice.

  staticphp /defaults/console/refresh
  staticphp /defaults/console/refresh --query "since=yesterday"

Options, when dispatching a url:
  --project <Name>   Application under src/ to use (default: Application)
  --query <string>   Fill $_GET from a query string
  --post <string>    Fill $_POST from a query string, and make it a POST
  --https            Present the request as https
  --help             This text
```

The command list is not hardcoded in `help()` - it iterates the merged `$commands` array it
is handed, sorted with `ksort()`, so a command added anywhere in the chain shows up without
anyone updating this text. Each line's description comes from a `DESCRIPTION` constant on
the command class when it declares one, falling back to the first sentence of its `USAGE`
constant; a command declaring neither prints with a blank description.

`staticphp <command> --help` is not handled by this file at all - each command class parses
its own `--help`/`-h`/`help`. `App\Cli::run()`, for example, matches `'--help', '-h', 'help'
=> self::usage($root, 0)` alongside its `add`, `list` and `upgrade` actions.

## URL dispatch mode

When `$argv[1]` matches neither `$localCommands`, the skeleton's registry, nor the
framework's, the script stops treating arguments as a command line and starts treating them
as an http request. This is the part that has no equivalent in a typical php cli tool: the
remaining arguments become superglobals, and `StaticPHP\Core\Bootstrap::FILE` is required
exactly as it would be from a front controller.

The arguments are walked in a single loop, recognising four flags and one bare value:

| Argument | Effect |
| --- | --- |
| `--query <string>` | Runs the string through `parse_str()` into `$_GET`, and sets `$_SERVER['QUERY_STRING']` to it verbatim. |
| `--post <string>` | Runs the string through `parse_str()` into `$_POST`. This alone is what makes the request a `POST` - see below. |
| `--project <Name>` | Sets `PUBLIC_PATH` to `src/<Name>/Public`. If that directory does not exist, prints `Project '<Name>' not found` and stops. |
| `--https` | Sets `$_SERVER['HTTPS'] = 'on'`. |
| anything else | Assigned to `$_SERVER['REQUEST_URI']` - this is the url segment. |
| `--help` (mid-arguments) | Prints the same help text as above and exits `0`, from inside dispatch mode rather than the top-level check. |

`--project` validates before assigning: `is_dir()` on `src/<Name>/Public` has to be true, or
the process stops with that message. Worth flagging - the failure path is `exit -1;` in the
source, written without parentheses. PHP parses that as a bare `exit;` followed by an
unreachable `-1` expression, not as `exit(-1)`, so the process actually exits `0` even though
it printed an error. A cron job or script checking the exit status of a mistyped
`--project` will see success.

`--project` has a sibling outside the cli: the `APP` environment variable. `--project`
picks the application `./staticphp` itself dispatches a url against; `APP` is the same
choice for everything that is not the cli - the npm scripts (`start` serves
`src/${APP:-Application}/Public`), rspack (`APP=Shop` restricts a build to one application
the same way `--app Shop` does), and the container scripts under `docker/app/scripts`,
which resolve an explicit `APP` before falling back to whatever single application exists
under `src/`. Neither name reaches the other: setting `APP` before running `./staticphp`
has no effect on it, and `--project` has no effect on npm or rspack.

After the loop, defaults fill in whatever a real web server would otherwise have set:
`HTTP_HOST` becomes `localhost`, `SERVER_PORT` follows whether `--https` was given,
`REQUEST_METHOD` is `POST` when `$_POST` is non-empty and `GET` otherwise, and
`HTTP_USER_AGENT` and `REMOTE_ADDR` get fixed stand-in values.

`PUBLIC_PATH` defaults to `src/Application/Public` - the skeleton's own application - unless
`--project` already defined it during the loop:

```php
<?php

if (defined('PUBLIC_PATH') === false) {
    define('PUBLIC_PATH', __DIR__ . '/src/Application/Public');
}

require StaticPHP\Core\Bootstrap::FILE;
```

From there it is an ordinary bootstrap: `APP_PATH` is derived from `PUBLIC_PATH` the same
way it is for a real request, and [the router](/staticphp-core/core/router/) resolves
`REQUEST_URI` to a controller exactly as it would over http. `--project` is how a
multi-application project (see
[applications and presets](/staticphp-core/skeleton/applications-and-presets/)) points a
scheduled job at an application other than the default one.

## Cron

This is what url dispatch mode is for: a controller written to answer a browser request also
answers cron, with nothing duplicated. From the project's own `README.md`:

```
./staticphp /defaults/console/refresh
./staticphp /defaults/console/refresh --query "since=yesterday"
```

From cron, give it the absolute path, since cron does not run with the project directory as
its working directory:

```
*/15 * * * * /srv/sites/example.com/staticphp /defaults/console/refresh
```

How `/defaults/console/refresh` turns into a class and method call - module, controller,
method segments, the rewrite rules in `$config['routing']` - is
[the router](/staticphp-core/core/router/).

## Adding a command

An application adds its own command by populating `$localCommands` in `./staticphp`:

```php
<?php

$localCommands = [
    'reindex' => \Application\Cli\Reindex::class,
];
```

The contract is the same one every command in the merged registry satisfies - a static
`run()` taking the arguments after the command name and the project root, returning the
process exit code. The framework's own command classes spell the parameters
`$arguments, $basePath`; the skeleton's spell them `$args, $root` - only the shape is the
contract, not the names:

```php
<?php

namespace Application\Cli;

class Reindex
{
    public static function run(array $arguments, string $basePath): int
    {
        // ...
        return 0;
    }
}
```

Because `$localCommands` is unioned in ahead of both the skeleton's and the framework's own
registries, a name here shadows a shipped command of the same name rather than colliding
with it - registering `migrate` in `$localCommands` replaces `StaticPHP\Utils\Models\Migrations\Cli`
for this project without touching the framework package.
