<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

use Swis\DecisionEngine\Request\PreparedRequest;

/**
 * The request never produced an HTTP response (DNS, TLS, socket, timeout). Never used for HTTP
 * status codes — those are ApiException subclasses.
 */
class TransportException extends \RuntimeException implements DecisionEngineException
{
    public function __construct(string $message, public readonly ?PreparedRequest $request = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
