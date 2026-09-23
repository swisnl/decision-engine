<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Support;

/**
 * Small array helpers used across the package.
 *
 * ```php
 * Arr::only(['a' => 1, 'b' => 2], ['a']);   // ['a' => 1]
 * Arr::except(['a' => 1, 'b' => 2], ['a']); // ['b' => 2]
 * Arr::withoutNulls(['a' => 1, 'b' => null]); // ['a' => 1]
 * ```
 */
final class Arr
{
    /**
     * @template K of array-key
     * @template T
     *
     * @param  array<K, T>  $array
     * @param  list<array-key>  $keys
     * @return array<K, T>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_fill_keys($keys, true));
    }

    /**
     * @template K of array-key
     * @template T
     *
     * @param  array<K, T>  $array
     * @param  list<array-key>  $keys
     * @return array<K, T>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_fill_keys($keys, true));
    }

    /**
     * @template K of array-key
     * @template T
     *
     * @param  array<K, T|null>  $array
     * @return array<K, T>
     */
    public static function withoutNulls(array $array): array
    {
        return array_filter($array, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Recursive replace that keeps string keys typed (unlike array_replace_recursive).
     * Lists in `$replacement` overwrite lists in `$base` wholesale.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $replacement
     * @return array<string, mixed>
     */
    public static function replaceRecursive(array $base, array $replacement): array
    {
        foreach ($replacement as $key => $value) {
            $existing = $base[$key] ?? null;

            if (is_array($existing) && is_array($value) && ! array_is_list($existing) && ! array_is_list($value)) {
                $base[$key] = self::replaceRecursive(self::stringKeys($existing), self::stringKeys($value));
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Read `$array[$key]` and assert it is a string.
     *
     * @param  array<array-key, mixed>  $array
     */
    public static function string(array $array, string $key, ?string $default = null): ?string
    {
        $value = $array[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            throw new \InvalidArgumentException("Expected [{$key}] to be a string, got " . get_debug_type($value) . '.');
        }

        return $value;
    }

    /**
     * Read `$array[$key]` and assert it is an array.
     *
     * @param  array<array-key, mixed>  $array
     * @param  array<array-key, mixed>|null  $default
     * @return array<array-key, mixed>|null
     */
    public static function array(array $array, string $key, ?array $default = null): ?array
    {
        $value = $array[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (! is_array($value)) {
            throw new \InvalidArgumentException("Expected [{$key}] to be an array, got " . get_debug_type($value) . '.');
        }

        return $value;
    }

    /**
     * Read `$array[$key]` and assert it is a float or int.
     *
     * @param  array<array-key, mixed>  $array
     */
    public static function float(array $array, string $key, ?float $default = null): ?float
    {
        $value = $array[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new \InvalidArgumentException("Expected [{$key}] to be a number, got " . get_debug_type($value) . '.');
        }

        return (float) $value;
    }

    /**
     * Read `$array[$key]` and assert it is an int.
     *
     * @param  array<array-key, mixed>  $array
     */
    public static function int(array $array, string $key, ?int $default = null): ?int
    {
        $value = $array[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (! is_int($value)) {
            throw new \InvalidArgumentException("Expected [{$key}] to be an integer, got " . get_debug_type($value) . '.');
        }

        return $value;
    }

    /**
     * Read `$array[$key]` and assert it is a bool.
     *
     * @param  array<array-key, mixed>  $array
     */
    public static function bool(array $array, string $key, ?bool $default = null): ?bool
    {
        $value = $array[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (! is_bool($value)) {
            throw new \InvalidArgumentException("Expected [{$key}] to be a boolean, got " . get_debug_type($value) . '.');
        }

        return $value;
    }

    /**
     * Cast an array with arbitrary keys to `array<string, mixed>` (integer keys become strings).
     *
     * @param  array<array-key, mixed>  $array
     * @return array<string, mixed>
     */
    public static function stringKeys(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
