<?php

// TODO: Needs revision

namespace StaticPHP\Utils\Models;

use Iterator;
use JsonSerializable;
use ArrayAccess;

/**
 * @implements Iterator<string, mixed>
 * @implements ArrayAccess<string, mixed>
 */
class RecordObject implements Iterator, JsonSerializable, ArrayAccess
{
    public const DATA_RECORD = 0;
    public const DATA_FORMATTED_RECORD = 1;

    /** @var array<string, mixed> */
    protected array $record = [];

    /** @var array<string, mixed> */
    protected array $original_record = [];

    /** @var array<string, mixed> */
    protected array $formatted_record = [];

    protected bool $skip_format = false;


    /** =========================================== Class Magic ==================================================== */
    /**
     * @param array<string, mixed> $record
     */
    public function __construct(array $record, bool $skip_format = false)
    {
        $this->record = $record;
        $this->skip_format = $skip_format;

        if ($skip_format !== true) {
            $this->format();
        }
        $this->original_record = $this->record;
    }


    public function __get(string $name): mixed
    {
        if (isset($this->record[$name])) {
            return $this->record[$name];
        }

        if (isset($this->formatted_record[$name])) {
            return $this->formatted_record[$name];
        }

        return $this[$name];
    }


    public function __set(string $name, mixed $value): void
    {
        if (isset($this->record[$name])) {
            $this->record[$name] = $value;
        }

        if (isset($this->formatted_record[$name])) {
            $this->formatted_record[$name] = $value;
        }
    }

    public function __toString(): string
    {
        return (string) json_encode($this);
    }

    public function __debugInfo()
    {
        return [
            'record' => $this->record,
            'formatted_record' => $this->formatted_record,
        ];
    }



    /** =========================================== JSON ==================================================== */
    public function jsonSerialize(): mixed
    {
        return $this->record + $this->formatted_record;
    }



    /** =========================================== Instance methods ==================================================== */
    public function get(string $name, ?int $from = null): mixed
    {
        if (($from === null || $from == RecordObject::DATA_RECORD) && isset($this->record[$name])) {
            return $this->record[$name];
        }

        if (($from === null || $from == RecordObject::DATA_FORMATTED_RECORD) && isset($this->formatted_record[$name])) {
            return $this->formatted_record[$name];
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function record(): array
    {
        return $this->record;
    }

    /**
     * @return array<string, mixed>
     */
    public function originalRecord(): array
    {
        return $this->original_record;
    }

    public function format(): void
    {
        foreach ($this->record as $key => $value) {
            if (strpos($key, 'additional_fields_') !== false) {
                $new_key = str_replace('additional_fields_', '', $key);
                $this->formatted_record[$new_key] = $this->record[$key];
                unset($this->record[$key]);

                $encoded = $this->formatted_record[$new_key];
                if (is_string($encoded) && $encoded !== '') {
                    $this->formatted_record[$new_key] = json_decode($encoded, true);
                }
            }
        }
    }

    public function save(): void
    {
        $this->original_record = $this->record;
    }

    public function reload(): void
    {
        if ($this->skip_format !== true) {
            $this->format();
        }
        $this->original_record = $this->record;
    }



    /** =========================================== Iterator Implementation ==================================================== */
    public function rewind(): void
    {
        reset($this->record);
    }

    public function current(): mixed
    {
        $var = current($this->record);
        return $var;
    }

    public function key(): mixed
    {
        // key() reports null once the pointer is past the end, which an iterator only
        // reaches when valid() is already false
        return (string) key($this->record);
    }

    public function next(): void
    {
        next($this->record);
    }

    public function valid(): bool
    {
        return key($this->record) !== null;
    }



    /** =========================================== ArrayAccess Implementation ==================================================== */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            throw new \Exception("Adding empty array entries is not allowed");
        } else {
            $this->record[$offset] = $value;
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->record[$offset]) || isset($this->formatted_record[$offset]);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->record[$offset], $this->formatted_record[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (isset($this->record[$offset])) {
            return $this->record[$offset];
        }

        if (isset($this->formatted_record[$offset])) {
            return $this->formatted_record[$offset];
        }

        throw new \Exception("\"{$offset}\" not found.");
    }
}
