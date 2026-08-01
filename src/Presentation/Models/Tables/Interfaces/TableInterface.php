<?php

namespace StaticPHP\Presentation\Models\Tables\Interfaces;

use StaticPHP\Presentation\Models\Tables\Enums\RowPosition;
use StaticPHP\Presentation\Models\Tables\Filters;
use StaticPHP\Presentation\Models\Tables\Pagination;
use StaticPHP\Presentation\Models\Tables\Sort;

/**
 * Table Interface
 *
 * The output generators and the sql helpers are handed a table through this interface and
 * read its state directly, so that state is part of the contract rather than an
 * implementation detail of Table.
 */
interface TableInterface
{
    public ?Sort $sort {
        get;
        set;
    }
    public ?Filters $filter {
        get;
        set;
    }
    public ?Pagination $pagination {
        get;
        set;
    }

    public ?array $columns {
        get;
        set;
    }

    public RowPosition $avgRowPosition {
        get;
        set;
    }
    public RowPosition $sumRowPosition {
        get;
        set;
    }
    public RowPosition $customRowPosition {
        get;
        set;
    }

    public bool|\Closure $isEditable {
        get;
        set;
    }
    public bool $showReadonlyInputs {
        get;
        set;
    }
    public null|string|\Closure $idKey {
        get;
        set;
    }

    public function __construct(array $columns, string $urlPrefix = '');

    public function tableId(): string;
    public function parseQueryString(string $str, string $delimiter = '&');

    public function initData(?string $filterData = null, ?string $sortData = null, ?int $page = null): void;

    public function getColumns(): array;
    public function setColumns(array $columns): void;

    public function getRows(): array;
    public function setRows(array &$rows): void;

    public function makeOutput();
    public function showOutput(): void;
}
