<?php

namespace StaticPHP\Tests\Presentation\Models\Tables;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\TestCase;
use StaticPHP\Presentation\Models\Tables\Column;
use StaticPHP\Presentation\Models\Tables\Enums\ColumnType;
use StaticPHP\Presentation\Models\Tables\Output\Excel;
use StaticPHP\Presentation\Models\Tables\Table;

/**
 * The excel output is the one part of the package that needs a library outside it, so it
 * is also the one part nothing else would notice had broken. phpspreadsheet is a dev
 * dependency purely so this file runs in CI.
 */
class ExcelOutputTest extends TestCase
{
    private function excel(array $rows): Excel
    {
        $columns = [
            new Column('name', title: 'Name', dataKey: 'name'),
            new Column('qty', title: 'Qty', type: ColumnType::INT, dataKey: 'qty'),
            new Column('total', title: 'Total', type: ColumnType::DECIMAL, dataKey: 'total'),
        ];

        // The TableInstance trait takes the owning table by reference, and setRows takes
        // the rows by reference, so both need a variable to bind to
        $table = new Table($columns);
        $table->setRows($rows);

        return new Excel($table);
    }

    public function testHeaderRowUsesColumnTitles()
    {
        $sheet = $this->excel([])->makeOutput()->getActiveSheet();

        $this->assertEquals('Name', $sheet->getCell([1, 1])->getValue());
        $this->assertEquals('Qty', $sheet->getCell([2, 1])->getValue());
        $this->assertEquals('Total', $sheet->getCell([3, 1])->getValue());
    }

    public function testTextColumnIsWrittenAsAString()
    {
        $rows = [['name' => 'Widget', 'qty' => 3, 'total' => 10.5]];
        $sheet = $this->excel($rows)->makeOutput()->getActiveSheet();

        $this->assertEquals('Widget', $sheet->getCell([1, 2])->getValue());
        $this->assertEquals(DataType::TYPE_STRING, $sheet->getCell([1, 2])->getDataType());
    }

    /**
     * The regression this file exists for: the switch matched 'int' against a ColumnType
     * enum, which is never loosely equal to its backing string, so every number fell
     * through to the string branch and excel stored it as text - unsummable, and flagged
     * with the green "number stored as text" triangle.
     */
    public function testNumericColumnsAreWrittenAsNumbersRatherThanText()
    {
        $rows = [['name' => 'Widget', 'qty' => 3, 'total' => 10.5]];
        $sheet = $this->excel($rows)->makeOutput()->getActiveSheet();

        $this->assertEquals(DataType::TYPE_NUMERIC, $sheet->getCell([2, 2])->getDataType());
        $this->assertEquals(DataType::TYPE_NUMERIC, $sheet->getCell([3, 2])->getDataType());
        $this->assertEquals(3, $sheet->getCell([2, 2])->getValue());
        $this->assertEquals(10.5, $sheet->getCell([3, 2])->getValue());
    }

    public function testNullNumbersBecomeZeroRatherThanEmptyText()
    {
        $rows = [['name' => 'Widget', 'qty' => null, 'total' => null]];
        $sheet = $this->excel($rows)->makeOutput()->getActiveSheet();

        $this->assertEquals(DataType::TYPE_NUMERIC, $sheet->getCell([2, 2])->getDataType());
        $this->assertEquals(0, $sheet->getCell([2, 2])->getValue());
    }

    public function testRowNumberColumnIsLeftOutOfTheExport()
    {
        $rows = [['name' => 'Widget', 'qty' => 1, 'total' => 1.0]];

        $columns = [
            new Column('nr', title: 'Nr', type: ColumnType::ROW_NUMBER),
            new Column('name', title: 'Name', dataKey: 'name'),
        ];
        $table = new Table($columns);
        $table->setRows($rows);

        $sheet = (new Excel($table))->makeOutput()->getActiveSheet();

        $this->assertEquals('Name', $sheet->getCell([1, 1])->getValue());
        $this->assertNull($sheet->getCell([2, 1])->getValue());
    }

    public function testExportKeyClosureReceivesTheRow()
    {
        $rows = [['name' => 'Widget', 'qty' => 2, 'total' => 4.0]];

        $columns = [
            new Column(
                'label',
                title: 'Label',
                exportKey: fn($column, $rowIndex, $rowItem) => strtoupper($rowItem['name'])
            ),
        ];
        $table = new Table($columns);
        $table->setRows($rows);

        $sheet = (new Excel($table))->makeOutput()->getActiveSheet();

        $this->assertEquals('WIDGET', $sheet->getCell([1, 2])->getValue());
    }
}
