<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Testing;

use Swis\DecisionEngine\Questions\QuestionType;

/**
 * Builders for fake answers, used with `Decision::fake([...])`.
 *
 * ```php
 * Decision::fake([
 *     'department' => Fake::choice('billing', confidence: 0.95),
 *     'severity'   => Fake::score(1.4, levels: 3),
 *     'wants_human'=> Fake::noul(0.1),
 *     'future'     => ['type' => 'vector', 'values' => [1, 2]],   // raw arrays pass through
 * ]);
 * ```
 */
final class Fake
{
    /**
     * @param  list<string>|null  $options  only needed when the question is not a Choice object (e.g. raw)
     * @param  array<string, mixed>  $extra
     */
    public static function choice(string $choice, float $confidence = 0.9, ?array $options = null, array $extra = []): FakeAnswer
    {
        return new FakeAnswer(QuestionType::Choice, $choice, $confidence, null, $options, $extra);
    }

    /**
     * @param  int|null  $levels  defaults to the question's level count
     * @param  float|null  $confidence  defaults to the confidence implied by the rendered distribution
     * @param  array<string, mixed>  $extra
     */
    public static function score(float $score, ?int $levels = null, ?float $confidence = null, array $extra = []): FakeAnswer
    {
        return new FakeAnswer(QuestionType::Score, $score, $confidence, $levels, null, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function noul(float $probability, array $extra = []): FakeAnswer
    {
        return new FakeAnswer(QuestionType::Noul, $probability, null, null, null, $extra);
    }

    public static function yes(): FakeAnswer
    {
        return self::noul(1.0);
    }

    public static function no(): FakeAnswer
    {
        return self::noul(0.0);
    }
}
