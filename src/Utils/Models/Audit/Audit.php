<?php

namespace StaticPHP\Utils\Models\Audit;

use PDOStatement;
use StaticPHP\Core\Models\Config;
use StaticPHP\Core\Models\Router;
use StaticPHP\Utils\Models\Db;

/**
 * The audit trail.
 *
 * Nothing here happens on its own. There is no hook in Db, no observer and no listener:
 * every row in the trail is the result of a call the application wrote. The convenience is
 * that insert(), update() and delete() read the affected rows themselves, so the before-and
 * -after select is written once here rather than at every call site - which is where it
 * goes wrong, because a hand-written select only covers the columns somebody remembered.
 *
 * @example Audit::update('woodchips', $data, ['id' => $id], module: 'dobeleseko');
 */
class Audit
{
    /**
     * Defaults for anything config['audit'] does not say, so the trail works before an
     * application has written a config file for it.
     *
     * @var array<string, mixed>
     * @access private
     */
    private const DEFAULTS = [
        'connection' => 'default',
        'table' => 'audit_log',
        'strict' => true,
        'max_rows' => 1000,
        'id_key' => 'id',
        'actor' => null,
        'context' => null,
        'exclude' => [],
    ];

    private static ?Store $store = null;
    private static ?string $requestId = null;

    /** @var ?array<string, mixed> */
    private static ?array $settings = null;

