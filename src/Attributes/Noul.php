<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Attributes;

/**
 * Declares a yes/no question on a TypedOutcome property. Type the property as `NoulAnswer` for the
 * full answer or `float` for the probability of yes. Thresholds stay in your code.
 *
 * ```php
 * #[Noul('Is the customer asking for a human agent?', true: 'Asks for a person, a call or a manager')]
 * public readonly NoulAnswer $wants_human;
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Noul
{
    /**
     * @param  array<array-key, mixed>|string|null  $instructions
     * @param  array<array-key, mixed>|string|null  $true  what counts as yes
     * @param  array<array-key, mixed>|string|null  $false  what counts as no
     * @param  string|null  $id  question id; defaults to the property name
     */
    public function __construct(
        public readonly array|string|null $instructions = null,
        public readonly array|string|null $true = null,
        public readonly array|string|null $false = null,
        public readonly ?string $id = null,
    ) {}
}
