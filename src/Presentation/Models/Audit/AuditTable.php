<?php

namespace StaticPHP\Presentation\Models\Audit;

use StaticPHP\Presentation\Models\Tables\Column;
use StaticPHP\Presentation\Models\Tables\Enums\ColumnType;
use StaticPHP\Presentation\Models\Tables\Enums\FieldType;
use StaticPHP\Presentation\Models\Tables\Enums\SortDirection;
use StaticPHP\Presentation\Models\Tables\SQL\SQLTable;
use StaticPHP\Utils\Models\Audit\AuditEvent;

/**
 * The audit trail as a table.
 *
 * A builder rather than a controller: reading the trail needs authorisation, and the
 * framework has no notion of who is allowed to. The application checks access, calls
 * build(), runs its own query - adding `WHERE module = ?` if it shows one module at a time
 * - and renders. That is roughly ten lines in place of the hundred and fifty this replaces.
 *
 * @example
 *     $table = AuditTable::build(self::$method_url, $filterData, $sortData, $page);
 *     $table->sqlFilter->prepareQueries();
 *     ... count, fetch, $table->setRows($rows) ...
 */
class AuditTable
{
    /**
     * The standard column set, in the order they are read.
     *
     * @access public
     * @static
     * @param  ?callable(string, mixed): string $formatValue
     *         Given a column name and a stored value, returns what to show. Dates held as
     *         anything other than a timestamp are the usual reason to pass one.
     * @param  string $alias Table alias the sort and filter expressions are built against
     * @return list<Column>
     */
    public static function columns(?callable $formatValue = null, string $alias = 'a'): array
    {
        return [
            new Column(
                'nr',
                title: '#',
                type: ColumnType::ROW_NUMBER,
                exportKey: false,
                sortEnabled: false,
                filterEnabled: false
            ),
            new Column(
                'created_at',
                title: 'Date',
                type: ColumnType::DATETIME,
                dataKey: 'created_at',
                sortBy: "{$alias}.created_at",
                sortDefaultColumn: true,
                sortDefaultDirection: SortDirection::DESC,
                filterBy: "{$alias}.created_at",
                filterFieldType: FieldType::DATEINTERVAL
            ),
            new Column(
                'actor',
                title: 'Who',
                // The name is on the row rather than joined, so this column keeps working
                // after the account is renamed or deleted
                dataKey: 'actor_name',
                sortBy: "{$alias}.actor_name",
                filterBy: "{$alias}.actor_id"
            ),
            new Column(
                'event',
                title: 'Event',
                dataKey: 'event',
                sortBy: "{$alias}.event",
                filterBy: "{$alias}.event",
                filterFieldType: FieldType::SELECT,
                filterSelectOptions: [
                    AuditEvent::CREATED => 'Created',
                    AuditEvent::UPDATED => 'Updated',
                    AuditEvent::DELETED => 'Deleted',
                ]
            ),
            new Column(
                'module',
                title: 'Module',
                dataKey: 'module',
                sortBy: "{$alias}.module",
                filterBy: "{$alias}.module"
            ),
            new Column(
                'entity',
                title: 'Record',
                dataKey: fn(Column $column, int $index, mixed $row): string => self::entity($row),
                sortBy: "{$alias}.entity_type",
                filterBy: "{$alias}.entity_type"
            ),
            new Column(
                'changes',
                title: 'Changes',
                dataKey: fn(Column $column, int $index, mixed $row): string => self::changes($row, $formatValue),
                // The renderer emits its own markup, and escapes every value that goes
                // into it. Without this the table would escape the tags as well.
                escapeDataHtml: false,
                sortEnabled: false,
                filterBy: "{$alias}.new_values"
            ),
        ];
    }

    /**
     * A table over the standard shape, ready for the application's own query.
     *
     * @access public
     * @static
     * @param  string  $baseUrl Where filter and sort links point, usually self::$method_url
     * @param  ?string $filterData
     * @param  ?string $sortData
     * @param  ?int    $page
     * @param  ?callable(string, mixed): string $formatValue
     * @param  string  $alias
     * @return SQLTable
     */
    public static function build(
        string $baseUrl,
        ?string $filterData = null,
        ?string $sortData = null,
        ?int $page = null,
        ?callable $formatValue = null,
        string $alias = 'a'
    ): SQLTable {
        $table = new SQLTable(self::columns($formatValue, $alias), $baseUrl);
        $table->idKey = 'id';
        $table->initData($filterData, $sortData, $page);

        return $table;
    }

    /**
     * "people #42", or just the table name when the row carries no key.
     *
     * @access public
     * @static
     * @param  mixed $row
     * @return string
     */
    public static function entity(mixed $row): string
    {
        $values = self::row($row);
        $type = self::text($values['entity_type'] ?? null);
        $id = self::text($values['entity_id'] ?? null);

        return ($id === '' ? $type : "{$type} #{$id}");
    }

    /**
     * The change itself, as markup.
     *
     * A created event has no old values worth showing and a deleted one has no new ones, so
     * each is rendered one-sided rather than as a diff against nothing.
     *
     * Every value is escaped. The trail records what was submitted, so an audit viewer is
     * the one place in an application guaranteed to be rendering somebody's input back.
     *
     * @access public
     * @static
     * @param  mixed $row
     * @param  ?callable(string, mixed): string $formatValue
     * @return string
     */
    public static function changes(mixed $row, ?callable $formatValue = null): string
    {
        $values = self::row($row);
        $old = self::decode($values['old_values'] ?? null);
        $new = self::decode($values['new_values'] ?? null);

        $format = static function (string $column, mixed $value) use ($formatValue): string {
            if ($formatValue !== null) {
                return htmlspecialchars($formatValue($column, $value), ENT_QUOTES, 'UTF-8');
            }

            return htmlspecialchars(self::text($value), ENT_QUOTES, 'UTF-8');
        };

        $lines = [];
        foreach (($new ?? $old ?? []) as $column => $value) {
            $name = htmlspecialchars((string) $column, ENT_QUOTES, 'UTF-8');

            if ($new === null || $old === null) {
                $lines[] = "<strong>{$name}</strong>: " . $format((string) $column, $value);
                continue;
            }

            $lines[] = "<strong>{$name}</strong>: "
                . $format((string) $column, $old[$column] ?? null)
                . ' &rarr; '
                . $format((string) $column, $value);
        }

        return implode('<br />', $lines);
    }

    /**
     * @access private
     * @static
     * @param  mixed $row
     * @return array<string, mixed>
     */
    private static function row(mixed $row): array
    {
        if (is_object($row) === true) {
            $row = get_object_vars($row);
        }

        if (is_array($row) === false) {
            return [];
        }

        $values = [];
        foreach ($row as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }

    /**
     * @access private
     * @static
     * @param  mixed $json
     * @return ?array<string, mixed>
     */
    private static function decode(mixed $json): ?array
    {
        if (is_array($json) === true) {
            $decoded = $json;
        } elseif (is_string($json) === true && $json !== '') {
            $decoded = json_decode($json, true);
        } else {
            return null;
        }

        if (is_array($decoded) === false) {
            return null;
        }

        $values = [];
        foreach ($decoded as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }

    /**
     * @access private
     * @static
     * @param  mixed $value
     * @return string
     */
    private static function text(mixed $value): string
    {
        if (is_scalar($value) === true) {
            return (string) $value;
        }

        if ($value === null) {
            return '';
        }

        return (string) json_encode($value);
    }
}
