<?php

namespace StaticPHP\Presentation\Models\Tables;

use StaticPHP\Presentation\Models\Tables\Interfaces\ColumnInterface;
use StaticPHP\Presentation\Models\Tables\Enums\ColumnType;
use StaticPHP\Presentation\Models\Tables\Enums\FieldType;
use StaticPHP\Presentation\Models\Tables\Enums\FormatterType;
use StaticPHP\Presentation\Models\Tables\Enums\SortDirection;
use StaticPHP\Presentation\Models\Tables\Enums\SortNulls;

class Column implements ColumnInterface
{
    // ## Default
    public string $id;
    public string $title = '';
    public string $description = '';
    public ColumnType $type = ColumnType::TEXT;

    // ## Column
    public bool|\Closure $showColumn = true;

    // ## Sort
    public bool $sortEnabled = true;
    public null|string|\Closure $sortBy = null;
    public bool $sortDefaultColumn = false;
    public SortDirection $sortDefaultDirection = SortDirection::ASC;

    /**
     * Where nulls land in the sort order.
     *
     * Set this to SortNulls::NONE on mysql and mariadb - neither understands the
     * NULLS FIRST / NULLS LAST syntax the other two cases produce.
     */
    public SortNulls $sortNulls = SortNulls::LAST;
    public ?string $sortLinkAttribute = null;

    // ## Filter
    public bool $filterHidden = false;
    public bool $filterEnabled = true;
    public ?string $filterTitle = null;
    /** @var string|array<string, mixed>|null */
    public null|string|array $filterDefaultValue = null;
    public ?string $filterDateValue = null;

    public FieldType|\Closure $filterFieldType = FieldType::TEXT;

    /**
     * Elements can be string or Closure. If its a Closure, column and value are passed as arguments.
     *
     * @var array<int|string, string|\Closure>
     */
    public array $filterInputAttributes = [];

    /** @var array<int|string, string|\Closure> */
    public array $filterInputClasses = [];

    /** @var array<int|string, mixed>|null */
    public ?array $filterSelectOptions = null;
    public ?string $filterSelectOptionsIdKey = null;
    public ?string $filterSelectOptionsTitleKey = null;
    /** @var array<int|string, mixed>|null */
    public ?array $filterSelectOptionsGroups = null;
    public ?string $filterSelectOptionsGroupTitleKey = null;
    public bool $filterSelectMultiple = false;
    public bool $filterSelectSkipEmptyDefault = false;
    public bool $filterSelectDefaultDisabled = false;

    public null|string|\Closure $filterBy = null;
    public bool $filterZeroIsNULL = false;
    /** @var array<int|string, mixed>|\Closure|null */
    public null|array|\Closure $filterData = null;
    public bool $filterSqlDate = false;

    // ## Data
    public null|\Closure $initValue = null;
    public null|string|\Closure $idKey = null;
    public null|string|\Closure $dataKey = null;
    public FormatterType $dataFormatter = FormatterType::TEXT;
    /** @var array<int|string, mixed>|\Closure */
    public array|\Closure $dataColumnAttributes = [];
    /** @var array<int|string, mixed>|\Closure */
    public array|\Closure $dataColumnClasses = [];
    /** @var array<int|string, mixed>|\Closure */
    public array|\Closure $dataColumnPrefix = [];
    /** @var array<int|string, mixed>|\Closure */
    public array|\Closure $dataColumnAddon = [];
    public null|string|\Closure $dataColumnBage = null;

    // ## Edit
    public bool|\Closure $isEditable = false;
    public null|string|\Closure $editKey = null;
    public FieldType $editFieldType = FieldType::TEXT;
    /** @var array<int|string, mixed>|null */
    public ?array $editSelectOptions = null;
    public bool $editSelectOptionsGroupped = false;
    public mixed $switchValue = 1;

    // ## Export
    public bool|null|string|\Closure $exportKey = null;

    // ## Presentation
    /**
     * Elements can be string or Closure. If its a Closure, column is passed as the only argument.
     *
     * @var array<int|string, string|\Closure>
     */
    public array $columnAttributes = [];

    /**
     * Elements can be string or Closure. If its a Closure, column is passed as the only argument.
     *
     * @var array<int|string, string|\Closure>
     */
    public array $columnClasses = [];

    /**
     * Wether or not the column is expandable.
     */
    public bool $expandableText = false;

    /**
     * Should html be escaped in a column.
     *
     * Escaping is on by default. Only turn it off for a column whose data is trusted
     * markup produced by the application itself - never for anything reaching the table
     * from a request or from user editable database content.
     */
    public bool $escapeDataHtml = true;


    /**
     * @param string $id
     * @param mixed  ...$settings Property name => value pairs to set on the column
     */
    public function __construct($id, ...$settings)
    {
        $this->id = $id;
        foreach ($settings as $key => $value) {
            if (is_string($key) === false || property_exists($this, $key) === false) {
                throw new \Exception("\"{$key}\" does not exists on Column");
            }

            $this->{$key} = $value;
        }
    }
}
