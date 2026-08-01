<?php

namespace StaticPHP\Tests\Presentation\Models\Audit;

use PHPUnit\Framework\TestCase;
use StaticPHP\Presentation\Models\Audit\AuditTable;
use StaticPHP\Presentation\Models\Tables\Column;

/**
 * The viewer renders values that came out of a request and went into the trail on the way
 * past, so it is the one table in an application guaranteed to be showing somebody's input
 * back to them.
 */
class AuditTableTest extends TestCase
{
    /**
     * @param  array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function row(array $values): array
    {
        return $values + [
            'created_at' => '2026-08-02 10:00:00',
            'event' => 'updated',
            'entity_type' => 'people',
            'entity_id' => '42',
            'module' => 'catalog',
            'actor_name' => 'Anna',
            'old_values' => null,
            'new_values' => null,
        ];
    }

    public function testColumnsCoverTheStandardShape(): void
    {
        $ids = array_map(fn(Column $column): string => $column->id, AuditTable::columns());

        $this->assertEquals(['nr', 'created_at', 'actor', 'event', 'module', 'entity', 'changes'], $ids);
    }

    public function testTheAliasReachesTheSortAndFilterExpressions(): void
    {
        $columns = AuditTable::columns(null, 'h');
        $created = $columns[1];

        $this->assertEquals('h.created_at', $created->sortBy);
        $this->assertEquals('h.created_at', $created->filterBy);
    }

    public function testEntityNamesTheRecord(): void
    {
        $this->assertEquals('people #42', AuditTable::entity($this->row([])));
    }

    public function testEntityWithoutAKeyIsJustTheTable(): void
    {
        $this->assertEquals('people', AuditTable::entity($this->row(['entity_id' => ''])));
    }

    public function testAnUpdateIsRenderedAsFromAndTo(): void
    {
        $html = AuditTable::changes($this->row([
            'old_values' => json_encode(['name' => 'Anna']),
            'new_values' => json_encode(['name' => 'Anna Berzina']),
        ]));

        $this->assertStringContainsString('<strong>name</strong>', $html);
        $this->assertStringContainsString('Anna &rarr; Anna Berzina', $html);
    }

    public function testACreatedEventIsRenderedOneSided(): void
    {
        $html = AuditTable::changes($this->row([
            'new_values' => json_encode(['name' => 'Anna']),
        ]));

        $this->assertStringContainsString('<strong>name</strong>: Anna', $html);
        $this->assertStringNotContainsString('&rarr;', $html);
    }

    public function testADeletedEventRendersTheOldValues(): void
    {
        $html = AuditTable::changes($this->row([
            'old_values' => json_encode(['name' => 'Anna', 'city' => 'Dobele']),
        ]));

        $this->assertStringContainsString('<strong>city</strong>: Dobele', $html);
        $this->assertStringNotContainsString('&rarr;', $html);
    }

    /**
     * ddz's renderer interpolates both the column name and the value straight into markup.
     * A name field carrying a script tag is stored faithfully and then rendered, which is
     * stored XSS in the one page an administrator opens to investigate.
     */
    public function testValuesAreEscaped(): void
    {
        $html = AuditTable::changes($this->row([
            'old_values' => json_encode(['name' => 'Anna']),
            'new_values' => json_encode(['name' => '<img src=x onerror=alert(1)>']),
        ]));

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function testColumnNamesAreEscapedToo(): void
    {
        $html = AuditTable::changes($this->row([
            'new_values' => json_encode(['<script>' => 'x']),
        ]));

        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testAFormatterIsAppliedAndItsOutputStillEscaped(): void
    {
        $html = AuditTable::changes(
            $this->row(['new_values' => json_encode(['created' => 1785715200])]),
            fn(string $column, mixed $value): string => ($column === 'created' && is_numeric($value)
                ? gmdate('d.m.Y', (int) $value) . ' <b>'
                : (is_scalar($value) ? (string) $value : ''))
        );

        $this->assertStringContainsString('03.08.2026', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testARowWithNoChangesRendersNothing(): void
    {
        $this->assertEquals('', AuditTable::changes($this->row([])));
    }

    public function testAlreadyDecodedValuesAreAccepted(): void
    {
        // Some drivers hand json columns back decoded
        $html = AuditTable::changes($this->row(['new_values' => ['name' => 'Anna']]));

        $this->assertStringContainsString('Anna', $html);
    }

    public function testNestedValuesAreRenderedRatherThanLost(): void
    {
        $html = AuditTable::changes($this->row(['new_values' => json_encode(['meta' => ['a' => 1]])]));

        $this->assertStringContainsString('a', $html);
        $this->assertStringNotContainsString('Array', $html);
    }
}
