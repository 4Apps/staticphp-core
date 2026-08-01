<?php

namespace StaticPHP\Presentation\Models\Tables\SQL;

use StaticPHP\Presentation\Models\Tables\Interfaces\TableInstanceInterface;
use StaticPHP\Presentation\Models\Tables\Traits\TableInstance;

/**
 * Paging SQL Implementation
 */
class SQLPagination implements TableInstanceInterface
{
    use TableInstance;

    public function limitQuery(): string
    {
        $pagination = $this->tableInstance->pagination
            ?? throw new \LogicException('SQLPagination needs a table whose paging was initialised');

        return <<<EOL
OFFSET {$pagination->limitFrom}
LIMIT {$pagination->limitPerPage}
EOL;
    }
}
