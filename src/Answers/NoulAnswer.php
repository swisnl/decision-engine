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
 * Answer to a Noul (yes/no) question. `noul` is the probability that the answer is yes.
 *
 * ```php
 * $a = $outcome->wants_human;   // noul 0.84
 * $a->isTrue();                 // true  (≥ 0.5)
 * $a->isTrue(0.9);              // false
 * $a->isFalse(0.9);             // false (P(no) = 0.16)
 * $a->isUncertain(0.2, 0.8);    // false
 * $a->toBool();                 // true
 * $a->certainty();              // 0.68 = |2·0.84 − 1|, derived client-side
 * ```
 */
final class NoulAnswer implements Answer
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        private readonly string $id,
        public readonly float $noul,
        private readonly array $raw,
        private readonly Thresholds $thresholds = new Thresholds(),
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(string $id, array $raw, ?Thresholds $thresholds = null): self
    {
        $noul = Arr::float($raw, 'noul') ?? throw MalformedAnswerException::required("answers.{$id}.noul");

        return new self($id, $noul, $raw, $thresholds ?? new Thresholds());
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): QuestionType
    {
        return QuestionType::Noul;
    }

    /**
     * Probability of yes is at least `$threshold`.
     */
    public function isTrue(float $threshold = 0.5): bool
    {
        return $this->noul >= $threshold;
    }

    /**
     * Probability of no (`1 − noul`) is at least `$threshold`.
     */
    public function isFalse(float $threshold = 0.5): bool
    {
        return (1.0 - $this->noul) >= $threshold;
    }

    /**
     * `noul` lies strictly between the two bounds: neither a clear yes nor a clear no.
     */
    public function isUncertain(float $low = 0.2, float $high = 0.8): bool
    {
        return $this->noul > $low && $this->noul < $high;
    }

    public function toBool(float $threshold = 0.5): bool
    {
        return $this->isTrue($threshold);
    }

    /**
     * Probability of no.
     */
    public function complement(): float
    {
        return 1.0 - $this->noul;
    }

    /**
     * `|2·noul − 1|`: 0 at 0.5, 1 at 0 or 1. Derived client-side; Jev reports no confidence for Noul.
     */
    public function certainty(): float
    {
        return Math::certaintyFromNoul($this->noul);
    }

    public function band(?Thresholds $thresholds = null): Certainty
    {
        return ($thresholds ?? $this->thresholds)->band($this->certainty());
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
        return new self($this->id, $this->noul, $this->raw, $thresholds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