    /**
     * Identifies everything one request changed.
     *
     * Generated once per process, so a request that touches five tables leaves five rows
     * that can be read back as one action. Under cli it covers the whole command run, which
     * is the useful reading for a nightly import.
     *
     * @access public
     * @static
     * @return string
     */
    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(16));
        }

        return self::$requestId;
    }

    /**
     * Forget the cached settings, store and request id.
     *
     * @access public
     * @static
     * @return void
     */
    public static function reset(): void
    {
        self::$store = null;
        self::$settings = null;
        self::$requestId = null;
    }

    /**
     * The configured store.
     *
     * @access public
     * @static
     * @return Store
     */
    public static function store(): Store
    {
        if (self::$store === null) {
            $table = self::setting('table');
            if (is_string($table) === false && is_callable($table) === false) {
                throw new AuditError("config['audit']['table'] must be a table name or a callable");
            }

            self::$store = new Store(self::connection(), $table);
        }

        return self::$store;
    }

    /**
     * Replace the store, for tests and for an application that assembles its own.
     *
     * @access public
     * @static
     * @param  ?Store $store
     * @return void
     */
    public static function setStore(?Store $store): void
    {
        self::$store = $store;
    }

    /**
     * Write one event, filling in the actor, the request context and the request id.
     *
     * @access public
     * @static
     * @param  AuditEvent $event
     * @return void
     */
    public static function record(AuditEvent $event): void
    {
        try {
            self::store()->write($event->withResolved(self::actor(), self::context(), self::requestId()));
        } catch (\Throwable $exception) {
            self::fail($exception);
        }
    }

    /**
     * Insert a row and record it.
     *
     * @access public
     * @static
     * @param  string                    $table
     * @param  array<string, mixed>      $data
     * @param  string                    $module
     * @param  ?string                   $entityId  Overrides the key read from $data
     * @param  ?string                   $returning Passed to Db::insert()
     * @param  list<string>              $tags
     * @param  ?array<string, mixed>     $context
     * @param  ?string                   $connection
     * @return mixed The Db::insert() result
     */
    public static function insert(
        string $table,
        array $data,
        string $module = '',
        ?string $entityId = null,
        ?string $returning = null,
        array $tags = [],
        ?array $context = null,
        ?string $connection = null
    ): mixed {
        $connection = ($connection ?? self::connection());
        $result = Db::insert($table, $data, $connection, $returning);

        [$old, $new] = Diff::between(null, $data, self::exclude($table));

        self::record(new AuditEvent(
            event: AuditEvent::CREATED,
            entityType: $table,
            entityId: ($entityId ?? self::insertedId($data, $result, $connection)),
            module: $module,
            oldValues: $old,
            newValues: $new,
            tags: $tags,
            context: $context
        ));

        return $result;
    }

    /**
     * Update rows and record one event per row actually changed.
     *
     * The rows are read before the update, so what is recorded is the change that happened
     * rather than the change that was requested.
     *
     * @access public
     * @static
     * @param  string                    $table
     * @param  array<string, mixed>      $data
     * @param  array<int|string, mixed>  $where As accepted by Db::update()
     * @param  string                    $module
     * @param  list<string>              $tags
     * @param  ?array<string, mixed>     $context
     * @param  ?string                   $connection
     * @return PDOStatement
     */
    public static function update(
        string $table,
        array $data,
        array $where,
        string $module = '',
        array $tags = [],
        ?array $context = null,
        ?string $connection = null
    ): PDOStatement {
        $connection = ($connection ?? self::connection());
        $before = self::rows($table, $where, $connection);

        if ($before === []) {
            self::notice("update on \"{$table}\" matched no rows");
        }

        // Checked before the update runs. A mistyped condition that matches the whole table
        // should not become a write plus half a million audit rows.
        $audit = self::withinLimit($table, count($before));

        $statement = Db::update($table, $data, $where, $connection);

        if ($audit === true) {
            $exclude = self::exclude($table);
            foreach ($before as $row) {
                [$old, $new] = Diff::between($row, $data, $exclude);
                if ($new === null) {
                    continue;
                }

                self::record(new AuditEvent(
                    event: AuditEvent::UPDATED,
                    entityType: $table,
                    entityId: self::rowId($row),
                    module: $module,
                    oldValues: $old,
                    newValues: $new,
                    tags: $tags,
                    context: $context
                ));
            }
        }

        return $statement;
    }

    /**
     * Delete rows, recording each one in full.
     *
     * Unlike an update there is no second chance to find out what was there, so the whole
     * row goes into old_values - minus anything the exclude list covers.
     *
     * @access public
     * @static
     * @param  string                $table
     * @param  mixed                 $where As accepted by Db::delete()
     * @param  string                $module
     * @param  list<string>          $tags
     * @param  ?array<string, mixed> $context
     * @param  ?string               $connection
     * @return PDOStatement
     */
    public static function delete(
        string $table,
        mixed $where,
        string $module = '',
        array $tags = [],
        ?array $context = null,
        ?string $connection = null
    ): PDOStatement {
        $connection = ($connection ?? self::connection());
        $before = self::rows($table, $where, $connection);

        if ($before === []) {
            self::notice("delete on \"{$table}\" matched no rows");
        }

        $audit = self::withinLimit($table, count($before));

        $statement = Db::delete($table, $where, $connection);

        if ($audit === true) {
            $exclude = self::exclude($table);
            foreach ($before as $row) {
                [$old, ] = Diff::between($row, null, $exclude);

                self::record(new AuditEvent(
                    event: AuditEvent::DELETED,
                    entityType: $table,
                    entityId: self::rowId($row),
                    module: $module,
                    oldValues: $old,
                    tags: $tags,
                    context: $context
                ));
            }
        }

        return $statement;
    }

    /**
     * @access public
     * @static
     * @param  ?array<string, mixed> $before
     * @param  ?array<string, mixed> $after
     * @param  list<string>          $exclude
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     */
    public static function diff(?array $before, ?array $after, array $exclude = []): array
    {
        return Diff::between($before, $after, $exclude);
    }

    /*
    | Settings
    */

    /**
     * @access private
     * @static
     * @return array<string, mixed>
     */
    private static function settings(): array
    {
        if (self::$settings === null) {
            $configured = Config::$items['audit'] ?? null;
            $configured = (is_array($configured) ? $configured : []);

            $settings = self::DEFAULTS;
            foreach ($configured as $key => $value) {
                $settings[(string) $key] = $value;
            }

            self::$settings = $settings;
        }

        return self::$settings;
    }

    /**
     * @access private
     * @static
     * @param  string $name
     * @return mixed
     */
    private static function setting(string $name): mixed
    {
        return (self::settings()[$name] ?? self::DEFAULTS[$name] ?? null);
    }

    /**
     * @access private
     * @static
     * @return string
     */
    private static function connection(): string
    {
        $connection = self::setting('connection');

        return (is_string($connection) && $connection !== '' ? $connection : 'default');
    }

    /**
     * Columns of $table whose values must not be stored.
     *
     * @access private
     * @static
     * @param  string $table
     * @return list<string>
     */
    private static function exclude(string $table): array
    {
        $exclude = self::setting('exclude');
        if (is_array($exclude) === false) {
            return [];
        }

        $columns = ($exclude[$table] ?? null);
        if (is_array($columns) === false) {
            return [];
        }

        $names = [];
        foreach ($columns as $column) {
            if (is_string($column) === true) {
                $names[] = $column;
            }
        }

        return $names;
    }

    /**
     * @access private
     * @static
     * @return array{type?: string, id?: string, name?: string}
     */
    private static function actor(): array
    {
        $resolver = self::setting('actor');
        if (is_callable($resolver) === false) {
            return [];
        }

        $actor = $resolver();
        if (is_array($actor) === false) {
            return [];
        }

        return [
            'type' => self::text($actor['type'] ?? null),
            'id' => self::text($actor['id'] ?? null),
            'name' => self::text($actor['name'] ?? null),
        ];
    }

    /**
     * Where the change came from.
     *
     * The default reads the request through Router::clientIp(), so a proxied deployment
     * records the client rather than the proxy, and cannot be told otherwise by a forged
     * X-Forwarded-For.
     *
     * @access private
     * @static
     * @return array{url?: string, ip_address?: string, user_agent?: string}
     */
    private static function context(): array
    {
        $resolver = self::setting('context');
        if (is_callable($resolver) === true) {
            $context = $resolver();
            if (is_array($context) === false) {
                return [];
            }

            return [
                'url' => self::text($context['url'] ?? null),
                'ip_address' => self::text($context['ip_address'] ?? null),
                'user_agent' => self::text($context['user_agent'] ?? null),
            ];
        }

        return [
            'url' => self::text($_SERVER['REQUEST_URI'] ?? null),
            'ip_address' => (Router::clientIp() ?? ''),
            'user_agent' => self::text($_SERVER['HTTP_USER_AGENT'] ?? null),
        ];
    }

    /*
    | Internals
    */

    /**
     * The rows a condition matches, as arrays whatever the connection's fetch mode is.
     *
     * @access private
     * @static
     * @param  string $table
     * @param  mixed  $where
     * @param  string $connection
     * @return list<array<string, mixed>>
     */
    private static function rows(string $table, mixed $where, string $connection): array
    {
        $rows = [];
        foreach (Db::select($table, $where, '*', $connection) as $row) {
            if (is_object($row) === true) {
                $row = get_object_vars($row);
            }

            if (is_array($row) === false) {
                continue;
            }

            $columns = [];
            foreach ($row as $key => $value) {
                $columns[(string) $key] = $value;
            }

            $rows[] = $columns;
        }

        return $rows;
    }

    /**
     * Whether a change of this size may be audited.
     *
     * @access private
     * @static
     * @param  string $table
     * @param  int    $matched
     * @return bool
     */
    private static function withinLimit(string $table, int $matched): bool
    {
        $limit = self::setting('max_rows');
        $limit = (is_int($limit) ? $limit : 1000);

        if ($limit < 1 || $matched <= $limit) {
            return true;
        }

        self::fail(new AuditError(
            "Refusing to audit {$matched} rows of \"{$table}\" in one call; "
            . "config['audit']['max_rows'] is {$limit}. Narrow the condition, or raise the limit."
        ));

        return false;
    }

    /**
     * @access private
     * @static
     * @param  array<string, mixed> $row
     * @return string
     */
    private static function rowId(array $row): string
    {
        $key = self::setting('id_key');
        $key = (is_string($key) && $key !== '' ? $key : 'id');

        return self::text($row[$key] ?? null);
    }

    /**
     * The key of a row that has just been inserted.
     *
     * Read from the data itself when it carries one, then from a RETURNING result, and only
     * then from the driver - postgres cannot answer without being told the sequence, and
     * throwing there would fail the insert over a missing audit detail.
     *
     * @access private
     * @static
     * @param  array<string, mixed> $data
     * @param  mixed                $result
     * @param  string               $connection
     * @return string
     */
    private static function insertedId(array $data, mixed $result, string $connection): string
    {
        $key = self::setting('id_key');
        $key = (is_string($key) && $key !== '' ? $key : 'id');

        if (isset($data[$key]) === true) {
            return self::text($data[$key]);
        }

        if (is_array($result) === true && isset($result[$key]) === true) {
            return self::text($result[$key]);
        }

        if (is_object($result) === true) {
            $values = get_object_vars($result);
            if (isset($values[$key]) === true) {
                return self::text($values[$key]);
            }
        }

        try {
            return self::text(Db::lastInsertId('', false, $connection));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @access private
     * @static
     * @param  mixed $value
     * @return string
     */
    private static function text(mixed $value): string
    {
        return (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Say something that is worth knowing but is not an error.
     *
     * A condition matching nothing is the case this exists for. Writing the same values a
     * row already holds is legitimate and common, so refusing would be wrong - but it is
     * also exactly what a mistyped condition looks like, and silence is what lets that ship.
     *
     * Only with debug on, because on a busy application the benign reading of this happens
     * constantly. Config::getBool() rather than resolveDebug(), which may call into the
     * application's own gate and is far too heavy to run on every write.
     *
     * @access private
     * @static
     * @param  string $message
     * @return void
     */
    private static function notice(string $message): void
    {
        if (Config::getBool('debug') === false) {
            return;
        }

        error_log('Audit trail: ' . $message);
    }

    /**
     * What to do about a failed audit write.
     *
     * Strict rethrows, which on postgres is the honest option anyway: the failed insert has
     * already aborted the transaction, so swallowing it does not rescue the change, it only
     * hides why the commit fails later.
     *
     * @access private
     * @static
     * @param  \Throwable $exception
     * @return void
     */
    private static function fail(\Throwable $exception): void
    {
        $strict = self::setting('strict');
        if ($strict === false) {
            error_log('Audit trail: ' . $exception->getMessage());

            return;
        }

        if ($exception instanceof AuditError) {
            throw $exception;
        }

        throw new AuditError('Audit trail: ' . $exception->getMessage(), 0, $exception);
    }
}
