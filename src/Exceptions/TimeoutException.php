<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

use Swis\DecisionEngine\Request\PreparedRequest;

/**
 * The per-attempt timeout elapsed.
 */
final class TimeoutException extends TransportException
{
    public function __construct(string $message, public readonly float $timeout, ?PreparedRequest $request = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $request, $previous);
    }
}
