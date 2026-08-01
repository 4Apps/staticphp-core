<?php

namespace StaticPHP\Tests\Utils\Models\Audit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StaticPHP\Utils\Models\Audit\Diff;

/**
 * The comparison rules are where an audit trail quietly goes wrong: too strict and every
 * update logs columns nobody touched, too loose and a real change disappears.
 */
class DiffTest extends TestCase
{
    public function testInsertRecordsEverythingAsNew(): void
    {
        [$old, $new] = Diff::between(null, ['name' => 'Anna', 'active' => true]);

        $this->assertNull($old);
        $this->assertEquals(['name' => 'Anna', 'active' => true], $new);
    }

    public function testDeleteRecordsEverythingAsOld(): void
    {
        [$old, $new] = Diff::between(['id' => 5, 'name' => 'Anna'], null);

        $this->assertEquals(['id' => 5, 'name' => 'Anna'], $old);
        $this->assertNull($new);
    }

    public function testUpdateRecordsOnlyWhatChanged(): void
    {
        [$old, $new] = Diff::between(
            ['name' => 'Anna', 'city' => 'Dobele'],
            ['name' => 'Anna Berzina', 'city' => 'Dobele']
        );

        $this->assertEquals(['name' => 'Anna'], $old);
        $this->assertEquals(['name' => 'Anna Berzina'], $new);
    }

    public function testUnchangedRowRecordsNothing(): void
    {
        [$old, $new] = Diff::between(['name' => 'Anna'], ['name' => 'Anna']);

        $this->assertNull($old);
        $this->assertNull($new);
    }

    /**
     * Db::update() is routinely handed the three columns being written against a row that
     * has thirty. The twenty-seven it does not mention have not changed, and reporting them
     * as changes to null is how an audit log becomes unreadable.
     */
    public function testColumnsMissingFromTheUpdateAreNotChanges(): void
    {
        [$old, $new] = Diff::between(
            ['id' => 5, 'name' => 'Anna', 'city' => 'Dobele', 'notes' => 'anything'],
            ['name' => 'Anna Berzina']
        );

        $this->assertEquals(['name' => 'Anna'], $old);
        $this->assertEquals(['name' => 'Anna Berzina'], $new);
    }

    /**
     * The bug in array_diff_assoc(): it returns entries of the old row, so a column that
     * was never selected - or was null and got a value - never appears at all.
     */
    public function testAColumnAbsentFromTheOldRowIsAChangeFromNull(): void
    {
        [$old, $new] = Diff::between(['name' => 'Anna'], ['approved_at' => '2026-08-02']);

        $this->assertEquals(['approved_at' => null], $old);
        $this->assertEquals(['approved_at' => '2026-08-02'], $new);
    }

    public function testNullToValueIsAChange(): void
    {
        [$old, $new] = Diff::between(['note' => null], ['note' => 'something']);

        $this->assertEquals(['note' => null], $old);
        $this->assertEquals(['note' => 'something'], $new);
    }

    public function testNullAndEmptyStringAreDifferent(): void
    {
        [, $new] = Diff::between(['note' => null], ['note' => '']);

        $this->assertEquals(['note' => ''], $new);
    }

    /*
    | Type coercion
    */

    /**
     * PDO hands most column types back as strings, so the integer an application writes and
     * the string that comes back are the same stored value.
     */
    public function testIntAndItsStringFormAreTheSameValue(): void
    {
        [, $new] = Diff::between(['qty' => '42'], ['qty' => 42]);

        $this->assertNull($new);
    }

    public function testFloatAndItsStringFormAreTheSameValue(): void
    {
        [, $new] = Diff::between(['price' => '10.5'], ['price' => 10.5]);

        $this->assertNull($new);
    }

    /**
     * Db::update() sends php booleans as the literals 'true'/'false' and the drivers return
     * t/f, 1/0 or a php bool. Without normalisation every update logs a flag change that
     * did not happen.
     */
    #[DataProvider('booleanShapes')]
    public function testBooleanShapesCompareEqual(mixed $stored, mixed $written): void
    {
        [, $new] = Diff::between(['active' => $stored], ['active' => $written]);

        $this->assertNull($new, 'expected no change');
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function booleanShapes(): array
    {
        return [
            'postgres t' => ['t', true],
            'postgres f' => ['f', false],
            'mysql 1' => ['1', true],
            'mysql 0' => ['0', false],
            'int 1' => [1, true],
            'int 0' => [0, false],
            'literal true' => ['true', true],
            'literal false' => ['false', false],
            'empty string is false' => ['', false],
            'native bool' => [true, true],
        ];
    }

    public function testAGenuineBooleanChangeIsStillRecorded(): void
    {
        [$old, $new] = Diff::between(['active' => 'f'], ['active' => true]);

        $this->assertEquals(['active' => 'f'], $old);
        $this->assertEquals(['active' => true], $new);
    }

    /**
     * The trade-off the "at least one side is a php bool" rule exists to avoid: with blanket
     * normalisation a text column holding the word "true" would compare equal to "1".
     */
    public function testTwoStringsAreComparedAsStrings(): void
    {
        [$old, $new] = Diff::between(['answer' => 'true'], ['answer' => '1']);

        $this->assertEquals(['answer' => 'true'], $old);
        $this->assertEquals(['answer' => '1'], $new);
    }

    public function testArraysAreComparedByContent(): void
    {
        [, $unchanged] = Diff::between(['meta' => ['a' => 1]], ['meta' => ['a' => 1]]);
        [, $changed] = Diff::between(['meta' => ['a' => 1]], ['meta' => ['a' => 2]]);

        $this->assertNull($unchanged);
        $this->assertEquals(['meta' => ['a' => 2]], $changed);
    }

    /*
    | Raw sql values and redaction
    */

    public function testRawSqlColumnsAreLoggedUnderTheirColumnName(): void
    {
        // Db::update('t', ['!counter' => 'counter + 1'], ...) - the result is not knowable
        // without another select, so the expression is the honest record
        [$old, $new] = Diff::between(['counter' => 4], ['!counter' => 'counter + 1']);

        $this->assertEquals(['counter' => 4], $old);
        $this->assertEquals(['counter' => 'counter + 1'], $new);
    }

    public function testExcludedColumnsAreRecordedAsChangedButNotStored(): void
    {
        [$old, $new] = Diff::between(
            ['name' => 'Anna', 'password' => 'old-hash'],
            ['name' => 'Anna', 'password' => 'new-hash'],
            ['password']
        );

        $this->assertEquals(['password' => Diff::REDACTED], $old);
        $this->assertEquals(['password' => Diff::REDACTED], $new);
    }

    public function testExcludedColumnsAreMaskedOnInsertAndDelete(): void
    {
        [, $inserted] = Diff::between(null, ['name' => 'Anna', 'password' => 'hash'], ['password']);
        [$deleted, ] = Diff::between(['name' => 'Anna', 'password' => 'hash'], null, ['password']);

        $this->assertEquals(['name' => 'Anna', 'password' => Diff::REDACTED], $inserted);
        $this->assertEquals(['name' => 'Anna', 'password' => Diff::REDACTED], $deleted);
    }

    public function testAnUnchangedExcludedColumnIsNotRecordedAtAll(): void
    {
        [, $new] = Diff::between(
            ['password' => 'same-hash'],
            ['password' => 'same-hash'],
            ['password']
        );

        $this->assertNull($new);
    }
}
