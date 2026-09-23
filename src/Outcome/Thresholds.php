<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Outcome;

use Swis\DecisionEngine\Support\Arr;

/**
 * Boundaries for the Certainty bands. Defaults follow the TypeSafe docs (high > 0.9, medium ≥ 0.5)
 * but stakes decide the numbers; configure them per project or pass them per call.
 *
 * ```php
 * $t = new Thresholds(high: 0.95, medium: 0.6);
 * $t->band(0.97); // Certainty::High
 * $answer->band($t);
 * ```
 */
final class Thresholds
{
    public function __construct(
        public readonly float $high = 0.9,
        public readonly float $medium = 0.5,
    ) {
        if ($medium > $high) {
            throw new \InvalidArgumentException('The medium threshold must not exceed the high threshold.');
        }
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Arr::float($data, 'high') ?? 0.9, Arr::float($data, 'medium') ?? 0.5);
    }

    public function band(float $certainty): Certainty
    {
        return match (true) {
            $certainty > $this->high => Certainty::High,
            $certainty >= $this->medium => Certainty::Medium,
            default => Certainty::Low,
        };
    }

    /**
     * @return array{high: float, medium: float}
     */
    public function toArray(): array
    {
        return ['high' => $this->high, 'medium' => $this->medium];
    }
}
