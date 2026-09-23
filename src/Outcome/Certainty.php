<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Outcome;

/**
 * Three-way certainty band, the recommended starting point for confidence-gated routing:
 * high → act automatically, medium → proceed with caution, low → do not act.
 *
 * ```php
 * match ($outcome->department->band()) {
 *     Certainty::High   => $this->route($outcome->department->choice),
 *     Certainty::Medium => $this->routeAndFlag($outcome->department->choice),
 *     Certainty::Low    => $this->escalate(),
 * };
 * ```
 */
enum Certainty: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::High => 2,
            self::Medium => 1,
            self::Low => 0,
        };
    }
}
