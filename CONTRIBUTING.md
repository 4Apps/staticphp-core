# Contributing

Pull requests are welcome - bug fixes, drivers, documentation, or a failing test that
demonstrates something is wrong. Small and focused beats large and sweeping; if a change
is big enough to need a discussion, open an issue first so the design is agreed before
anyone writes it.

## Branches and versions

| Branch / ref | What it is                                                            |
| ------------ | --------------------------------------------------------------------- |
| `master`     | The latest released code. What is on Packagist as a stable version.    |
| `develop`    | Active development. Base pull requests here unless it is a hotfix.     |
| tags         | Releases, `vMAJOR.MINOR.PATCH`. A tag is what Packagist publishes.     |

Versioning is [semver](https://semver.org/): a breaking change to anything under `src/` is
a major, new behaviour that keeps existing calls working is a minor, everything else is a
patch. `master` is only ever fast-forwarded from `develop` at release time, so a commit on
`master` is always a commit that shipped.

A hotfix against a release branches from `master`, and is merged back into both `master`
and `develop`.

## Working on it

Everything runs in docker, so no php, composer or database has to exist on the host.

```bash
docker compose build develop
docker compose run --rm develop bash
```

That mounts the working tree, so edits are visible in the container immediately. Inside:

```bash
composer install
./scripts/code_tests.bash
```

`code_tests.bash` is what CI runs, and it is the only thing that has to pass: `php -l`
over every file, `phpcs` against `phpcs.xml`, the phpunit suite, a `--strict-psr`
autoloader dump, and `composer validate`. Individual pieces, if you want them separately:

```bash
composer test                        # phpunit
composer lint                        # phpcs
vendor/bin/phpunit --filter RouterTest
vendor/bin/phpcbf --standard=phpcs.xml src   # fix what phpcs can fix on its own
```

The `testing` compose service bakes the source into the image instead of mounting it,
which is how CI sees the tree. Rebuild it after edits.

`scripts/git_pre_commit.bash` runs the same checks as a hook, if you would rather find out
before pushing.

## Tests

The suite is self-contained: it points `APP_PATH` at this package, so the framework stands
in for an application and no demo app has to exist. The migration and i18n suites run
against a real sqlite database in a temp file rather than a mock, so nothing there needs a
service container either.

New code needs a test. A bug fix needs a test that fails before the fix - if it passes
against the unfixed code, it is not testing the bug.

Two things the suite deliberately covers that are easy to break without noticing:

-   **The package must work without twig.** `composer install --no-dev` followed by
    `php scripts/boot_without_twig.php` is a separate CI job, because the main suite has
    twig installed as a dev dependency and would not catch a hard reference to it.
-   **Optional dependencies.** Anything under `suggest` in `composer.json` belongs to one
    class. If you add such a class, add the entry, and add it to `require-dev` with a test,
    or the file is one nothing ever loads.

## Style

`phpcs.xml` is the authority and CI enforces it. Beyond what it can check:

-   Comments explain *why*, not *what*. Match the density of the file you are in.
-   Always use braces, even for a single statement.
-   No emoji in code, comments or commit messages.

## Databases

The migration drivers cover postgres, mysql/mariadb and sqlite. CI exercises sqlite,
because it needs no service container. If you touch a driver, test it against the real
engine - a throwaway container is enough:

```bash
docker run --rm -e POSTGRES_PASSWORD=secret -p 55432:5432 postgres:17-alpine
docker run --rm -e MYSQL_ROOT_PASSWORD=secret -p 33306:3306 mysql:8.4
```

Point an application's `config['db']['pdo']` at it and drive `staticphp migrate` end to
end: `status`, `apply --dry-run`, `apply`, a deliberately failing migration, then
`forget` / `baseline` / `repair`. The engines differ in ways the drivers exist to paper
over - mysql has no transactional DDL, so a failed migration there is recorded as `FAILED`
and may have partially applied, while postgres rolls the whole thing back.

## Pull requests

-   Base on `develop`, one topic per PR.
-   `./scripts/code_tests.bash` green.
-   Say what breaks. If a change is breaking, it waits for the next major, so it needs to
    be worth it - and it needs an [UPGRADE.md](UPGRADE.md) entry.
-   Update [CHANGELOG.md](CHANGELOG.md) under the unreleased heading.
