<?php

declare(strict_types=1);

use Swis\DecisionEngine\Engines\Jev\JevEngine;
use Swis\DecisionEngine\Engines\Jev\ModelsEndpoint;
use Swis\DecisionEngine\Exceptions\ApiException;
use Swis\DecisionEngine\Exceptions\AuthenticationException;
use Swis\DecisionEngine\Exceptions\BadRequestException;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Exceptions\NotFoundException;
use Swis\DecisionEngine\Exceptions\OverloadedException;
use Swis\DecisionEngine\Exceptions\PermissionDeniedException;
use Swis\DecisionEngine\Exceptions\RateLimitException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Exceptions\ServerException;
use Swis\DecisionEngine\Exceptions\UnprocessableEntityException;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Version;

function jev(): JevEngine
{
    return new JevEngine('sk-test', 'https://api.typesafe.ai/', 'jev-latest');
}

it('prepares exactly {state, model, questions} with the right headers', function (): void {
    $request = DecisionRequest::fromArray($this->fixture('quickstart-request'));
    $prepared = jev()->prepare($request);

    expect($prepared->method)->toBe('POST')
        ->and($prepared->url)->toBe('https://api.typesafe.ai/v1/systemone')
        ->and($prepared->header('Authorization'))->toBe('Bearer sk-test')
        ->and($prepared->header('Content-Type'))->toBe('application/json')
        ->and($prepared->header('Accept'))->toBe('application/json')
        ->and($prepared->header('User-Agent'))->toBe(Version::userAgent())
        ->and(array_keys($prepared->json()))->toBe(['state', 'model', 'questions'])
        ->and($prepared->json())->toEqual($this->fixture('quickstart-request'))
        ->and($prepared->meta)->toBe(['engine' => 'jev', 'model' => 'jev-latest', 'questions' => ['department', 'frustration', 'is_urgent']]);
});

it('fills in the default model and applies merge + merge_body last', function (): void {
    $request = DecisionRequest::fromArray(['state' => 's', 'questions' => ['n' => ['type' => 'noul', 'instructions' => 'q']]])
        ->withMerge(['experimental' => true, 'model' => 'jev-preview'])
        ->withEngineOptions(['merge_body' => ['store' => false, 'experimental' => false]]);

    $body = (new JevEngine('k', model: 'jev-1.13.0'))->prepare($request)->json();

    expect($body['model'])->toBe('jev-preview')
        ->and($body['experimental'])->toBeFalse()
        ->and($body['store'])->toBeFalse()
        ->and(array_keys($body))->toBe(['state', 'model', 'questions', 'experimental', 'store']);

    expect((new JevEngine('k', model: 'jev-1.13.0'))->prepare($request->withMerge(['model' => null]))->json()['model'])->toBe('jev-1.13.0'); // null → engine default
    expect((new JevEngine('k', model: 'jev-1.13.0'))->prepare(DecisionRequest::fromArray(['state' => 's', 'questions' => ['n' => ['type' => 'noul']]]))->json()['model'])->toBe('jev-1.13.0');
});

it('interprets the documented response into an Outcome', function (): void {
    $request = DecisionRequest::fromArray($this->fixture('quickstart-request'));
    $body = $this->fixture('quickstart-response');
    $response = HttpResponse::jsonResponse(200, $body, ['x-typesafe-request-id' => 'req_abc'])->withLatency(101.5);

    $outcome = jev()->interpret($request, $response);

    expect($outcome->model)->toBe('jev-1.13.0')
        ->and($outcome->engine)->toBe('jev')
        ->and($outcome->department->choice)->toBe('technical')
        ->and($outcome->department->confidence)->toBe(0.78)
        ->and($outcome->frustration->score)->toBe(1.0)
        ->and($outcome->is_urgent->noul)->toBe(1.0)
        ->and($outcome->usage->inputTokens)->toBe(392)
        ->and($outcome->meta->calibrated)->toBeTrue()
        ->and($outcome->meta->requestId)->toBe('req_abc')
        ->and($outcome->meta->latencyMs)->toBe(101.5)
        ->and($outcome->raw())->toBe($body)
        ->and($outcome->response())->toBe($response);
});

it('maps every error status to its exception, carrying body, headers and request id', function (int $status, string $class): void {
    $request = DecisionRequest::fromArray($this->fixture('quickstart-request'));
    $response = HttpResponse::jsonResponse($status, ['message' => 'nope'], ['x-typesafe-request-id' => 'req_err', 'retry-after-ms' => '250']);

    try {
        jev()->interpret($request, $response);
        $this->fail('Expected an exception');
    } catch (ApiException $e) {
        expect($e)->toBeInstanceOf($class)
            ->and($e->status)->toBe($status)
            ->and($e->body())->toBe(['message' => 'nope'])
            ->and($e->requestId)->toBe('req_err')
            ->and($e->engine)->toBe('jev')
            ->and($e->headers['retry-after-ms'])->toBe(['250']);

        if ($e instanceof RateLimitException) {
            expect($e->retryAfterMs)->toBe(250);
        }
    }
})->with([
    [400, BadRequestException::class],
    [401, AuthenticationException::class],
    [403, PermissionDeniedException::class],
    [404, NotFoundException::class],
    [422, UnprocessableEntityException::class],
    [429, RateLimitException::class],
    [500, ServerException::class],
    [529, OverloadedException::class],
]);

