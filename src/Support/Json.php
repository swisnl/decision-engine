<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Support;

/**
 * JSON helpers with a single, deterministic encoding configuration.
 *
 * ```php
 * Json::encode(['b' => 1, 'a' => 2]);          // '{"b":1,"a":2}'
 * Json::canonical(['b' => 1, 'a' => 2]);       // '{"a":2,"b":1}' — keys sorted recursively, for hashing/diffing
 * Json::normalize(new ArrayObject(['x' => 1])); // ['x' => 1]
 * ```
 */
final class Json
{
    public const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Encode with the package's standard flags.
     */
    public static function encode(mixed $value, bool $pretty = false): string
    {
        return json_encode($value, self::FLAGS | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    /**
     * Encode with every object key sorted recursively so equal structures produce equal bytes.
     * Lists keep their order.
     */
    public static function canonical(mixed $value): string
    {
        return json_encode(self::sortKeys($value), self::FLAGS);
    }

    /**
     * Decode to an associative array. Throws \JsonException on malformed input.
     *
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    public static function decode(string $json): array|string|int|float|bool|null
    {
        /** @var array<array-key, mixed>|string|int|float|bool|null $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Decode to an array, or throw when the top level is not an object/array.
     *
     * @return array<array-key, mixed>
     */
    public static function decodeArray(string $json): array
    {
        $decoded = self::decode($json);

        if (! is_array($decoded)) {
            throw new \JsonException('Expected a JSON object or array at the top level, got ' . get_debug_type($decoded) . '.');
        }

        return $decoded;
    }

    /**
     * Convert any PHP value to a JSON-safe value: scalars and null pass through,
     * JsonSerializable is unwrapped, Stringable becomes a string, other objects become arrays,
     * arrays are normalized recursively.
     *
     * @return array<array-key, mixed>|string|int|float|bool|null
     */
    public static function normalize(mixed $value): array|string|int|float|bool|null
    {
        if ($value instanceof \JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if ($value instanceof \Traversable) {
            return self::normalize(iterator_to_array($value));
        }

        if (is_object($value)) {
            return self::normalize(get_object_vars($value));
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = self::normalize($item);
            }

            return $result;
        }

        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('Cannot represent ' . get_debug_type($value) . ' as JSON.');
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = [];

        foreach ($value as $key => $item) {
            $sorted[$key] = self::sortKeys($item);
        }

        if (! array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}
