<?php

namespace StaticPHP\Presentation\Models\Tables\Enums;

enum SortNulls: string
{
    /**
     * Leave null placement to the database.
     *
     * Required for mysql and mariadb, which have no NULLS FIRST / NULLS LAST syntax - an
     * ORDER BY carrying it is a syntax error there, not a hint they ignore.
     */
    case NONE = 'NONE';

    case FIRST = 'FIRST';
    case LAST = 'LAST';
}
