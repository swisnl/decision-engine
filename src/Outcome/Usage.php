<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Outcome;

use Swis\DecisionEngine\Support\Arr;

/**
 * Token usage reported by the engine.
 *
 * ```php
 * $outcome->usage->inputTokens;  // 360
 * $outcome->usage->outputTokens; // 39
 * $outcome->usage->total();      // 399
 * ```
 */
final class Usage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public static function zero(): self
    {
        return new self();
    }

    /**
     * @param  array<string, mixed>  $data  `{input_tokens, output_tokens}`
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::int($data, 'input_tokens') ?? Arr::int($data, 'inputTokens') ?? 0,
            Arr::int($data, 'output_tokens') ?? Arr::int($data, 'outputTokens') ?? 0,
        );
    }

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function add(self $other): self
    {
        return new self($this->inputTokens + $other->inputTokens, $this->outputTokens + $other->outputTokens);
    }

    /**
     * @return array{input_tokens: int, output_tokens: int}
     */
    public function toArray(): array
    {
        return ['input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens];
    }
}
