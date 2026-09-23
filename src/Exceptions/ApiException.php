<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * The API answered with an error status (or a structurally invalid 2xx, see
 * ResponseValidationException). Carries everything needed to debug without leaking the API key.
 *
 * ```php
 * try { ... } catch (ApiException $e) {
 *     $e->status;      // 429
 *     $e->requestId;   // 'req_…'
 *     $e->body();      // decoded error body, if JSON
 *     $e->engine;      // 'jev'
 * }
 * ```
 */
class ApiException extends \RuntimeException implements DecisionEngineException
{
    public readonly int $status;

    /**
     * @var array<string, list<string>>
     */
    public readonly array $headers;

    public readonly ?string $requestId;

    public function __construct(
        string $message,
        public readonly HttpResponse $response,
        public readonly ?PreparedRequest $preparedRequest = null,
        public readonly ?string $engine = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $response->status, $previous);

        $this->status = $response->status;
        $this->headers = $response->headers;
        $this->requestId = $response->header('x-typesafe-request-id') ?? $response->header('x-request-id') ?? $response->header('request-id');
    }

    /**
     * Pick the subclass matching an HTTP status.
     */
    public static function fromResponse(HttpResponse $response, ?PreparedRequest $request = null, ?string $engine = null, ?string $message = null): self
    {
        $message ??= self::describe($response, $engine);

        return match (true) {
            $response->status === 400 => new BadRequestException($message, $response, $request, $engine),
            $response->status === 401 => new AuthenticationException($message, $response, $request, $engine),
            $response->status === 403 => new PermissionDeniedException($message, $response, $request, $engine),
            $response->status === 404 => new NotFoundException($message, $response, $request, $engine),
            $response->status === 422 => new UnprocessableEntityException($message, $response, $request, $engine),
            $response->status === 429 => new RateLimitException($message, $response, $request, $engine),
            $response->status === 529 => new OverloadedException($message, $response, $request, $engine),
            $response->status >= 500 => new ServerException($message, $response, $request, $engine),
            default => new self($message, $response, $request, $engine),
        };
    }

    /**
     * Decoded error body, or null when the body is not JSON.
     *
     * @return array<string, mixed>|null
     */
    public function body(): ?array
    {
        return $this->response->tryJson();
    }

    /**
     * Whether the retry policy would consider this status transient.
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, [408, 429, 529], true) || ($this->status >= 500 && $this->status < 600);
    }

    private static function describe(HttpResponse $response, ?string $engine): string
    {
        $prefix = $engine === null ? 'API' : ucfirst($engine) . ' API';
        $detail = self::extractMessage($response);

        return "{$prefix} request failed with status {$response->status}" . ($detail === null ? '.' : ": {$detail}");
    }

    private static function extractMessage(HttpResponse $response): ?string
    {
        $body = $response->tryJson();

        if ($body === null) {
            $text = trim($response->body);

            return $text === '' ? null : mb_substr($text, 0, 200);
        }

        foreach (['message', 'detail', 'error'] as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value)) {
                return $value;
            }

            if (is_array($value)) {
                $nested = $value['message'] ?? self::validationErrors($value);

                if (is_string($nested)) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * FastAPI-style 422 details: `[{"loc": ["body", "state"], "msg": "Field required"}, …]` →
     * `body.state: Field required; …`.
     *
     * @param  array<array-key, mixed>  $detail
     */
    private static function validationErrors(array $detail): ?string
    {
        $messages = [];

        foreach ($detail as $error) {
            if (! is_array($error) || ! is_string($error['msg'] ?? null)) {
                continue;
            }

            $loc = is_array($error['loc'] ?? null) ? implode('.', array_filter($error['loc'], is_scalar(...))) : '';
            $messages[] = ($loc === '' ? '' : "{$loc}: ") . $error['msg'];
        }

        return $messages === [] ? null : implode('; ', $messages);
    }
}
