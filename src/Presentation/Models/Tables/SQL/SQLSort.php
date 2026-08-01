<?php

namespace StaticPHP\Presentation\Models\Tables\SQL;

use StaticPHP\Presentation\Models\Tables\Interfaces\TableInstanceInterface;
use StaticPHP\Presentation\Models\Tables\Enums\SortNulls;
use StaticPHP\Presentation\Models\Tables\Sort;
use StaticPHP\Presentation\Models\Tables\Traits\TableInstance;

/**
 * SQL Sort implementation
 */
class SQLSort implements TableInstanceInterface
{
    use TableInstance;

    /**
     * The table's sort, which initData() creates before this class is ever constructed.
     */
    private function sort(): Sort
    {
        return $this->tableInstance->sort
            ?? throw new \LogicException('SQLSort needs a table whose sorting was initialised');
    }

    /**
     * Returns what to do with nulls in a SQL order by statement
     */
    public function sortNulls(): SortNulls
    {
        $column = $this->sort()->currentColumn();
        return $column->sortNulls ?? SortNulls::FIRST;
    }

    /**
     * Returns SQL formatted ORDER BY
     */
    public function sortQuery(): string
    {
        $column = $this->sort()->sortBy();
        $direction = $this->sort()->sortDirection()->value;
        $nulls = match ($this->sortNulls()) {
            SortNulls::NONE => '',
            SortNulls::FIRST => 'NULLS FIRST',
            SortNulls::LAST => 'NULLS LAST',
        };

        return " ORDER BY {$column} {$direction} {$nulls} ";
    }
}
