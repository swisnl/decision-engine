<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Transport;

use Swis\DecisionEngine\Support\Json;

/**
 * A raw HTTP response. Header names are stored lower-cased; multi-valued headers keep every value.
 *
 * ```php
 * $response->status;                       // 200
 * $response->header('x-typesafe-request-id'); // 'req_…'
 * $response->json();                       // decoded body (throws \JsonException when not JSON)
 * ```
 */
final class HttpResponse
{
    /**
     * @var array<string, list<string>>
     */
    public readonly array $headers;

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
        public readonly ?float $latencyMs = null,
    ) {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = is_array($value) ? array_values($value) : [$value];
        }

        $this->headers = $normalized;
    }

    /**
     * Convenience constructor for tests and fakes.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public static function jsonResponse(int $status, mixed $body, array $headers = []): self
    {
        return new self($status, ['content-type' => 'application/json'] + $headers, Json::encode($body));
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : $values[0];
    }

    /**
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = Json::decodeArray($this->body);

        return $decoded;
    }

    /**
     * Decoded body or null when it is not valid JSON / not an object.
     *
     * @return array<string, mixed>|null
     */
    public function tryJson(): ?array
    {
        try {
            return $this->json();
        } catch (\JsonException) {
            return null;
        }
    }

    public function withLatency(float $latencyMs): self
    {
        return new self($this->status, $this->headers, $this->body, $latencyMs);
    }
}
