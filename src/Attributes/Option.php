<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Attributes;

/**
 * Describes a backed-enum case used as a choice option. Cases without it get no description.
 *
 * ```php
 * enum Department: string
 * {
 *     #[Option('Exchanges, wrong or damaged items', notFor: 'Refund requests', examples: ['arrived broken'])]
 *     case Returns = 'returns';
 *
 *     case Other = 'other';
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS_CONSTANT)]
final class Option
{
    /**
     * @param  array<array-key, mixed>|string|null  $what
     * @param  array<array-key, mixed>|string|null  $notFor
     * @param  list<mixed>|null  $examples
     */
    public function __construct(
        public readonly array|string|null $what = null,
        public readonly array|string|null $notFor = null,
        public readonly ?array $examples = null,
    ) {}
}
