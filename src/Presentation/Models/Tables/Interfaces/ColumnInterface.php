<?php

namespace StaticPHP\Presentation\Models\Tables\Interfaces;

interface ColumnInterface
{
    public function __construct(string $id, ...$settings);
}
