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

    public function limitQuery()
    {
        return <<<EOL
OFFSET {$this->tableInstance->pagination->limitFrom}
LIMIT {$this->tableInstance->pagination->limitPerPage}
EOL;
    }
}
