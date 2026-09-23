<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel\Events;

use Swis\DecisionEngine\Request\DecisionRequest;

/**
 * Dispatched when a decision throws (validation, transport or API failure).
 */
final class DecisionFailed
{
    public function __construct(
        public readonly DecisionRequest $request,
        public readonly \Throwable $exception,
    ) {}
}
