<?php

namespace StaticPHP\Presentation\Models\Tables\Enums;

enum EditableTableType: string
{
    case WHOLE_TABLE = 'whole_table';
    case BY_FIELD = 'by_field';
}
