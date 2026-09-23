<?php

declare(strict_types=1);

use Swis\DecisionEngine\Exceptions\ApiException;
use Swis\DecisionEngine\Exceptions\AuthenticationException;
use Swis\DecisionEngine\Exceptions\BadRequestException;
use Swis\DecisionEngine\Exceptions\NotFoundException;
use Swis\DecisionEngine\Exceptions\OverloadedException;
use Swis\DecisionEngine\Exceptions\ParallelFailedException;
use Swis\DecisionEngine\Exceptions\PermissionDeniedException;
use Swis\DecisionEngine\Exceptions\RateLimitException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Exceptions\ServerException;
use Swis\DecisionEngine\Exceptions\UnprocessableEntityException;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Transport\HttpResponse;

it('maps every documented status to its exception class', function (int $status, string $class): void {
    $response = HttpResponse::jsonResponse($status, ['message' => 'boom'], ['x-typesafe-request-id' => 'req_9']);
    $e = ApiException::fromResponse($response, null, 'jev');

    expect($e)->toBeInstanceOf($class)
        ->and($e->status)->toBe($status)
        ->and($e->getCode())->toBe($status)
        ->and($e->requestId)->toBe('req_9')
        ->and($e->engine)->toBe('jev')
        ->and($e->body())->toBe(['message' => 'boom'])
        ->and($e->getMessage())->toBe("Jev API request failed with status {$status}: boom");
})->with([
    [400, BadRequestException::class],
    [401, AuthenticationException::class],
    [403, PermissionDeniedException::class],
    [404, NotFoundException::class],
    [422, UnprocessableEntityException::class],
    [429, RateLimitException::class],
    [500, ServerException::class],
    [503, ServerException::class],
    [529, OverloadedException::class],
    [418, ApiException::class],
]);

it('extracts nested error messages and falls back to raw text', function (): void {
    expect(ApiException::fromResponse(HttpResponse::jsonResponse(400, ['error' => ['message' => 'nested']]))->getMessage())->toContain('nested')
        ->and(ApiException::fromResponse(new HttpResponse(502, [], '<html>Bad gateway</html>'))->getMessage())->toContain('<html>Bad gateway</html>')
        ->and(ApiException::fromResponse(new HttpResponse(502, [], ''))->getMessage())->toBe('API request failed with status 502.')
        ->and(ApiException::fromResponse(new HttpResponse(502, [], ''))->body())->toBeNull();
});

it('renders FastAPI validation details', function (): void {
    $response = HttpResponse::jsonResponse(422, ['detail' => [
        ['type' => 'missing', 'loc' => ['body', 'state'], 'msg' => 'Field required', 'input' => []],
        ['type' => 'string_type', 'loc' => ['body', 'questions', 'x', 'instructions'], 'msg' => 'Input should be a valid string'],
    ]]);

    expect(ApiException::fromResponse($response, null, 'jev')->getMessage())
        ->toBe('Jev API request failed with status 422: body.state: Field required; body.questions.x.instructions: Input should be a valid string');
});

it('parses retry-after headers in all three forms', function (): void {
    $ms = new RateLimitException('x', HttpResponse::jsonResponse(429, [], ['retry-after-ms' => '150']));
    $seconds = new RateLimitException('x', HttpResponse::jsonResponse(429, [], ['Retry-After' => '2']));
    $date = new RateLimitException('x', HttpResponse::jsonResponse(429, [], ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 3)]));
    $none = new RateLimitException('x', HttpResponse::jsonResponse(429, []));

    expect($ms->retryAfterMs)->toBe(150)
        ->and($seconds->retryAfterMs)->toBe(2000)
        ->and($date->retryAfterMs)->toBeGreaterThanOrEqual(1000)->toBeLessThanOrEqual(3000)
        ->and($none->retryAfterMs)->toBeNull()
        ->and($ms->isRetryable())->toBeTrue()
        ->and(ApiException::fromResponse(HttpResponse::jsonResponse(400, []))->isRetryable())->toBeFalse();
});

it('carries a field path on response validation failures', function (): void {
    $e = ResponseValidationException::at('answers.tone.confidence', 'must be a number', HttpResponse::jsonResponse(200, []));

    expect($e->fieldPath)->toBe('answers.tone.confidence')
        ->and($e->status)->toBe(200)
        ->and($e->getMessage())->toBe('Invalid response body at [answers.tone.confidence]: must be a number');
});

it('redacts credentials in prepared requests', function (): void {
    $request = PreparedRequest::jsonRequest('POST', 'https://api.typesafe.ai/v1/systemone', ['Authorization' => 'Bearer secret', 'X-Trace' => 't'], ['state' => 'x']);

    expect($request->redactedHeaders()['Authorization'])->toBe('[redacted]')
        ->and($request->header('authorization'))->toBe('Bearer secret')
        ->and($request->header('content-type'))->toBe('application/json')
        ->and($request->toCurlCommand())->not->toContain('secret')
        ->and($request->toCurlCommand(redact: false))->toContain('secret')
        ->and($request->toCurlCommand())->toContain("--data-raw '{\"state\":\"x\"}'")
        ->and(print_r($request, true))->not->toContain('secret')
        ->and($request->json())->toBe(['state' => 'x'])
        ->and($request->withHeaders(['X-Trace' => 'u'])->header('X-Trace'))->toBe('u')
        ->and($request->withMeta(['engine' => 'jev'])->meta)->toBe(['engine' => 'jev']);
});

it('normalizes response headers case-insensitively', function (): void {
    $response = new HttpResponse(200, ['Content-Type' => 'application/json', 'X-Multi' => ['a', 'b']], '{"a":1}');

    expect($response->header('content-type'))->toBe('application/json')
        ->and($response->headerValues('x-multi'))->toBe(['a', 'b'])
        ->and($response->header('missing'))->toBeNull()
        ->and($response->isSuccess())->toBeTrue()
        ->and($response->json())->toBe(['a' => 1])
        ->and((new HttpResponse(200, [], 'nope'))->tryJson())->toBeNull()
        ->and($response->withLatency(12.5)->latencyMs)->toBe(12.5);
});

it('summarizes parallel failures', function (): void {
    $e = new ParallelFailedException(['a' => 'ok'], ['b' => new RuntimeException('b failed'), 'c' => new RuntimeException('c failed')]);

    expect($e->getMessage())->toBe('2 of 3 parallel decisions failed. First failure [b]: b failed')
        ->and($e->outcomes())->toBe(['a' => 'ok'])
        ->and(array_keys($e->failures()))->toBe(['b', 'c'])
        ->and($e->getPrevious()?->getMessage())->toBe('b failed');
});
