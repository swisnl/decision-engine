<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Support;

/**
 * The handful of formulas behind System One answers, implemented once and shared by the
 * LLM engines (which must derive these values) and the client-side helpers.
 *
 * ```php
 * Math::normalize(['a' => 2.0, 'b' => 2.0]);          // ['a' => 0.5, 'b' => 0.5]
 * Math::argmax(['a' => 0.3, 'b' => 0.4, 'c' => 0.3]);  // 'b'
 * Math::expectedScore([0 => 0.0, 1 => 0.57, 2 => 0.43]); // 1.43
 * Math::confidence(3, 0.85);                            // 0.775
 * Math::certaintyFromNoul(0.84);                        // 0.68
 * ```
 */
final class Math
{
    /**
     * Clamp every value to [0, 1] and rescale so the sum is 1. All-zero (or empty) input
     * becomes a uniform distribution.
     *
     * @template K of array-key
     *
     * @param  array<K, float|int>  $values
     * @return array<K, float>
     */
    public static function normalize(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $clamped = array_map(static fn(float|int $v): float => self::clamp((float) $v), $values);
        $sum = array_sum($clamped);

        if ($sum <= 0.0) {
            $uniform = 1.0 / count($clamped);

            return array_map(static fn(): float => $uniform, $clamped);
        }

        return array_map(static fn(float $v): float => $v / $sum, $clamped);
    }

    /**
     * Key of the largest value; on a tie the first key in iteration order wins (stable).
     *
     * @template K of array-key
     *
     * @param  non-empty-array<K, float|int>  $values
     * @return K
     */
    public static function argmax(array $values): int|string
    {
        $bestKey = null;
        $bestValue = -INF;

        foreach ($values as $key => $value) {
            if ((float) $value > $bestValue) {
                $bestValue = (float) $value;
                $bestKey = $key;
            }
        }

        if ($bestKey === null) {
            throw new \InvalidArgumentException('argmax() of an empty array.');
        }

        return $bestKey;
    }

    /**
     * Expected level: Σ level × probability. Keys are level indices (int or numeric string).
     *
     * @param  array<array-key, float|int>  $probabilities
     */
    public static function expectedScore(array $probabilities): float
    {
        $score = 0.0;

        foreach ($probabilities as $level => $probability) {
            $score += (int) $level * (float) $probability;
        }

        return $score;
    }

    /**
     * TypeSafe's confidence statistic for an n-way distribution: `(n · p_max − 1) / (n − 1)`,
     * 0 for a uniform distribution and 1 for a one-hot one. Matches the documented 3-option
     * form `(3 · p_max − 1) / 2` and the quickstart example (0.85 over 3 options → 0.775 ≈ 0.78).
     */
    public static function confidence(int $optionCount, float $maxProbability): float
    {
        if ($optionCount <= 1) {
            return 1.0;
        }

        return self::clamp(($optionCount * $maxProbability - 1.0) / ($optionCount - 1));
    }

    /**
     * Confidence of a full distribution.
     *
     * @param  non-empty-array<array-key, float|int>  $probabilities
     */
    public static function confidenceOf(array $probabilities): float
    {
        return self::confidence(count($probabilities), (float) max($probabilities));
    }

    /**
     * Client-side certainty of a Noul answer: `|2p − 1|`, the two-way case of `confidence()`.
     * Documented as derived; Jev itself reports no confidence for Noul.
     */
    public static function certaintyFromNoul(float $noul): float
    {
        return self::clamp(abs(2.0 * $noul - 1.0));
    }

    public static function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }

    /**
     * Compare floats with a tolerance; used in tests and validation of probability sums.
     */
    public static function approximately(float $a, float $b, float $epsilon = 1e-6): bool
    {
        return abs($a - $b) <= $epsilon;
    }
}
