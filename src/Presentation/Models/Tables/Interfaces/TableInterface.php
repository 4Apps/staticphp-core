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

    /** @var array<string, \StaticPHP\Presentation\Models\Tables\Column> */
    public array $columns {
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

    /**
     * @param array<int, mixed> $columns Validated by setColumns()
     */
    public function __construct(array $columns, string $urlPrefix = '');

    public function tableId(): string;

    /**
     * @return array<string, string>
     */
    public function parseQueryString(string $str, string $delimiter = '&'): array;

    public function initData(?string $filterData = null, ?string $sortData = null, ?int $page = null): void;

    /**
     * @return array<string, \StaticPHP\Presentation\Models\Tables\Column>
     */
    public function getColumns(): array;

    /**
     * @param array<int, mixed> $columns
     */
    public function setColumns(array $columns): void;

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getRows(): array;

    /**
     * @param array<int|string, array<string, mixed>> $rows
     */
    public function setRows(array &$rows): void;

    public function makeOutput(): mixed;
    public function showOutput(): void;
}
