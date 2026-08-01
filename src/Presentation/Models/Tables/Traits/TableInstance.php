<?php

namespace StaticPHP\Presentation\Models\Tables\Traits;

use StaticPHP\Presentation\Models\Tables\Interfaces\TableInterface;

trait TableInstance
{
    protected TableInterface $tableInstance;

    public function __construct(TableInterface &$tableInstance)
    {
        $this->tableInstance = &$tableInstance;
    }
}
