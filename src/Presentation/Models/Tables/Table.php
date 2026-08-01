<?php

namespace StaticPHP\Presentation\Models\Tables;

use StaticPHP\Presentation\Models\Tables\Interfaces\TableInterface;
use StaticPHP\Presentation\Models\Tables\Interfaces\OutputInterface;
use StaticPHP\Presentation\Models\Tables\Enums\RowPosition;

class Table implements TableInterface
{
    public ?Sort $sort = null;
    public ?Filters $filter = null;
    public ?Pagination $pagination = null;
    public ?OutputInterface $outputGenerator = null;

    /** @var array<string, Column> */
    public array $columns = [];

    /** @var array<int|string, array<string, mixed>> */
    public array $rows = [];

    /** @var array<int|string, mixed> */
    public array $children = [];

    public null|\Closure $initRow = null;

    /** @var array<string, mixed>|null */
    public ?array $avgRow = null;
    public RowPosition $avgRowPosition = RowPosition::BODY_TOP;

    /** @var array<string, mixed>|null */
    public ?array $sumRow = null;
    public RowPosition $sumRowPosition = RowPosition::BODY_TOP;

    /** @var array<string, mixed>|null */
    public ?array $customRow = null;
    public RowPosition $customRowPosition = RowPosition::BODY_TOP;

    /** @var array<string, mixed>|null */
    public ?array $beforeDataRow = null;

    /** @var array<string, mixed>|null */
    public ?array $afterDataRow = null;

    public bool|\Closure $isEditable = false;

    /**
     * Render non-editable rows as disabled inputs rather than as plain text.
     *
     * When isEditable resolves per row, the editable rows are inputs and the rest are bare
     * text, so column widths jump about between rows. This keeps every row the same shape
     * and lets the disabled state carry the meaning instead.
     */
    public bool $showReadonlyInputs = false;

    public null|string|\Closure $idKey = null;

    /**
     * Unique table id
     *
     * (default value: '')
     *
     * @var string
     * @access protected
     */
    protected string $tableId = '';

    /**
     * Url prefix
     *
     * (default value: '')
     *
     * @var string
     * @access protected
     */
    protected string $urlPrefix = '';


    /**
     * @param array<int, mixed> $columns Validated by setColumns()
     */
    public function __construct(
        array $columns,
        string $urlPrefix = ''
    ) {
        // time() plus one of a hundred values is guessable; this only needs to be unique
        // within a page, but a predictable id is a needless hint to anything scripting it
        $this->tableId = bin2hex(random_bytes(8));
        $this->urlPrefix = $urlPrefix;

        $this->setColumns($columns);
    }


    /**
     * Returns table's unique id
     *
     * @access public
     * @return string
     */
    public function tableId(): string
    {
        return $this->tableId;
    }

    /**
     * Parse query string using $delimiter
     *
     * @param string $str Query string
     * @param string $delimiter Delimiter
     * @return array<string, string>
     */
    public function parseQueryString(string $str, string $delimiter = '&'): array
    {
        $op = [];
        $pairs = explode(($delimiter === '' ? '&' : $delimiter), $str);
        foreach ($pairs as $pair) {
            $ex = explode("=", $pair);
            if (count($ex) < 2) {
                continue;
            }
            list($k, $v) = array_map("urldecode", $ex);
            $op[$k] = $v;
        }

        return $op;
    }


    /**
     * If parameters are real null, skip class init
     */
    public function initData(
        ?string $filterData = null,
        ?string $sortData = null,
        ?int $page = null
    ): void {
        if ($filterData !== null) {
            $this->filter = new Filters($this, "{$this->urlPrefix}%filter/{$sortData}", $filterData);
        }
        if ($sortData !== null) {
            $this->sort = new Sort($this, "{$this->urlPrefix}{$filterData}/%sort", $sortData);
        }
        if ($page !== null) {
            $this->pagination = new Pagination($this, "{$this->urlPrefix}{$filterData}/{$sortData}/%pagination", $page);
        }
    }


    /**
     * @return array<string, Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @param array<int, mixed> $columns
     */
    public function setColumns(array $columns): void
    {
        foreach ($columns as $column) {
            if ($column instanceof Column == false) {
                throw new \Exception("Not all columns are instances of Column");
            }

            $this->columns[$column->id] = $column;
        }
    }


    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @param array<int|string, array<string, mixed>> $rows
     */
    public function setRows(array &$rows): void
    {
        $this->rows = &$rows;

        // Format row values
        if ($this->initRow !== null) {
            foreach ($this->rows as $rowIndex => $row) {
                $formatted = Utils::expandClosure($this->initRow, [$rowIndex, $row]);
                $this->rows[$rowIndex] = (is_array($formatted) ? $formatted : $row);
            }
        }

        // Format column values
        foreach ($this->columns as $column) {
            if ($column->initValue !== null) {
                foreach ($this->rows as $rowIndex => $row) {
                    $this->rows[$rowIndex][$column->id] = Utils::expandClosure(
                        $column->initValue,
                        [$column, $rowIndex, $row]
                    );
                }
            }
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * @param array<int|string, mixed> $children
     */
    public function setChildren(array &$children): void
    {
        $this->children = &$children;
    }

    public function makeOutput(): mixed
    {
        if (!empty($this->outputGenerator)) {
            return $this->outputGenerator->makeOutput();
        }

        return null;
    }

    public function showOutput(): void
    {
        if (!empty($this->outputGenerator)) {
            $this->outputGenerator->showOutput();
        }
    }
}
