<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines\Jev;

use Swis\DecisionEngine\Exceptions\ApiException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Version;

/**
 * `GET {baseUrl}/v1/models` — pure prepare/interpret pair like an Engine. The body may be a bare
 * list of model cards or wrapped in `models` / `data`; both are accepted.
 *
 * ```php
 * $endpoint = new ModelsEndpoint($apiKey, 'https://api.typesafe.ai');
 * $cards = $endpoint->interpret($transport->send($endpoint->prepare(), $options)); // list<ModelCard>
 * ```
 */
final class ModelsEndpoint
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.typesafe.ai',
    ) {}

    public function prepare(): PreparedRequest
    {
        return new PreparedRequest(
            'GET',
            rtrim($this->baseUrl, '/') . '/v1/models',
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'User-Agent' => Version::userAgent(),
            ],
            '',
            ['engine' => 'jev', 'endpoint' => 'models'],
        );
    }

    /**
     * @return list<ModelCard>
     */
    public function interpret(HttpResponse $response): array
    {
        if (! $response->isSuccess()) {
            throw ApiException::fromResponse($response, $this->prepare(), 'jev');
        }

        try {
            $body = $response->json();
        } catch (\JsonException $e) {
            throw ResponseValidationException::at('', 'body is not valid JSON', $response, null, 'jev', $e);
        }

        $list = array_is_list($body) ? $body : ($body['models'] ?? $body['data'] ?? null);

        if (! is_array($list)) {
            throw ResponseValidationException::at('models', 'expected a list of model cards', $response, null, 'jev');
        }

        $cards = [];

        foreach (array_values($list) as $i => $card) {
            if (! is_array($card)) {
                throw ResponseValidationException::at("models.{$i}", 'expected an object', $response, null, 'jev');
            }

            try {
                $cards[] = ModelCard::fromArray(Arr::stringKeys($card));
            } catch (\InvalidArgumentException $e) {
                throw ResponseValidationException::at("models.{$i}", $e->getMessage(), $response, null, 'jev', $e);
            }
        }

        return $cards;
    }
}
