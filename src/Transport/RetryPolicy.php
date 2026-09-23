<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Transport;

use Swis\DecisionEngine\Support\Arr;

/**
 * Retry configuration, defaulting to the official TypeSafe SDK behaviour: 2 retries on
 * 408 / 429 / 5xx, connection errors and timeouts; exponential backoff from 500 ms doubling
 * to 5 000 ms with 25 % jitter; `Retry-After` / `retry-after-ms` honoured up to 60 s.
 *
 * `invalidResponses` re-asks, without backoff, when a 2xx response fails validation: a missing
 * answer, a malformed body, or (mostly with LLM engines) model output that does not fit the schema.
 *
 * ```php
 * RetryPolicy::default();
 * RetryPolicy::none();
 * RetryPolicy::default()->with(maxRetries: 5, statuses: [429, 503]);
 * RetryPolicy::fromArray(['max_retries' => 0]);     // snake_case (config) …
 * RetryPolicy::fromArray(['maxRetries' => 0]);      // … and camelCase both accepted
 * ```
 */
final class RetryPolicy
{
    /**
     * @param  list<int>  $statuses
     */
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly array $statuses = [408, 429, 500, 501, 502, 503, 504, 505, 506, 507, 508, 509, 510, 511, 529],
        public readonly int $backoffInitialMs = 500,
        public readonly int $backoffMaxMs = 5000,
        public readonly float $jitter = 0.25,
        public readonly int $maxRetryAfterMs = 60000,
        public readonly bool $connectionErrors = true,
        public readonly bool $timeouts = true,
        public readonly bool $respectRetryAfter = true,
        public readonly int $invalidResponses = 1,
    ) {
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be >= 0.');
        }

        if ($invalidResponses < 0) {
            throw new \InvalidArgumentException('invalidResponses must be >= 0.');
        }

        if ($jitter < 0.0 || $jitter > 1.0) {
            throw new \InvalidArgumentException('jitter must be between 0 and 1.');
        }
    }

    public static function default(): self
    {
        return new self();
    }

    public static function none(): self
    {
        return new self(maxRetries: 0, invalidResponses: 0);
    }

    /**
     * Accepts snake_case or camelCase keys. `statuses` may contain ints or the shorthand strings
     * `'4xx'` / `'5xx'`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $data = self::snakeKeys($data);
        $defaults = new self();

        return new self(
            maxRetries: Arr::int($data, 'max_retries') ?? $defaults->maxRetries,
            statuses: isset($data['statuses']) ? self::expandStatuses(Arr::array($data, 'statuses') ?? []) : $defaults->statuses,
            backoffInitialMs: Arr::int($data, 'backoff_initial_ms') ?? $defaults->backoffInitialMs,
            backoffMaxMs: Arr::int($data, 'backoff_max_ms') ?? $defaults->backoffMaxMs,
            jitter: Arr::float($data, 'jitter') ?? $defaults->jitter,
            maxRetryAfterMs: Arr::int($data, 'max_retry_after_ms') ?? $defaults->maxRetryAfterMs,
            connectionErrors: Arr::bool($data, 'connection_errors') ?? $defaults->connectionErrors,
            timeouts: Arr::bool($data, 'timeouts') ?? $defaults->timeouts,
            respectRetryAfter: Arr::bool($data, 'respect_retry_after') ?? $defaults->respectRetryAfter,
            invalidResponses: Arr::int($data, 'invalid_responses') ?? $defaults->invalidResponses,
        );
    }

    /**
     * @param  list<int>|null  $statuses
     */
    public function with(
        ?int $maxRetries = null,
        ?array $statuses = null,
        ?int $backoffInitialMs = null,
        ?int $backoffMaxMs = null,
        ?float $jitter = null,
        ?int $maxRetryAfterMs = null,
        ?bool $connectionErrors = null,
        ?bool $timeouts = null,
        ?bool $respectRetryAfter = null,
        ?int $invalidResponses = null,
    ): self {
        return new self(
            $maxRetries ?? $this->maxRetries,
            $statuses ?? $this->statuses,
            $backoffInitialMs ?? $this->backoffInitialMs,
            $backoffMaxMs ?? $this->backoffMaxMs,
            $jitter ?? $this->jitter,
            $maxRetryAfterMs ?? $this->maxRetryAfterMs,
            $connectionErrors ?? $this->connectionErrors,
            $timeouts ?? $this->timeouts,
            $respectRetryAfter ?? $this->respectRetryAfter,
            $invalidResponses ?? $this->invalidResponses,
        );
    }

    public function retriesStatus(int $status): bool
    {
        return in_array($status, $this->statuses, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'max_retries' => $this->maxRetries,
            'statuses' => $this->statuses,
            'backoff_initial_ms' => $this->backoffInitialMs,
            'backoff_max_ms' => $this->backoffMaxMs,
            'jitter' => $this->jitter,
            'max_retry_after_ms' => $this->maxRetryAfterMs,
            'connection_errors' => $this->connectionErrors,
            'timeouts' => $this->timeouts,
            'respect_retry_after' => $this->respectRetryAfter,
            'invalid_responses' => $this->invalidResponses,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $statuses
     * @return list<int>
     */
    private static function expandStatuses(array $statuses): array
    {
        $expanded = [];

        foreach ($statuses as $status) {
            if (is_int($status)) {
                $expanded[] = $status;
            } elseif (is_string($status) && preg_match('/^([1-5])xx$/i', $status, $m) === 1) {
                $expanded = [...$expanded, ...range((int) $m[1] * 100, (int) $m[1] * 100 + 99)];
            } elseif (is_string($status) && ctype_digit($status)) {
                $expanded[] = (int) $status;
            } else {
                throw new \InvalidArgumentException('Retry statuses must be integers or the shorthand "4xx"/"5xx", got ' . get_debug_type($status) . '.');
            }
        }

        $expanded = array_values(array_unique($expanded));
        sort($expanded);

        return $expanded;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function snakeKeys(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            $result[$snake] = $value;
        }

        return $result;
    }
}
