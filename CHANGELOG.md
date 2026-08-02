# Changelog

Notable changes per release. Dates are ISO 8601.

Headings are `major.minor`, because that is the part a human decides and the part that
carries meaning. Tags additionally carry a patch number derived from the commit count -
`v2.0.326` is a release of the 2.0 line - so there are more tags than there are headings
here. See CONTRIBUTING.md.

## unreleased

Additive throughout: nothing here changes the meaning of an existing call. The new
subsystems are opt-in and inert until something calls them.

### Added

-   Audit trail: `Audit::insert()`, `update()`, `delete()` and `record()`, writing one
    standard row shape over postgres, mysql/mariadb and sqlite, with `staticphp audit
    install` and `audit prune`, and `Presentation\Models\Audit\AuditTable` to render it.
    Capture is in php and every call is explicit - no observers, no `Db` hooks and no
    database triggers - because a trail that writes itself is one nobody can reason about
    at the call site. `update()` and `delete()` read the affected rows before writing, so
    the old values are recorded without 300 hand written before-fetches. The table name may
    be a resolver, so a deployment that already splits its trail across tables keeps doing
    so against one logical standard.
-   Field encryption: `Crypto::encrypt()`, `decrypt()` and `blindIndex()` over libsodium
    secretbox, with `staticphp crypto key` and `crypto rotate`. Explicit, like the audit -
    nothing is wired into `Db`, so a value cannot be encrypted twice or written in the
    clear because a hook did not fire. Stored values carry a version and key id
    (`sp1:k1:...`), which is what lets a retired key keep decrypting old rows, lets a
    column hold plaintext and ciphertext while a backfill runs, and lets `rotate` tell what
    still needs rewriting. Keys are read from environment variables named in config, never
    from config itself. `blindIndex()` restores equality lookups and uniqueness on an
    encrypted column through a second, separately keyed column; ranges and `LIKE` do not
    come back.
-   Rate limiting: `Throttle::hit()`, `check()` and `clear()` on top of the existing cache
    backends, returning an `Attempt` that also carries the `X-RateLimit-*` and `Retry-After`
    headers. Fixed window, and the boundary and read-modify-write limits are documented on
    the class rather than left to be discovered.
-   `staticphp doctor`: php version, extensions, each connection's pdo driver and whether it
    actually answers, migration state, cache directory permissions, debug left on for
    everybody, and whether the configured audit table is readable. `--offline` skips
    anything that opens a connection and `--strict` fails on warnings for CI. It reads only.
-   `staticphp sessions install`, putting the session schema on the same footing as i18n and
    audit instead of a loose `.sql` file to copy by hand.
-   `Db::transaction(callable)`: commits when the callable returns, rolls back when it
    throws. It catches `Throwable` rather than `Exception`, so a `TypeError` or a failed
    assertion no longer ends the request with the transaction open, and a nested call takes
    a savepoint rather than committing the transaction its caller opened.
-   `Db::select()`, which resolves a `$where` exactly the way `update()` and `delete()` do.
    `buildWhere()` stays private.
-   `StaticPHP\Core\Cli::commands()`, the framework's own command map. The skeleton merges
    it rather than listing commands itself, so adding one here no longer needs a matching
    skeleton release. Application commands win a name collision.
-   `SortNulls::NONE`, for mysql and mariadb, where `NULLS FIRST` / `NULLS LAST` is a syntax
    error rather than a hint that gets ignored.
-   `Menu::firstVisibleMenu()`, and a batch of additions to `Utils\Helpers`: `isBlank()`,
    `isBlankOrNull()`, `valueOrNull()`, `cNumberFormat()`, `localeDateFormat()`,
    `weekOfMonth()`, `yearRangeDateTime()`, `sqlTimestampToDatetime()`,
    `isArrayKeyBlank()`, `isArrayKeyBlankOrNull()`, `padEmptyArrayForDropdown()`,
    `uploadCodeToMessage()` and `simpleArray()`. `trimChars()`, `extractArrayByKeys()` and
    `groupArray()` gained optional parameters and existing calls are unaffected.

### Changed

-   The session schema is `Utils/Files/Sessions/install.pgsql.sql`, replacing
    `Utils/Files/table_sessions_postgres.sql`. Its `timestamp` column is now `timestamptz`:
    `gc()` compares against `CURRENT_TIMESTAMP`, and on a server whose timezone is not utc a
    naive column makes that comparison wrong twice a year for the length of the dst shift.
    Existing installations are unaffected until they choose to migrate.
-   `Utils/Files/table_sessions_mysql.sql` is gone. Core ships no mysql session handler, so
    nothing could use it, and the file was not valid mysql in any case - it quoted
    identifiers with double quotes and stored the timestamp as `int(11)`. `sessions install`
    now says which handlers to use instead when the driver is not postgres.

