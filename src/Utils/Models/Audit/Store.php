<?php

namespace StaticPHP\Utils\Models\Audit;

use PDO;
use StaticPHP\Utils\Models\Db;

/**
 * Writes audit rows to a table.
 *
 * The target is resolved per event rather than fixed, which is what lets one deployment
 * keep everything in `audit_log` and another split it per module without either of them
 * having a different column shape.
 */
class Store
{
    /**
     * Declared widths of the varchar columns.
     *
     * Values are cut to fit rather than allowed to fail. An audit write that throws because
     * somebody's name is long has turned the trail into an outage, and the alternative -
     * making every column TEXT - gives up the index sizes the shape was chosen for.
     *
     * @var array<string, int>
     * @access private
     */
    private const WIDTHS = [
        'request_id' => 32,
        'module' => 64,
        'event' => 32,
        'entity_type' => 128,
        'entity_id' => 64,
        'actor_type' => 32,
        'actor_id' => 64,
        'actor_name' => 190,
        'ip_address' => 45,
    ];

    /**
     * Table names are concatenated into the query, so nothing but a plain identifier -
     * optionally schema-qualified - is accepted. A resolver is application code, but it is
     * application code being handed an event whose module may have come from a request.
     *
     * @var string
     * @access private
     */
    private const TABLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    private string $connection;

    /** @var string|callable(AuditEvent): string */
    private $table;

    /**
     * @access public
     * @param string                            $connection Entry of config['db']['pdo']
     * @param string|callable(AuditEvent): string $table    One table, or a resolver
     */
    public function __construct(string $connection, string|callable $table)
    {
        $this->connection = $connection;
        $this->table = $table;
    }

    /**
     * Write one row.
     *
     * On the caller's connection and inside the caller's transaction, so a rolled back
     * change takes its audit row with it.
     *
     * @access public
     * @param  AuditEvent $event
     * @return void
     */
    public function write(AuditEvent $event): void
    {
        $table = $this->tableFor($event);

        Db::insert(
            $table,
            [
                'created_at' => ($event->createdAt ?? $this->now()),
                'request_id' => $this->fit('request_id', $event->requestId),
                'module' => $this->fit('module', $event->module),
                'event' => $this->fit('event', $event->event),
                'entity_type' => $this->fit('entity_type', $event->entityType),
                'entity_id' => $this->fit('entity_id', $event->entityId),
                'actor_type' => $this->fit('actor_type', $event->actorType),
                'actor_id' => $this->fit('actor_id', $event->actorId),
                'actor_name' => $this->fit('actor_name', $event->actorName),
                'old_values' => self::json($event->oldValues),
                'new_values' => self::json($event->newValues),
                'url' => $event->url,
                'ip_address' => $this->fit('ip_address', $event->ipAddress),
                'user_agent' => $event->userAgent,
                'tags' => ($event->tags === [] ? null : self::json($event->tags)),
                'context' => self::json($event->context),
            ],
            $this->connection
        );
    }

    /**
     * Which table this event belongs in.
     *
     * @access public
     * @param  AuditEvent $event
     * @return string
     */
    public function tableFor(AuditEvent $event): string
    {
        return self::assertTableName(
            is_string($this->table) ? $this->table : ($this->table)($event)
        );
    }

    /**
     * Reject anything that is not a plain, optionally schema-qualified, table name.
     *
     * @access public
     * @static
     * @param  string $table
     * @return string The name, unchanged
     */
    public static function assertTableName(string $table): string
    {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new AuditError("Refusing to write the audit trail to \"{$table}\": not a plain table name");
        }

        return $table;
    }

    /**
     * The connection this store writes to.
     *
     * @access public
     * @return string
     */
    public function connection(): string
    {
        return $this->connection;
    }

    /**
     * Now, in UTC, spelled the way this driver reads it.
     *
     * Stamped here rather than left to the column default so that every driver agrees on
     * the value and a test can pin it. Postgres is given an explicit offset because
     * timestamptz would otherwise read a bare string in the session's timezone; the other
     * two store what they are given.
     *
     * @access private
     * @return string
     */
    private function now(): string
    {
        $pdo = Db::init($this->connection);
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $format = ($driver === 'pgsql' ? 'Y-m-d H:i:sP' : 'Y-m-d H:i:s');

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format($format);
    }

    /**
     * Cut a value to its column width.
     *
     * @access private
     * @param  string $column
     * @param  string $value
     * @return string
     */
    private function fit(string $column, string $value): string
    {
        $width = self::WIDTHS[$column] ?? 0;
        if ($width === 0 || mb_strlen($value) <= $width) {
            return $value;
        }

        return mb_substr($value, 0, $width);
    }

    /**
     * @access private
     * @static
     * @param  ?array<int|string, mixed> $values
     * @return ?string
     */
    private static function json(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        $encoded = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return ($encoded === false ? null : $encoded);
    }
}
