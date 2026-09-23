<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * HTTP 429. `retryAfterMs` is parsed from `retry-after-ms` or `Retry-After` (seconds or HTTP date).
 */
final class RateLimitException extends ApiException
{
    public readonly ?int $retryAfterMs;

    public function __construct(string $message, HttpResponse $response, ?PreparedRequest $preparedRequest = null, ?string $engine = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $response, $preparedRequest, $engine, $previous);

        $this->retryAfterMs = self::parseRetryAfter($response);
    }

    /**
     * Milliseconds to wait according to the response headers, or null when absent/unparseable.
     */
    public static function parseRetryAfter(HttpResponse $response): ?int
    {
        $ms = $response->header('retry-after-ms');

        if ($ms !== null && is_numeric($ms)) {
            return max(0, (int) round((float) $ms));
        }

        $after = $response->header('retry-after');

        if ($after === null) {
            return null;
        }

        if (is_numeric($after)) {
            return max(0, (int) round((float) $after * 1000));
        }

        $timestamp = strtotime($after);

        return $timestamp === false ? null : max(0, ($timestamp - time()) * 1000);
    }
}
