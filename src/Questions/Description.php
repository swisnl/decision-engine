<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Support\Json;

/**
 * Builds the structured descriptions TypeSafe recommends for options, levels and criteria.
 *
 * A description is `string | array | null`. When only `what` is given the plain value is used
 * unchanged, so the simple form stays byte-identical to the docs; as soon as `notFor`,
 * `examples` or `signals` are present the `{what, not_for, examples, signals}` object is built.
 *
 * ```php
 * Description::make('Charges and invoices');
 * // 'Charges and invoices'
 *
 * Description::make(what: 'Exchanges', notFor: 'Refunds', examples: ['wrong size']);
 * // ['what' => 'Exchanges', 'not_for' => 'Refunds', 'examples' => ['wrong size']]
 * ```
 */
final class Description
{
    /**
     * @param  list<mixed>|null  $examples
     * @param  list<mixed>|null  $signals
     * @return array<array-key, mixed>|string|null
     */
    public static function make(
        mixed $what = null,
        mixed $notFor = null,
        ?array $examples = null,
        ?array $signals = null,
    ): array|string|null {
        if ($notFor === null && $examples === null && $signals === null) {
            return self::normalize($what);
        }

        /** @var array<string, mixed> $structured */
        $structured = Arr::withoutNulls([
            'what' => self::normalize($what),
            'not_for' => self::normalize($notFor),
            'examples' => $examples === null ? null : array_values(array_map(self::normalize(...), $examples)),
            'signals' => $signals === null ? null : array_values(array_map(self::normalize(...), $signals)),
        ]);

        return $structured;
    }

    /**
     * Normalize a description value to `string | array | null`. Numbers and booleans become strings.
     *
     * @return array<array-key, mixed>|string|null
     */
    public static function normalize(mixed $value): array|string|null
    {
        $normalized = Json::normalize($value);

        if (is_int($normalized) || is_float($normalized)) {
            return (string) $normalized;
        }

        if (is_bool($normalized)) {
            return $normalized ? 'true' : 'false';
        }

        return $normalized;
    }
}
