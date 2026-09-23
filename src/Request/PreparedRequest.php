<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Request;

use Swis\DecisionEngine\Support\Json;

/**
 * A fully rendered HTTP request, ready for a Transport. Produced by `Engine::prepare()`; nothing
 * has been sent yet, so it doubles as a dry-run / debugging artefact.
 *
 * ```php
 * $prepared = Decision::for($state)->noul('urgent', 'Urgent?')->toRequest();
 * $prepared->url;             // 'https://api.typesafe.ai/v1/systemone'
 * $prepared->json();          // decoded body
 * $prepared->toCurlCommand(); // copy-pasteable curl, API key redacted unless $redact = false
 * ```
 */
final class PreparedRequest
{
    private const SENSITIVE_HEADERS = ['authorization', 'x-api-key', 'api-key', 'proxy-authorization'];

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $meta  engine name, model, question ids… for logging
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body,
        public readonly array $meta = [],
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $meta
     */
    public static function jsonRequest(string $method, string $url, array $headers, array $body, array $meta = []): self
    {
        return new self($method, $url, ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers, Json::encode($body), $meta);
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

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->method, $this->url, array_replace($this->headers, $headers), $this->body, $this->meta);
    }

    public function withBody(string $body): self
    {
        return new self($this->method, $this->url, $this->headers, $body, $this->meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->method, $this->url, $this->headers, $this->body, array_replace($this->meta, $meta));
    }

    /**
     * Headers with credentials replaced by `[redacted]`.
     *
     * @return array<string, string>
     */
    public function redactedHeaders(): array
    {
        $redacted = [];

        foreach ($this->headers as $name => $value) {
            $redacted[$name] = in_array(strtolower($name), self::SENSITIVE_HEADERS, true) ? '[redacted]' : $value;
        }

        return $redacted;
    }

    public function toCurlCommand(bool $redact = true): string
    {
        $parts = ['curl', '-X', $this->method, escapeshellarg($this->url)];

        foreach ($redact ? $this->redactedHeaders() : $this->headers as $name => $value) {
            $parts[] = '-H ' . escapeshellarg("{$name}: {$value}");
        }

        if ($this->body !== '') {
            $parts[] = '--data-raw ' . escapeshellarg($this->body);
        }

        return implode(" \\\n  ", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $this->redactedHeaders(),
            'body' => $this->body,
            'meta' => $this->meta,
        ];
    }
}
