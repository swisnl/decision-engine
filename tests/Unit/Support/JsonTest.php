<?php

declare(strict_types=1);

use Swis\DecisionEngine\Support\Json;
use Swis\DecisionEngine\Transport\RetryPolicy;

it('encodes deterministically with sorted keys in canonical mode', function (): void {
    expect(Json::canonical(['b' => [1, 2], 'a' => ['z' => 1, 'y' => 2]]))->toBe('{"a":{"y":2,"z":1},"b":[1,2]}')
        ->and(Json::encode(['b' => 1, 'a' => 2]))->toBe('{"b":1,"a":2}')
        ->and(Json::encode(1.0))->toBe('1.0');
});

it('decodes arrays and rejects scalars in decodeArray', function (): void {
    expect(Json::decodeArray('{"a":1}'))->toBe(['a' => 1]);
    expect(fn() => Json::decodeArray('"x"'))->toThrow(JsonException::class);
});

it('normalizes enums and traversables', function (): void {
    enum TestSuit: string
    {
        case Hearts = 'hearts';
    }

    expect(Json::normalize(TestSuit::Hearts))->toBe('hearts')
        ->and(Json::normalize(new ArrayIterator(['a' => 1])))->toBe(['a' => 1]);
});

it('parses retry policies from snake_case, camelCase and status shorthands', function (): void {
    $policy = RetryPolicy::fromArray(['maxRetries' => 3, 'statuses' => [408, '429', '5xx'], 'backoff_initial_ms' => 100]);

    expect($policy->maxRetries)->toBe(3)
        ->and($policy->backoffInitialMs)->toBe(100)
        ->and($policy->retriesStatus(503))->toBeTrue()
        ->and($policy->retriesStatus(429))->toBeTrue()
        ->and($policy->retriesStatus(404))->toBeFalse()
        ->and(RetryPolicy::fromArray($policy->toArray())->toArray())->toBe($policy->toArray())
        ->and(RetryPolicy::none()->maxRetries)->toBe(0)
        ->and(RetryPolicy::default()->retriesStatus(529))->toBeTrue();
});

it('rejects invalid retry configuration', function (): void {
    expect(fn() => new RetryPolicy(maxRetries: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new RetryPolicy(jitter: 2.0))->toThrow(InvalidArgumentException::class)
        ->and(fn() => RetryPolicy::fromArray(['statuses' => ['weird']]))->toThrow(InvalidArgumentException::class);
});
