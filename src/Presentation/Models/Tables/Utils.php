<?php

namespace StaticPHP\Presentation\Models\Tables;

class Utils
{
    /**
     * @return array<int|string, mixed>
     */
    public static function ensureArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }

    /**
     * @param array<int, mixed> $args
     */
    public static function valueOrClosure(mixed $value, ?\Closure $closure = null, array $args = []): mixed
    {
        if (empty($closure)) {
            return $value;
        }

        array_unshift($args, $value);
        return call_user_func_array($closure, $args);
    }

    /**
     * @param array<int, mixed> $args
     */
    public static function expandClosure(mixed $closure, array $args = []): mixed
    {
        if (is_callable($closure)) {
            return call_user_func_array($closure, $args);
        }

        return $closure;
    }

    /**
     * Resolve every element, calling the closures among them, and return the results as
     * strings.
     *
     * Callers implode the result straight into markup - html attributes, class lists - so
     * a value with no string form was never usable. Coercing here rather than at each of
     * the dozen call sites keeps the attribute builders working with strings throughout.
     *
     * @param  array<int|string, mixed> $arrayOfData
     * @param  array<int, mixed>        $args
     * @return array<int|string, string>
     */
    public static function runClosures(array $arrayOfData, array $args = []): array
    {
        $resolved = [];
        foreach ($arrayOfData as $key => $item) {
            $value = self::expandClosure($item, $args);
            $resolved[$key] = (is_scalar($value) ? (string) $value : '');
        }

        return $resolved;
    }
}
