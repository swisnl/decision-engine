<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Answers;

use Swis\DecisionEngine\Contracts\Answer;
use Swis\DecisionEngine\Outcome\Certainty;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Questions\QuestionType;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Support\Enums;

/**
 * Answer to a Choice question.
 *
 * ```php
 * $a = $outcome->department;
 * $a->choice;                 // 'billing'
 * $a->confidence;             // 0.82
 * $a->probability('returns'); // 0.05
 * $a->is('billing');          // true
 * $a->ranked();               // ['billing' => 0.91, 'returns' => 0.05, 'shipping' => 0.04]
 * $a->top(2);                 // ['billing' => 0.91, 'returns' => 0.05]
 * $a->isConfident(0.9);       // false
 * ```
 */
final class ChoiceAnswer implements Answer
{
    /**
     * @param  array<string, float>  $probabilities
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        private readonly string $id,
        public readonly string $choice,
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
        $choice = $raw['choice'] ?? null;

        if (! is_string($choice) && ! is_int($choice)) {
            throw MalformedAnswerException::type("answers.{$id}.choice", 'a string');
        }

        $rawProbabilities = Arr::array($raw, 'probabilities') ?? throw MalformedAnswerException::required("answers.{$id}.probabilities");
        $probabilities = [];

        foreach ($rawProbabilities as $option => $probability) {
            if (! is_int($probability) && ! is_float($probability)) {
                throw MalformedAnswerException::type("answers.{$id}.probabilities.{$option}", 'a number');
            }

            $probabilities[(string) $option] = (float) $probability;
        }

        $confidence = Arr::float($raw, 'confidence') ?? throw MalformedAnswerException::required("answers.{$id}.confidence");

        return new self($id, (string) $choice, $probabilities, $confidence, $raw, $thresholds ?? new Thresholds());
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): QuestionType
    {
        return QuestionType::Choice;
    }

    public function is(string $option): bool
    {
        return $this->choice === $option;
    }

    /**
     * The choice as a case of a backed enum whose values are the option names.
     *
     * @template E of \BackedEnum
     *
     * @param  class-string<E>  $enum
     * @return E
     */
    public function as(string $enum): \BackedEnum
    {
        return Enums::tryFrom($enum, $this->choice) ?? throw new \UnexpectedValueException("Choice [{$this->choice}] of [{$this->id}] is not a case of {$enum}.");
    }

    public function probability(string $option): float
    {
        return $this->probabilities[$option] ?? 0.0;
    }

    /**
     * Options ordered by probability, highest first. Ties keep the server's order.
     *
     * @return array<string, float>
     */
    public function ranked(): array
    {
        $ranked = $this->probabilities;
        uasort($ranked, static fn(float $a, float $b): int => $b <=> $a);

        return $ranked;
    }

    /**
     * @return array<string, float>
     */
    public function top(int $count): array
    {
        return array_slice($this->ranked(), 0, max(0, $count), true);
    }

    /**
     * @return list<string>
     */
    public function options(): array
    {
        return array_keys($this->probabilities);
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
        return new self($this->id, $this->choice, $this->probabilities, $this->confidence, $this->raw, $thresholds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
