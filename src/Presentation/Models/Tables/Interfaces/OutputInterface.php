<?php

namespace StaticPHP\Presentation\Models\Tables\Interfaces;

/**
 * Table Interface
 */
interface OutputInterface extends TableInstanceInterface
{
    public function makeOutput(): mixed;
    public function showOutput(): void;
}
