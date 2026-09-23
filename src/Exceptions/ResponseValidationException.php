<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * A 2xx response whose body does not have the expected structure. `fieldPath` is the dotted path
 * of the offending field, e.g. `answers.tone.confidence`.
 */
final class ResponseValidationException extends ApiException
{
    public function __construct(
        string $message,
        public readonly string $fieldPath,
        HttpResponse $response,
        ?PreparedRequest $preparedRequest = null,
        ?string $engine = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $response, $preparedRequest, $engine, $previous);
    }

    public static function at(string $fieldPath, string $problem, HttpResponse $response, ?PreparedRequest $request = null, ?string $engine = null, ?\Throwable $previous = null): self
    {
        return new self("Invalid response body at [{$fieldPath}]: {$problem}", $fieldPath, $response, $request, $engine, $previous);
    }
}
