<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Attributes;

/**
 * Declares a choice question on a TypedOutcome property. The property type picks what you get:
 * `ChoiceAnswer` (choice, probabilities, confidence), `string` (the chosen option) or a backed enum
 * (the chosen case). Options come from `options`, or from the property's enum when omitted.
 *
 * ```php
 * #[Choice('Which team should handle this?', options: ['billing' => 'Charges, invoices', 'other' => null])]
 * public readonly ChoiceAnswer $department;
 *
 * #[Choice('Which team should handle this?')]      // options from the enum's cases
 * public readonly Department $department;
 *
 * #[Choice('Which team should handle this?', options: Department::class)]
 * public readonly ChoiceAnswer $department;        // $triage->department->as(Department::class)
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Choice
{
    /**
     * @param  array<array-key, mixed>|string|null  $instructions
     * @param  array<array-key, mixed>|class-string<\BackedEnum>|null  $options  option → description, or a backed enum class
     * @param  string|null  $id  question id; defaults to the property name
     */
    public function __construct(
        public readonly array|string|null $instructions = null,
        public readonly array|string|null $options = null,
        public readonly ?string $id = null,
    ) {}
}
