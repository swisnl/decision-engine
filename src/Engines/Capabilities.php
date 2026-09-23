<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines;

/**
 * What an engine can handle. Used by the client-side Validator so bad requests fail before any I/O.
 *
 * ```php
 * $caps = Capabilities::jev();          // 255 options, 2–10 levels, calibrated
 * $caps->with(calibrated: false);       // for a structured-output LLM engine
 * ```
 */
final class Capabilities
{
    public function __construct(
        public readonly int $maxChoiceOptions = 255,
        public readonly int $minScoreLevels = 2,
        public readonly int $maxScoreLevels = 10,
        public readonly bool $calibrated = true,
        public readonly bool $supportsNullState = true,
        public readonly bool $supportsStructuredInstructions = true,
    ) {}

    /**
     * Jev rejects `"state": null` (422 "Field required"); `""` or `[]` are fine.
     */
    public static function jev(): self
    {
        return new self(supportsNullState: false);
    }

    public static function llm(): self
    {
        return new self(calibrated: false);
    }

    public function with(
        ?int $maxChoiceOptions = null,
        ?int $minScoreLevels = null,
        ?int $maxScoreLevels = null,
        ?bool $calibrated = null,
        ?bool $supportsNullState = null,
        ?bool $supportsStructuredInstructions = null,
    ): self {
        return new self(
            $maxChoiceOptions ?? $this->maxChoiceOptions,
            $minScoreLevels ?? $this->minScoreLevels,
            $maxScoreLevels ?? $this->maxScoreLevels,
            $calibrated ?? $this->calibrated,
            $supportsNullState ?? $this->supportsNullState,
            $supportsStructuredInstructions ?? $this->supportsStructuredInstructions,
        );
    }
}
