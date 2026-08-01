<?php

namespace StaticPHP\Presentation\Models\Tables\Interfaces;

interface ColumnInterface
{
    public string $id {
        get;
        set;
    }

    public function __construct(string $id, ...$settings);
}
