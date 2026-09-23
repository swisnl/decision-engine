<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Answers;

use Swis\DecisionEngine\Contracts\Answer;
use Swis\DecisionEngine\Outcome\Certainty;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Questions\QuestionType;
use Swis\DecisionEngine\Support\Arr;

/**
 * An answer of a type this package does not model (yet). Nothing is dropped: read fields with
 * `get()` or the full array with `raw()`. `certainty()` uses a `confidence` field when present.
 *
 * ```php
 * $a = $outcome->answer('mystery'); // {"type": "vector", "values": [...], "confidence": 0.5}
 * $a->rawType();          // 'vector'
 * $a->get('values');      // [...]
 * $a->certainty();        // 0.5
 * ```
 */
final class GenericAnswer implements Answer
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        private readonly string $id,
        private readonly array $raw,
        private readonly Thresholds $thresholds = new Thresholds(),
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function type(): ?QuestionType
    {
        return QuestionType::tryFrom($this->rawType() ?? '');
    }

    public function rawType(): ?string
    {
        $type = $this->raw['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    public function certainty(): float
    {
        try {
            return Arr::float($this->raw, 'confidence') ?? 0.0;
        } catch (\InvalidArgumentException) {
            return 0.0;
        }
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
        return new self($this->id, $this->raw, $thresholds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
