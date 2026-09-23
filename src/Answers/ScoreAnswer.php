<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Answers;

use Swis\DecisionEngine\Contracts\Answer;
use Swis\DecisionEngine\Outcome\Certainty;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Questions\QuestionType;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Support\Math;

/**
 * Answer to a Score question.
 *
 * ```php
 * $a = $outcome->severity;
 * $a->score;                   // 1.43 (expected level, Σ level × probability)
 * $a->normalized();            // 0.715 (score / (levels − 1))
 * $a->level();                 // 1 — the most probable level
 * $a->levelDescription();      // 'Broken or degraded feature, but workaround exists'
 * $a->probabilityAtLeast(2);   // 0.43 — cumulative probability of level ≥ 2
 * $a->atLeast(1.5);            // false
 * $a->confidence;              // 0.35
 * ```
 */
final class ScoreAnswer implements Answer
{
    /**
     * @param  array<int, array<array-key, mixed>|string|null>  $legend
     * @param  array<int, float>  $probabilities
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        private readonly string $id,
        public readonly float $score,
        public readonly array $legend,
        public readonly array $probabilities,
        public readonly float $confidence,
        private readonly array $raw,
        private readonly Thresholds $thresholds = new Thresholds(),
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(string $id, array $raw, ?Thresholds $thresholds = null): self
    {
        $score = Arr::float($raw, 'score') ?? throw MalformedAnswerException::required("answers.{$id}.score");
        $confidence = Arr::float($raw, 'confidence') ?? throw MalformedAnswerException::required("answers.{$id}.confidence");
        $rawProbabilities = Arr::array($raw, 'probabilities') ?? throw MalformedAnswerException::required("answers.{$id}.probabilities");
        $rawLegend = Arr::array($raw, 'legend') ?? [];

        $probabilities = [];

        foreach ($rawProbabilities as $level => $probability) {
            if (! is_int($probability) && ! is_float($probability)) {
                throw MalformedAnswerException::type("answers.{$id}.probabilities.{$level}", 'a number');
            }

            $probabilities[(int) $level] = (float) $probability;
        }

        ksort($probabilities);

        $legend = [];

        foreach ($rawLegend as $level => $description) {
            if ($description !== null && ! is_string($description) && ! is_array($description)) {
                throw MalformedAnswerException::type("answers.{$id}.legend.{$level}", 'a string, object or null');
            }

            $legend[(int) $level] = $description;
        }

        ksort($legend);

        return new self($id, $score, $legend, $probabilities, $confidence, $raw, $thresholds ?? new Thresholds());
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): QuestionType
    {
        return QuestionType::Score;
    }

    public function levels(): int
    {
        return max(count($this->probabilities), count($this->legend));
    }

    /**
     * Score rescaled to 0..1 (`score / (levels − 1)`).
     */
    public function normalized(): float
    {
        $levels = $this->levels();

        return $levels <= 1 ? 0.0 : Math::clamp($this->score / ($levels - 1));
    }

    /**
     * The most probable level (argmax; first on tie). Differs from `round($score)` when the
     * distribution is bimodal.
     */
    public function level(): int
    {
        if ($this->probabilities === []) {
            return (int) round($this->score);
        }

        return (int) Math::argmax($this->probabilities);
    }

    /**
     * @return array<array-key, mixed>|string|null
     */
    public function levelDescription(?int $level = null): array|string|null
    {
        return $this->legend[$level ?? $this->level()] ?? null;
    }

    public function probability(int $level): float
    {
        return $this->probabilities[$level] ?? 0.0;
    }

    /**
     * Cumulative probability that the true level is at least `$level`.
     */
    public function probabilityAtLeast(int $level): float
    {
        $sum = 0.0;

        foreach ($this->probabilities as $index => $probability) {
            if ($index >= $level) {
                $sum += $probability;
            }
        }

        return Math::clamp($sum);
    }

    public function atLeast(float $score): bool
    {
        return $this->score >= $score;
    }

    public function isConfident(float $threshold = 0.9): bool
    {
        return $this->confidence >= $threshold;
    }

    public function certainty(): float
    {
        return $this->confidence;
    }

    public function band(?Thresholds $thresholds = null): Certainty
    {
        return ($thresholds ?? $this->thresholds)->band($this->confidence);
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }

    public function withThresholds(Thresholds $thresholds): static
    {
        return new self($this->id, $this->score, $this->legend, $this->probabilities, $this->confidence, $this->raw, $thresholds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
