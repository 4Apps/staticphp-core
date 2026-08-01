# Changelog

Notable changes per release. Dates are ISO 8601.

Headings are `major.minor`, because that is the part a human decides and the part that
carries meaning. Tags additionally carry a patch number derived from the commit count -
`v2.0.326` is a release of the 2.0 line - so there are more tags than there are headings
here. See CONTRIBUTING.md.

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
