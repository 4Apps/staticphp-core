<?php

namespace StaticPHP\Presentation\Models\Tables\Interfaces;

interface ColumnInterface
{
    public string $id {
        get;
        set;
    }

    /**
     * @param mixed ...$settings Property name => value pairs to set on the column
     */
    public function __construct(string $id, ...$settings);
}
