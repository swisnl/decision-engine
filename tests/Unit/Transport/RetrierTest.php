<?php

declare(strict_types=1);

use Swis\DecisionEngine\Exceptions\ConnectionException;
use Swis\DecisionEngine\Exceptions\TimeoutException;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Transport\Retrier;
use Swis\DecisionEngine\Transport\RetryPolicy;

function noJitter(): Retrier
{
    return new Retrier(fn(): float => 0.0);
}

it('stops after maxRetries', function (): void {
    $r = noJitter();
    $p = RetryPolicy::default();

    expect($r->delayFor(0, HttpResponse::jsonResponse(503, []), $p))->toBe(0.5)
        ->and($r->delayFor(1, HttpResponse::jsonResponse(503, []), $p))->toBe(1.0)
        ->and($r->delayFor(2, HttpResponse::jsonResponse(503, []), $p))->toBeNull()
        ->and($r->delayFor(0, HttpResponse::jsonResponse(503, []), RetryPolicy::none()))->toBeNull();
});

it('only retries the configured statuses', function (): void {
    $r = noJitter();

    expect($r->delayFor(0, HttpResponse::jsonResponse(400, []), RetryPolicy::default()))->toBeNull()
        ->and($r->delayFor(0, HttpResponse::jsonResponse(200, []), RetryPolicy::default()))->toBeNull()
        ->and($r->delayFor(0, HttpResponse::jsonResponse(408, []), RetryPolicy::default()))->toBe(0.5)
        ->and($r->delayFor(0, HttpResponse::jsonResponse(429, []), RetryPolicy::default()))->toBe(0.5)
        ->and($r->delayFor(0, HttpResponse::jsonResponse(529, []), RetryPolicy::default()))->toBe(0.5)
        ->and($r->delayFor(0, HttpResponse::jsonResponse(503, []), RetryPolicy::default()->with(statuses: [429])))->toBeNull();
});

it('honours retry-after headers up to the cap', function (): void {
    $r = noJitter();
    $p = RetryPolicy::default();

    expect($r->delayFor(0, HttpResponse::jsonResponse(429, [], ['retry-after-ms' => '100']), $p))->toBe(0.1)
        ->and($r->delayFor(0, HttpResponse::jsonResponse(429, [], ['Retry-After' => '3']), $p))->toBe(3.0)
        ->and($r->delayFor(0, HttpResponse::jsonResponse(429, [], ['Retry-After' => '120']), $p))->toBe(60.0)
        ->and($r->delayFor(0, HttpResponse::jsonResponse(429, [], ['Retry-After' => '120']), $p->with(respectRetryAfter: false)))->toBe(0.5);
});

it('backs off exponentially with jitter and a cap', function (): void {
    $p = RetryPolicy::default()->with(maxRetries: 10);

    expect(noJitter()->backoff(0, $p))->toBe(0.5)
        ->and(noJitter()->backoff(3, $p))->toBe(4.0)
        ->and(noJitter()->backoff(4, $p))->toBe(5.0)
        ->and(noJitter()->backoff(9, $p))->toBe(5.0)
        ->and((new Retrier(fn(): float => 1.0))->backoff(0, $p))->toBe(0.375) // 500ms · (1 − 0.25)
        ->and((new Retrier(fn(): float => 0.5))->backoff(0, $p))->toBe(0.4375)
        ->and(noJitter()->backoff(0, $p->with(backoffInitialMs: 0)))->toBe(0.0);

    $sampled = new Retrier();
    $delay = $sampled->backoff(1, $p);
    expect($delay)->toBeGreaterThanOrEqual(0.75)->toBeLessThanOrEqual(1.0);
});

it('retries connection errors and timeouts according to the policy', function (): void {
    $r = noJitter();
    $p = RetryPolicy::default();

    expect($r->delayFor(0, new ConnectionException('x'), $p))->toBe(0.5)
        ->and($r->delayFor(0, new TimeoutException('x', 1.0), $p))->toBe(0.5)
        ->and($r->delayFor(0, new ConnectionException('x'), $p->with(connectionErrors: false)))->toBeNull()
        ->and($r->delayFor(0, new TimeoutException('x', 1.0), $p->with(timeouts: false)))->toBeNull()
        ->and($r->delayFor(0, new RuntimeException('x'), $p))->toBeNull();
});
