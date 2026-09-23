<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Transport;

use Swis\DecisionEngine\Exceptions\ConnectionException;
use Swis\DecisionEngine\Exceptions\RateLimitException;
use Swis\DecisionEngine\Exceptions\TimeoutException;

/**
 * Pure retry decision: given what happened on attempt N, how long to wait before attempt N+1,
 * or null to stop. Shared by the blocking client and the Fiber scheduler; only *sleeping* differs.
 *
 * ```php
 * $retrier = new Retrier();
 * $retrier->delayFor(0, HttpResponse::jsonResponse(429, [], ['retry-after-ms' => '100']), RetryPolicy::default()); // 0.1
 * $retrier->delayFor(2, $response503, RetryPolicy::default());                                                   // null (max 2 retries)
 * ```
 */
final class Retrier
{
    /**
     * @var \Closure(): float  returns a float in [0, 1)
     */
    private readonly \Closure $random;

    /**
     * @param  (\Closure(): float)|null  $random  injectable randomness for deterministic tests
     */
    public function __construct(?\Closure $random = null)
    {
        $this->random = $random ?? static fn(): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * @param  int  $attempt  0-based: how many retries have already happened
     * @return float|null  seconds to wait before the next attempt, or null to give up
     */
    public function delayFor(int $attempt, HttpResponse|\Throwable $result, RetryPolicy $policy): ?float
    {
        if ($attempt >= $policy->maxRetries) {
            return null;
        }

        if ($result instanceof \Throwable) {
            return $this->shouldRetryThrowable($result, $policy) ? $this->backoff($attempt, $policy) : null;
        }

        if (! $policy->retriesStatus($result->status)) {
            return null;
        }

        if ($policy->respectRetryAfter) {
            $retryAfterMs = RateLimitException::parseRetryAfter($result);

            if ($retryAfterMs !== null) {
                return min($retryAfterMs, $policy->maxRetryAfterMs) / 1000;
            }
        }

        return $this->backoff($attempt, $policy);
    }

    public function shouldRetryThrowable(\Throwable $e, RetryPolicy $policy): bool
    {
        if ($e instanceof TimeoutException) {
            return $policy->timeouts;
        }

        if ($e instanceof ConnectionException) {
            return $policy->connectionErrors;
        }

        return false;
    }

    /**
     * Exponential backoff with jitter: `min(initial · 2^attempt, max) · (1 − jitter · U[0,1))`.
     */
    public function backoff(int $attempt, RetryPolicy $policy): float
    {
        if ($policy->backoffInitialMs <= 0 || $policy->backoffMaxMs <= 0) {
            return 0.0;
        }

        $ms = min($policy->backoffInitialMs * (2 ** $attempt), $policy->backoffMaxMs);
        $ms *= 1.0 - $policy->jitter * ($this->random)();

        return max(0.0, $ms / 1000);
    }
}
