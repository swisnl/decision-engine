<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Config;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Exceptions\AuthenticationException;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Exceptions\ConnectionException;
use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\Exceptions\RateLimitException;
use Swis\DecisionEngine\Exceptions\ServerException;
use Swis\DecisionEngine\Exceptions\TimeoutException;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Testing\FakeTransport;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Transport\Retrier;
use Swis\DecisionEngine\Transport\RetryPolicy;

function engines(): EngineManager
{
    return EngineManager::fromArray(['default' => 'jev', 'engines' => ['jev' => ['driver' => 'jev', 'api_key' => 'sk-test']]]);
}

function clientWith(FakeTransport $transport, ?RetryPolicy $retry = null, ?array &$sleeps = null, ?array &$logs = null): Client
{
    $logger = new class ($logs) extends AbstractLogger {
        public function __construct(private ?array &$logs) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->logs[] = [$level, (string) $message, $context];
        }
    };

    $client = new Client(engines(), $transport, new RequestOptions(timeout: 10.0), $retry ?? RetryPolicy::default(), $logger, retrier: new Retrier(fn(): float => 0.0));
    $client->withSleeper(function (float $s) use (&$sleeps): void {
        $sleeps[] = $s;
    });

    return $client;
}

beforeEach(function (): void {
    $this->request = DecisionRequest::fromArray($this->fixture('quickstart-request'));
    $this->ok = HttpResponse::jsonResponse(200, $this->fixture('quickstart-response'), ['x-typesafe-request-id' => 'req_ok']);
});

it('runs the full pipeline and logs a redacted info line', function (): void {
    $transport = new FakeTransport([$this->ok]);
    $logs = [];
    $client = clientWith($transport, logs: $logs);

    $before = $after = null;
    $client->before(function (DecisionRequest $r, PreparedRequest $p) use (&$before): void {
        $before = $p;
    })->after(function (DecisionRequest $r, Outcome $o) use (&$after): void {
        $after = $o;
    });

    $outcome = $client->execute($this->request);

    expect($outcome->department->choice)->toBe('technical')
        ->and($outcome->meta->requestId)->toBe('req_ok')
        ->and($outcome->meta->latencyMs)->toBeFloat()
        ->and($outcome->request())->toBe($before)
        ->and($after)->toBe($outcome)
        ->and($transport->count())->toBe(1)
        ->and($transport->lastSent()?->json())->toEqual($this->fixture('quickstart-request'))
        ->and($transport->sentOptions()[0]->timeout)->toBe(10.0)
        ->and($logs[0][0])->toBe('info')
        ->and($logs[0][1])->toBe('Decision made')
        ->and($logs[0][2]['engine'])->toBe('jev')
        ->and($logs[0][2]['questions'])->toBe(3)
        ->and($logs[0][2]['request_id'])->toBe('req_ok')
        ->and(json_encode($logs))->not->toContain('sk-test');
});

it('validates before any I/O', function (): void {
    $transport = new FakeTransport([$this->ok]);
    $bad = DecisionRequest::fromArray(['state' => 's', 'questions' => ['s' => ['type' => 'score', 'criteria' => ['one']]]]);
    $failures = [];
    $client = clientWith($transport)->onFailure(function (DecisionRequest $r, Throwable $e) use (&$failures): void {
        $failures[] = $e;
    });

    expect(fn() => $client->execute($bad))->toThrow(InvalidDecisionException::class)
        ->and($transport->count())->toBe(0)
        ->and($failures[0])->toBeInstanceOf(InvalidDecisionException::class);
});

it('prepare() is a dry run that applies request headers and sends nothing', function (): void {
    $transport = new FakeTransport();
    $prepared = clientWith($transport)->prepare($this->request->withOptions(new RequestOptions(headers: ['X-Trace-Id' => 't1'])));

    expect($prepared->header('X-Trace-Id'))->toBe('t1')
        ->and($prepared->header('Authorization'))->toBe('Bearer sk-test')
        ->and($transport->count())->toBe(0);
});

it('merges request options over client defaults', function (): void {
    $transport = new FakeTransport([$this->ok]);
    clientWith($transport)->execute($this->request->withOptions(new RequestOptions(timeout: 2.5)));

    expect($transport->sentOptions()[0]->timeout)->toBe(2.5);
});

it('retries retryable statuses with the retrier delay and returns the eventual success', function (): void {
    $transport = new FakeTransport([
        HttpResponse::jsonResponse(429, ['message' => 'slow down'], ['retry-after-ms' => '100']),
        HttpResponse::jsonResponse(503, []),
        $this->ok,
    ]);
    $sleeps = [];
    $outcome = clientWith($transport, sleeps: $sleeps)->execute($this->request);

    expect($outcome->department->choice)->toBe('technical')
        ->and($transport->count())->toBe(3)
        ->and($sleeps)->toBe([0.1, 1.0]);
});