### Packaging

-   `ext-sodium` declared as `suggest`. It is bundled and enabled in most php builds, and an
    application that never encrypts a column does not need it.

### Tooling

-   phpstan at level 9 with an empty baseline, and `composer validate` and
    `composer dump-autoload --strict-psr`, are part of `scripts/code_tests.bash`. The
    baseline generated when phpstan was introduced has been worked off rather than carried,
    which is what the empty file is there to keep true.

## 2.0 - 2026-08-01

The first release of the framework as a composer package. Everything below is breaking;
[UPGRADE.md](UPGRADE.md) is the step by step, and there is no compatibility shim, because
1.x was never on Packagist as anything but the 2019 `v1.1.0` tag and every known
installation vendors its own copy of `System/`.

### Breaking

-   The framework is `4apps/staticphp-core`, installed by composer, instead of a `System/`
    directory vendored into the application.
-   `Core\` and `System\Modules\` both become `StaticPHP\`, resolved by PSR-4.
    `scripts/upgrade_v2_namespaces.bash` does the mechanical rewrite.
-   `PUBLIC_PATH` must be defined by the front controller. The framework used to walk up
    from its own file to find the application; installed under `vendor/` that would find
    the wrong tree, so it throws rather than guessing.
-   `SYS_PATH` and `SYS_MODULES_PATH` are gone. `SP_PATH` is the package's own directory.
-   The `$project` argument to `Load::` and the autoload lists names an entry in
    `$config['module_paths']` rather than a directory beside the application. `staticphp`
    is reserved and resolves to the framework's own modules. An unknown name throws.
-   `$config['debug_ips']` is gone. It let a request header turn on `display_errors`, full
    exception traces and the query log, because it compared an address the request could
    influence against a list. `$config['debug_check']`, a `callable(): bool` the
    application supplies, decides instead - the framework makes no trust decision of its
    own. It runs before sessions and the database exist, fails closed, and only a strict
    `true` opens the gate.
-   `$config['client_ip']` defaults to `null`, meaning "work it out", the way `base_url`
    does. An application that sets it explicitly keeps its value.
-   `twig/twig` is suggested rather than required. See "Optional dependencies" below.
-   `Load::view()` without a view engine is a real renderer rather than a stub: it extracts
    `$data`, honours `$return = true`, and provides `$config` and `$env` the way twig does.

### Added

-   Database migrations: `staticphp migrate` with `status`, `apply`, `new`, `baseline`,
    `repair` and `forget`, over postgres, mysql/mariadb and sqlite. Checksum drift
    detection, `--dry-run`, `--to=`, `--check` for CI, per-run advisory locking, and
    transaction handling that follows what the engine can actually do.
-   Rewritten i18n: catalogs, locale negotiation, ICU message formatting and a `staticphp
    i18n` command to scan and manage keys.
-   Proxy awareness behind `$config['trust_proxy_headers']`, off by default:
    `Router::requestIsSecure()`, `Router::clientIp()` and `Router::forwardedHeader()`.
    Without it, behind tls termination `base_url` advertised the internal port, the
    session cookie lost its `Secure` flag, and every request looked like it came from the
    proxy. `X-Forwarded-For` entries are counted from the right, one per
    `$config['trusted_proxy_hops']`, because the header is appended to rather than
    overwritten and its leftmost entry is whatever the client claimed.
-   `.gitattributes` keeps tests, tooling and CI out of the dist archive.

### Fixed

-   Excel table output wrote every numeric column as text. The type switch compared a
    `ColumnType` enum against its backing strings (`'int'`, `'float'`), and a backed enum
    is never loosely equal to its own value, so the numeric branch was unreachable. Now
    matched on `ColumnType::INT` and `ColumnType::DECIMAL`, and covered by tests.
-   `uuid4()` uses `random_bytes()` rather than `openssl_random_pseudo_bytes()`: core
    rather than an extension, and it throws instead of returning possibly weak bytes.
-   Whether a request counted as encrypted was decided three different ways in three
    places, so the error page could build `https://` urls for a request whose session
    cookie had just been sent without `Secure`. `Router::requestIsSecure()` is now the one
    answer, and it accepts the non-`on` values php documents for `$_SERVER['HTTPS']`.
-   Injection, path traversal, dispatch and escaping issues found in a security audit of
    the 1.x tree.

### Packaging

-   Declared the optional dependencies that individual classes need - phpspreadsheet,
    mongodb, redis, memcached, apcu, iconv and the pdo drivers - as `suggest`, so they show
    up on Packagist and in `composer suggest` without being installed for anyone.
-   The php constraint is `>=8.4 <9` rather than an open `>=8.4`.
