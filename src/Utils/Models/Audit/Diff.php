<?php

namespace StaticPHP\Utils\Models\Audit;

/**
 * Works out what actually changed between two rows.
 *
 * Pure by design, so the comparison rules - which are the fiddly part - are tested without
 * a database anywhere near them.
 */
class Diff
{
    /**
     * Stands in for a value that must not reach the audit table.
     *
     * The key is kept so the log still shows that a password changed, without showing
     * either the old or the new one.
     *
     * @var string
     * @access public
     */
    public const REDACTED = '***';

    /**
     * The old/new pair for one change.
     *
     * A null `$before` is an insert and a null `$after` is a delete; both record the whole
     * row. An update records only what changed.
     *
     * Only keys present in `$after` are considered, because `$after` is the data being
     * written: `Db::update()` is routinely handed three columns against a row that has
     * thirty, and the twenty-seven it does not mention have not changed. Keys in `$after`
     * with no counterpart in `$before` are recorded as a change from null - that is the
     * case `array_diff_assoc()` silently drops.
     *
     * @access public
     * @static
     * @param  ?array<string, mixed> $before
     * @param  ?array<string, mixed> $after
     * @param  list<string>          $exclude Columns whose values must not be stored
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     *         Old and new values, both null when nothing changed
     */
    public static function between(?array $before, ?array $after, array $exclude = []): array
    {
        if ($before === null && $after === null) {
            return [null, null];
        }

        if ($after === null) {
            return [self::mask((array) $before, $exclude), null];
        }

        if ($before === null) {
            return [null, self::mask($after, $exclude)];
        }

        $oldValues = [];
        $newValues = [];

        foreach ($after as $key => $newValue) {
            // Db::insert()/Db::update() treat a leading "!" as "this value is raw sql".
            // The column name is what belongs in the log, and the expression itself is a
            // more honest record of a counter bump than the null we would otherwise have.
            $column = (isset($key[0]) && $key[0] === '!' ? substr($key, 1) : $key);
            $oldValue = $before[$column] ?? null;

            if (self::same($oldValue, $newValue) === true) {
                continue;
            }

            if (in_array($column, $exclude, true) === true) {
                $oldValues[$column] = self::REDACTED;
                $newValues[$column] = self::REDACTED;
                continue;
            }

            $oldValues[$column] = $oldValue;
            $newValues[$column] = $newValue;
        }

        if ($newValues === []) {
            return [null, null];
        }

        return [$oldValues, $newValues];
    }

    /**
     * Replace excluded columns with the marker, leaving the keys in place.
     *
     * @access public
     * @static
     * @param  array<string, mixed> $values
     * @param  list<string>         $exclude
     * @return array<string, mixed>
     */
    public static function mask(array $values, array $exclude): array
    {
        if ($exclude === []) {
            return $values;
        }

        foreach ($values as $key => $value) {
            if (in_array($key, $exclude, true) === true) {
                $values[$key] = self::REDACTED;
            }
        }

        return $values;
    }

    /**
     * Whether two values represent the same stored value.
     *
     * Comparison is on string forms rather than identity, because PDO hands most column
     * types back as strings: the integer 1 an application writes and the "1" that comes
     * back are the same value, and a strict comparison would report every untouched column
     * as changed.
     *
     * @access public
     * @static
     * @param  mixed $a
     * @param  mixed $b
     * @return bool
     */
    public static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return ($a === null && $b === null);
        }

        // Only when one side really is a php bool. Two strings stay a string comparison,
        // so a text column holding the literal "true" is never quietly equal to 1.
        if (is_bool($a) === true || is_bool($b) === true) {
            return (self::boolish($a) === self::boolish($b));
        }

        if (is_scalar($a) === false || is_scalar($b) === false) {
            return (json_encode($a) === json_encode($b));
        }

        return ((string) $a === (string) $b);
    }

    /**
     * Normalise the boolean family to "1" or "0".
     *
     * Db::insert()/Db::update() send php booleans as the literals 'true' and 'false', and
     * the drivers hand them back as t/f, 1/0, '' or a php bool depending on driver and
     * version. Without this an untouched flag reads as changed on every single update.
     *
     * Anything unrecognised is returned as-is, so it can still fail the comparison.
     *
     * @access private
     * @static
     * @param  mixed $value
     * @return string
     */
    private static function boolish(mixed $value): string
    {
        if (is_bool($value) === true) {
            return ($value === true ? '1' : '0');
        }

        $text = (is_scalar($value) ? strtolower((string) $value) : '');

        return match ($text) {
            '1', 't', 'true', 'y', 'yes' => '1',
            '0', 'f', 'false', 'n', 'no', '' => '0',
            default => $text,
        };
    }
}
