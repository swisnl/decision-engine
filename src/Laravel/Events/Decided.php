<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel\Events;

use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Request\DecisionRequest;

/**
 * Dispatched after every successful decision.
 */
final class Decided
{
    public function __construct(
        public readonly DecisionRequest $request,
        public readonly Outcome $outcome,
    ) {}
}
