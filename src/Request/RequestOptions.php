<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Request;

use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Transport\RetryPolicy;

/**
 * Transport-level options for a single request: timeouts, retry policy and extra headers.
 * Null means "use the client's configured default".
 *
 * ```php
 * $options = RequestOptions::fromArray(['timeout' => 3.0, 'retry' => ['maxRetries' => 0], 'headers' => ['X-Trace-Id' => 'abc']]);
 * $options->merge(new RequestOptions(timeout: 5.0))->timeout; // 5.0
 * ```
 */
final class RequestOptions
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ?float $timeout = null,
        public readonly ?float $connectTimeout = null,
        public readonly ?RetryPolicy $retry = null,
        public readonly array $headers = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $retry = $data['retry'] ?? null;

        if ($retry instanceof RetryPolicy) {
            $policy = $retry;
        } elseif (is_array($retry)) {
            /** @var array<string, mixed> $retry */
            $policy = RetryPolicy::fromArray($retry);
        } elseif ($retry === null) {
            $policy = null;
        } else {
            throw new \InvalidArgumentException('Option [retry] must be an array or a RetryPolicy.');
        }

        $normalizedHeaders = [];

        foreach (Arr::array($data, 'headers') ?? [] as $name => $value) {
            if (! is_string($name) || (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value))) {
                throw new \InvalidArgumentException('Option [headers] must map header names to scalar values.');
            }

            $normalizedHeaders[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return new self(
            timeout: Arr::float($data, 'timeout') ?? Arr::float($data, 'timeout_seconds'),
            connectTimeout: Arr::float($data, 'connect_timeout') ?? Arr::float($data, 'connectTimeout'),
            retry: $policy,
            headers: $normalizedHeaders,
        );
    }

    /**
     * Combine with another options object; the other's non-null values win, headers are merged.
     */
    public function merge(?self $other): self
    {
        if ($other === null) {
            return $this;
        }

        return new self(
            $other->timeout ?? $this->timeout,
            $other->connectTimeout ?? $this->connectTimeout,
            $other->retry ?? $this->retry,
            array_replace($this->headers, $other->headers),
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->timeout, $this->connectTimeout, $this->retry, array_replace($this->headers, $headers));
    }

    public function withTimeout(?float $timeout): self
    {
        return new self($timeout, $this->connectTimeout, $this->retry, $this->headers);
    }

    public function withRetry(?RetryPolicy $retry): self
    {
        return new self($this->timeout, $this->connectTimeout, $retry, $this->headers);
    }

    public function isEmpty(): bool
    {
        return $this->timeout === null && $this->connectTimeout === null && $this->retry === null && $this->headers === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [];

        if ($this->timeout !== null) {
            $array['timeout'] = $this->timeout;
        }

        if ($this->connectTimeout !== null) {
            $array['connect_timeout'] = $this->connectTimeout;
        }

        if ($this->retry !== null) {
            $array['retry'] = $this->retry->toArray();
        }

        if ($this->headers !== []) {
            $array['headers'] = $this->headers;
        }

        return $array;
    }
}
