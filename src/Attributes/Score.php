<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Attributes;

/**
 * Declares a score question on a TypedOutcome property, levels lowest first. Type the property as
 * `ScoreAnswer` for the full answer or `float` for the expected score (0 … levels − 1).
 *
 * ```php
 * #[Score('How severe is the issue?', levels: ['Cosmetic', 'Degraded', 'Blocking'])]
 * public readonly ScoreAnswer $severity;
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Score
{
    /**
     * @param  array<array-key, mixed>|string|null  $instructions
     * @param  list<mixed>  $levels  level descriptions, lowest first
     * @param  string|null  $id  question id; defaults to the property name
     */
    public function __construct(
        public readonly array|string|null $instructions = null,
        public readonly array $levels = [],
        public readonly ?string $id = null,
    ) {}
}