it('reports a dotted field path for malformed 2xx bodies', function (array $body, string $fieldPath): void {
    $request = DecisionRequest::fromArray($this->fixture('quickstart-request'));

    try {
        jev()->interpret($request, HttpResponse::jsonResponse(200, $body));
        $this->fail('Expected a ResponseValidationException');
    } catch (ResponseValidationException $e) {
        expect($e->fieldPath)->toBe($fieldPath)->and($e->status)->toBe(200);
    }
})->with([
    'missing model' => [['answers' => []], 'model'],
    'answers not object' => [['model' => 'm', 'answers' => 'x'], 'answers'],
    'usage not object' => [['model' => 'm', 'answers' => [], 'usage' => 1], 'usage'],
    'usage tokens not int' => [['model' => 'm', 'answers' => [], 'usage' => ['input_tokens' => '1']], 'usage.input_tokens'],
    'choice missing probabilities' => [['model' => 'm', 'answers' => ['tone' => ['type' => 'choice', 'choice' => 'a', 'confidence' => 1]]], 'answers.tone.probabilities'],
    'choice confidence wrong type' => [['model' => 'm', 'answers' => ['tone' => ['type' => 'choice', 'choice' => 'a', 'probabilities' => ['a' => 1], 'confidence' => 'high']]], 'answers.tone.confidence'],
    'score missing score' => [['model' => 'm', 'answers' => ['s' => ['type' => 'score', 'confidence' => 1, 'probabilities' => ['0' => 1]]]], 'answers.s.score'],
    'noul missing noul' => [['model' => 'm', 'answers' => ['n' => ['type' => 'noul']]], 'answers.n.noul'],
    'answer not object' => [['model' => 'm', 'answers' => ['n' => 'x']], 'answers.n'],
]);

it('rejects non-JSON 2xx bodies', function (): void {
    $request = DecisionRequest::fromArray($this->fixture('quickstart-request'));

    expect(fn() => jev()->interpret($request, new HttpResponse(200, [], 'not json')))->toThrow(ResponseValidationException::class, 'not valid JSON');
});

it('keeps unknown answer types as GenericAnswer instead of failing', function (): void {
    $request = DecisionRequest::fromArray($this->fixture('triage-request'));
    $outcome = jev()->interpret($request, HttpResponse::jsonResponse(200, $this->fixture('triage-response')));

    expect($outcome->answer('mystery')->get('values'))->toBe([0.1, 0.2])->and(count($outcome))->toBe(5);
});

it('requires an api key', function (): void {
    expect(fn() => new JevEngine(''))->toThrow(ConfigurationException::class)
        ->and(fn() => JevEngine::fromConfig([]))->toThrow(ConfigurationException::class, 'engines.jev.api_key')
        ->and(JevEngine::fromConfig(['api_key' => 'k', 'model' => 'jev-1.13.0'])->defaultModel())->toBe('jev-1.13.0')
        ->and(JevEngine::fromConfig(['api_key' => 'k'])->name())->toBe('jev')
        ->and(JevEngine::fromConfig(['api_key' => 'k'])->capabilities()->calibrated)->toBeTrue();
});

describe('ModelsEndpoint', function (): void {
    it('prepares a GET and accepts bare or wrapped lists', function (): void {
        $endpoint = new ModelsEndpoint('k', 'https://api.typesafe.ai');
        $prepared = $endpoint->prepare();

        expect($prepared->method)->toBe('GET')
            ->and($prepared->url)->toBe('https://api.typesafe.ai/v1/models')
            ->and($prepared->header('Authorization'))->toBe('Bearer k')
            ->and($prepared->body)->toBe('');

        $card = ['name' => 'jev-1.13.0', 'description' => 'Latest', 'release_date' => '2026-06-01'];

        foreach ([[$card], ['models' => [$card]], ['data' => [$card]]] as $body) {
            $cards = $endpoint->interpret(HttpResponse::jsonResponse(200, $body));
            expect($cards)->toHaveCount(1)
                ->and($cards[0]->name)->toBe('jev-1.13.0')
                ->and($cards[0]->releaseDate)->toBe('2026-06-01')
                ->and($cards[0]->toArray())->toBe($card);
        }

        expect(jev()->models()->prepare()->url)->toBe('https://api.typesafe.ai/v1/models');
    });

    it('maps errors and validates cards', function (): void {
        $endpoint = new ModelsEndpoint('k');

        expect(fn() => $endpoint->interpret(HttpResponse::jsonResponse(401, [])))->toThrow(AuthenticationException::class)
            ->and(fn() => $endpoint->interpret(HttpResponse::jsonResponse(200, ['nope' => 1])))->toThrow(ResponseValidationException::class)
            ->and(fn() => $endpoint->interpret(HttpResponse::jsonResponse(200, [['description' => 'no name']])))->toThrow(ResponseValidationException::class, 'models.0')
            ->and(fn() => $endpoint->interpret(HttpResponse::jsonResponse(200, ['x'])))->toThrow(ResponseValidationException::class, 'models.0')
            ->and(fn() => $endpoint->interpret(new HttpResponse(200, [], '{')))->toThrow(ResponseValidationException::class);
    });
});