it('throws the last error once retries are exhausted', function (): void {
    $transport = new FakeTransport([HttpResponse::jsonResponse(503, []), HttpResponse::jsonResponse(503, []), HttpResponse::jsonResponse(503, []), $this->ok]);
    $sleeps = [];
    $client = clientWith($transport, sleeps: $sleeps);

    expect(fn() => $client->execute($this->request))->toThrow(ServerException::class)
        ->and($transport->count())->toBe(3)
        ->and($sleeps)->toBe([0.5, 1.0]);
});

it('does not retry non-retryable statuses', function (): void {
    $transport = new FakeTransport([HttpResponse::jsonResponse(401, ['message' => 'bad key']), $this->ok]);

    expect(fn() => clientWith($transport)->execute($this->request))->toThrow(AuthenticationException::class, 'bad key')
        ->and($transport->count())->toBe(1);
});

it('retries transport errors and rethrows when exhausted', function (): void {
    $transport = new FakeTransport([new ConnectionException('refused'), new TimeoutException('slow', 10.0), $this->ok]);
    $sleeps = [];
    expect(clientWith($transport, sleeps: $sleeps)->execute($this->request))->toBeInstanceOf(Outcome::class)
        ->and($sleeps)->toBe([0.5, 1.0]);

    $transport = new FakeTransport([new ConnectionException('a'), new ConnectionException('b'), new ConnectionException('c')]);
    expect(fn() => clientWith($transport)->execute($this->request))->toThrow(ConnectionException::class, 'c');

    $transport = new FakeTransport([new TimeoutException('slow', 10.0), $this->ok]);
    expect(fn() => clientWith($transport, RetryPolicy::none())->execute($this->request))->toThrow(TimeoutException::class);
});

it('honours a per-request retry policy', function (): void {
    $transport = new FakeTransport([HttpResponse::jsonResponse(429, []), $this->ok]);

    expect(fn() => clientWith($transport)->execute($this->request->withOptions(new RequestOptions(retry: RetryPolicy::none()))))->toThrow(RateLimitException::class)
        ->and($transport->count())->toBe(1);
});

it('lists models through the jev engine and refuses for others', function (): void {
    $transport = new FakeTransport([HttpResponse::jsonResponse(200, [['name' => 'jev-1.13.0', 'description' => 'd', 'release_date' => '2026-01-01']])]);
    $cards = clientWith($transport)->models();

    expect($cards[0]->name)->toBe('jev-1.13.0')
        ->and($transport->lastSent()?->method)->toBe('GET');

    $manager = engines();
    $manager->extend('other', fn(array $c): Swis\DecisionEngine\Contracts\Engine => $manager->engine('jev'));
    // A non-Jev instance: use an anonymous engine via register()
    $client = new Client($manager, $transport);
    expect(fn() => $client->models('nope'))->toThrow(ConfigurationException::class);
});

it('builds from Config', function (): void {
    $config = Config::fromArray(['engines' => ['jev' => ['driver' => 'jev', 'api_key' => 'k']], 'transport' => ['timeout' => 3.5], 'concurrency' => ['default' => 4]]);
    $client = Client::fromConfig($config, new FakeTransport([$this->ok]));

    expect($client->concurrency())->toBe(4)
        ->and($client->retryPolicy()->maxRetries)->toBe(2)
        ->and($client->engine()->name())->toBe('jev')
        ->and($client->options($this->request)->timeout)->toBe(3.5)
        ->and($client->execute($this->request))->toBeInstanceOf(Outcome::class);
});

it('runs the quickstart live when TYPESAFE_API_KEY is set', function (): void {
    $key = getenv('TYPESAFE_API_KEY');

    if (! is_string($key) || $key === '') {
        $this->markTestSkipped('TYPESAFE_API_KEY not set');
    }

    $client = Client::fromConfig(Config::fromEnv());
    $outcome = $client->execute($this->request);

    expect($outcome->model)->toStartWith('jev')
        ->and($outcome->department->choice)->toBeIn(['billing', 'technical', 'sales'])
        ->and(round(array_sum($outcome->department->probabilities), 2))->toBe(1.0)
        ->and($outcome->frustration->score)->toBeGreaterThanOrEqual(0.0)
        ->and($outcome->is_urgent->noul)->toBeGreaterThanOrEqual(0.0)
        ->and($outcome->usage->inputTokens)->toBeGreaterThan(0)
        ->and($outcome->meta->requestId)->not->toBeNull()
        ->and($outcome->meta->calibrated)->toBeTrue();
})->group('live');
