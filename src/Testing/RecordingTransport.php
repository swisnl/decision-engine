<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Testing;

use Swis\DecisionEngine\Contracts\Transport;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Support\Json;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * Records real responses as JSON fixtures and replays them on later runs, keyed by a hash of
 * method + URL + body. Request headers (and so API keys) are never written.
 *
 * ```php
 * $client = new Client($engines, new RecordingTransport(new CurlTransport(), __DIR__ . '/fixtures/recorded'));
 * ```
 */
final class RecordingTransport implements Transport
{
    public function __construct(
        private readonly Transport $inner,
        private readonly string $directory,
        private readonly bool $refresh = false,
    ) {}

    public function send(PreparedRequest $request, RequestOptions $options): HttpResponse
    {
        $path = $this->path($request);

        if (! $this->refresh && is_file($path)) {
            /** @var array{response: array{status: int, headers: array<string, list<string>>, body: string}} $recorded */
            $recorded = Json::decodeArray((string) file_get_contents($path));

            return new HttpResponse($recorded['response']['status'], $recorded['response']['headers'], $recorded['response']['body'], 0.0);
        }

        $response = $this->inner->send($request, $options);

        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0o777, true);
        }

        file_put_contents($path, Json::encode([
            'request' => ['method' => $request->method, 'url' => $request->url, 'body' => $request->body],
            'response' => ['status' => $response->status, 'headers' => $response->headers, 'body' => $response->body],
        ], pretty: true) . "\n");

        return $response;
    }

    public function path(PreparedRequest $request): string
    {
        return rtrim($this->directory, '/') . '/' . hash('sha256', $request->method . ' ' . $request->url . "\n" . $request->body) . '.json';
    }

    public function has(PreparedRequest $request): bool
    {
        return is_file($this->path($request));
    }
}
