<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Concurrency\FiberScheduler;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Exceptions\ParallelFailedException;
use Swis\DecisionEngine\Exceptions\ServerException;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Testing\FakeConcurrentTransport;
use Swis\DecisionEngine\Testing\FakeTransport;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Transport\Retrier;
use Swis\DecisionEngine\Transport\RetryPolicy;

function parallelClient(FakeTransport $transport, int $concurrency = 10, ?array &$logs = null): Client
{
    $engines = EngineManager::fromArray(['engines' => ['jev' => ['driver' => 'jev', 'api_key' => 'k'], 'haiku' => ['driver' => 'jev', 'api_key' => 'k2', 'model' => 'haiku-ish']]]);
    $logger = new class ($logs) extends AbstractLogger {
        public function __construct(private ?array &$logs) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->logs[] = [$level, (string) $message];
        }
    };

    return new Client($engines, $transport, new RequestOptions(), RetryPolicy::default(), $logger, $concurrency, new Retrier(fn(): float => 0.0));
}

function noulBody(float $p = 0.9): array
{
    return ['model' => 'jev-1.13.0', 'answers' => ['urgent' => ['type' => 'noul', 'noul' => $p]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]];
}

afterEach(fn() => Decision::resolveClientUsing(null));

it('runs 20 decisions with 200 ms latency at concurrency 10 in about 400 ms', function (): void {
    $transport = new FakeConcurrentTransport(resolver: fn(): HttpResponse => HttpResponse::jsonResponse(200, noulBody())->withLatency(200));
    $client = parallelClient($transport, 10);

    $decisions = [];
    for ($i = 0; $i < 20; $i++) {
        $decisions["t{$i}"] = Decision::for("state {$i}")->withClient($client)->noul('urgent', 'Is this urgent?');
    }

    $start = microtime(true);
    $outcomes = Decision::parallel($decisions, concurrency: 10, client: $client);
    $elapsed = microtime(true) - $start;

    expect(array_keys($outcomes))->toBe(array_keys($decisions))
        ->and($outcomes['t7'])->toBeInstanceOf(Outcome::class)
        ->and($outcomes['t7']->urgent->noul)->toBe(0.9)
        ->and($transport->count())->toBe(20)
        ->and($elapsed)->toBeGreaterThan(0.35)->toBeLessThan(1.2);
});

it('retries a 429 with retry-after inside a batch without stalling the others', function (): void {
    $calls = 0;
    $transport = new FakeConcurrentTransport(resolver: function (PreparedRequest $r) use (&$calls): HttpResponse {
        $calls++;
        $state = $r->json()['state'];

        if ($state === 'rate-limited' && $calls <= 2) {
            return HttpResponse::jsonResponse(429, ['message' => 'slow'], ['retry-after-ms' => '150'])->withLatency(10);
        }

        return HttpResponse::jsonResponse(200, noulBody())->withLatency(100);
    });
    $client = parallelClient($transport, 2);

    $tasks = [
        'a' => Decision::for('rate-limited')->withClient($client)->noul('urgent', 'q'),
        'b' => Decision::for('ok-1')->withClient($client)->noul('urgent', 'q'),
        'c' => Decision::for('ok-2')->withClient($client)->noul('urgent', 'q'),
        'd' => Decision::for('ok-3')->withClient($client)->noul('urgent', 'q'),
    ];

    $start = microtime(true);
    $outcomes = Decision::parallel($tasks, concurrency: 2, client: $client);
    $elapsed = microtime(true) - $start;

    expect($outcomes)->toHaveCount(4)
        ->and($outcomes['a']->urgent->noul)->toBe(0.9)
        ->and($transport->count())->toBe(5) // one retry
        ->and($elapsed)->toBeLessThan(0.8);
});

it('isolates failures and throws ParallelFailedException after everything settled', function (): void {
    $transport = new FakeConcurrentTransport(resolver: fn(PreparedRequest $r): HttpResponse => $r->json()['state'] === 'bad'
        ? HttpResponse::jsonResponse(500, ['message' => 'boom'])->withLatency(20)
        : HttpResponse::jsonResponse(200, noulBody())->withLatency(20));
    $client = parallelClient($transport);

    $tasks = [
        'good' => Decision::for('good')->withClient($client)->noul('urgent', 'q'),
        'bad' => Decision::for('bad')->withClient($client)->noul('urgent', 'q')->withOptions(['retry' => RetryPolicy::none()]),
        'closure' => fn() => throw new RuntimeException('closure failed'),
        'later' => Decision::for('good')->withClient($client)->noul('urgent', 'q'),
    ];

    try {
        Decision::parallel($tasks, client: $client);
        $this->fail('Expected ParallelFailedException');
    } catch (ParallelFailedException $e) {
        expect(array_keys($e->outcomes()))->toBe(['good', 'later'])
            ->and(array_keys($e->failures()))->toBe(['bad', 'closure'])
            ->and($e->failures()['bad'])->toBeInstanceOf(ServerException::class)
            ->and($e->failures()['closure']->getMessage())->toBe('closure failed')
            ->and($e->getMessage())->toContain('2 of 4');
    }

    $settled = Decision::settle($tasks, client: $client);
    expect($settled['good'])->toBeInstanceOf(Outcome::class)
        ->and($settled['bad'])->toBeInstanceOf(ServerException::class)
        ->and($settled['closure'])->toBeInstanceOf(RuntimeException::class);
});

it('overlaps sequential decide() calls inside closures and supports nested parallel()', function (): void {
    $transport = new FakeConcurrentTransport(resolver: fn(): HttpResponse => HttpResponse::jsonResponse(200, noulBody())->withLatency(100));
    $client = parallelClient($transport, 10);

    $triage = function (string $ticket) use ($client): array {
        $first = Decision::for($ticket)->withClient($client)->noul('urgent', 'q')->decide();
        $second = Decision::for($ticket . ' follow-up')->withClient($client)->noul('urgent', 'q')->decide();

        // Nested fan-out reuses the running loop.
        $inner = Decision::parallel([
            'x' => Decision::for($ticket . ' x')->withClient($client)->noul('urgent', 'q'),
            'y' => fn() => Decision::for($ticket . ' y')->withClient($client)->noul('urgent', 'q')->decide()->urgent->noul,
        ], client: $client);

        return [$first->urgent->noul, $second->urgent->noul, $inner['x']->urgent->noul, $inner['y']];
    };

    $start = microtime(true);
    $results = Decision::parallel([fn() => $triage('t1'), fn() => $triage('t2'), fn() => $triage('t3')], client: $client);
    $elapsed = microtime(true) - $start;

    expect($results)->toHaveCount(3)
        ->and($results[0])->toBe([0.9, 0.9, 0.9, 0.9])
        ->and($transport->count())->toBe(12)
        // 3 sequential hops (first, second, nested) of 100 ms each, overlapped across tasks → ~300 ms, not 1.2 s
        ->and($elapsed)->toBeGreaterThan(0.25)->toBeLessThan(0.9);
});

it('forEach decides many states with the same questions, keys preserved, lazily', function (): void {
    $transport = new FakeConcurrentTransport(resolver: fn(PreparedRequest $r): HttpResponse => HttpResponse::jsonResponse(200, noulBody($r->json()['state']['spam'] ? 0.95 : 0.05))->withLatency(30));
    $client = parallelClient($transport);

    $tickets = (function (): Generator {
        yield 'id-1' => ['body' => 'buy now', 'spam' => true];
        yield 'id-2' => ['body' => 'hello', 'spam' => false];
        yield 'id-3' => ['body' => 'cheap pills', 'spam' => true];
    })();

    $batch = Decision::forEach($tickets, fn(array $ticket): array => ['message' => $ticket['body'], 'spam' => $ticket['spam']])
        ->withClient($client)
        ->noul('urgent', 'Is this message spam?')
        ->using('haiku');

    $outcomes = $batch->decide(concurrency: 20);

    expect(array_keys($outcomes))->toBe(['id-1', 'id-2', 'id-3'])
        ->and($outcomes['id-1']->urgent->isTrue(0.9))->toBeTrue()
        ->and($outcomes['id-2']->urgent->isFalse(0.9))->toBeTrue()
        ->and($transport->sent()[0]->json()['state'])->toBe(['message' => 'buy now', 'spam' => true])
        ->and($transport->sent()[0]->json()['model'])->toBe('haiku-ish')
        ->and($batch->template()->questions()->ids())->toBe(['urgent']);

    $settled = Decision::forEach(['k' => ['body' => 'x', 'spam' => false]], fn(array $t): array => $t)->withClient($client)->noul('urgent', 'q')->settle();
    expect($settled['k'])->toBeInstanceOf(Outcome::class);
});

it('falls back to sequential execution with a warning when the transport is not concurrent', function (): void {
    $transport = new FakeTransport(resolver: fn(): HttpResponse => HttpResponse::jsonResponse(200, noulBody()));
    $logs = [];
    $client = parallelClient($transport, logs: $logs);

    $outcomes = Decision::parallel([
        'a' => Decision::for('a')->withClient($client)->noul('urgent', 'q'),
        'b' => fn() => 'plain value',
    ], client: $client);

    expect($outcomes['a'])->toBeInstanceOf(Outcome::class)
        ->and($outcomes['b'])->toBe('plain value')
        ->and(array_filter($logs, fn(array $l) => $l[0] === 'warning' && str_contains($l[1], 'sequentially')))->toHaveCount(1);

    expect(fn() => Decision::parallel(['x' => 'not a task'], client: $client))->toThrow(InvalidArgumentException::class);
});

it('uses the statically resolved client when none is given', function (): void {
    $transport = new FakeConcurrentTransport(resolver: fn(): HttpResponse => HttpResponse::jsonResponse(200, noulBody()));
    Decision::resolveClientUsing(fn(): Client => parallelClient($transport));

    $outcomes = Decision::parallel(['a' => Decision::for('a')->noul('urgent', 'q')]);

    expect($outcomes['a']->urgent->noul)->toBe(0.9)->and(FiberScheduler::current())->toBeNull();
});

it('rejects a concurrency below one and exposes the scheduler primitives outside fibers', function (): void {
    expect(fn() => new FiberScheduler(new FakeConcurrentTransport(), 0))->toThrow(InvalidArgumentException::class);

    $transport = new FakeConcurrentTransport([HttpResponse::jsonResponse(200, ['ok' => true])]);
    $scheduler = new FiberScheduler($transport, 3);

    expect($scheduler->concurrency())->toBe(3)
        ->and($scheduler->await(new PreparedRequest('GET', 'fake://x', [], ''), new RequestOptions())->json())->toBe(['ok' => true]);

    $scheduler->sleep(0.001);
    expect($scheduler->run([]))->toBe([]);
});

it('sends each decision over the transport of the client it is bound to', function (): void {
    $default = new FakeConcurrentTransport(resolver: fn(): \Throwable => new LogicException('default transport used'));
    Decision::resolveClientUsing(fn(): Client => parallelClient($default));

    $concurrent = new FakeConcurrentTransport(resolver: fn(): HttpResponse => HttpResponse::jsonResponse(200, noulBody(0.7))->withLatency(100));
    $blocking = new FakeTransport(resolver: fn(): HttpResponse => HttpResponse::jsonResponse(200, noulBody(0.2)));

    $start = microtime(true);
    $outcomes = Decision::parallel([
        'a' => Decision::for('a')->withClient(parallelClient($concurrent))->noul('urgent', 'q'),
        'b' => Decision::for('b')->withClient(parallelClient($concurrent))->noul('urgent', 'q'),
        'c' => Decision::for('c')->withClient(parallelClient($blocking))->noul('urgent', 'q'),
    ]);
    $elapsed = microtime(true) - $start;

    expect($outcomes['a']->urgent->noul)->toBe(0.7)
        ->and($outcomes['b']->urgent->noul)->toBe(0.7)
        ->and($outcomes['c']->urgent->noul)->toBe(0.2)
        ->and($default->count())->toBe(0)
        ->and($concurrent->count())->toBe(2)
        ->and($blocking->count())->toBe(1)
        ->and($elapsed)->toBeLessThan(0.18); // a and b overlapped
});

it('settles a failing forEach state closure as that key\'s failure', function (): void {
    $client = parallelClient(new FakeConcurrentTransport(resolver: fn(): HttpResponse => HttpResponse::jsonResponse(200, noulBody())));
    $toState = fn(string $body, string $key): string => $key === 'bad' ? throw new RuntimeException('cannot build state') : $body;

    $settled = Decision::forEach(['ok' => 'fine', 'bad' => 'broken'], $toState)->withClient($client)->noul('urgent', 'q')->settle();

    expect($settled['ok'])->toBeInstanceOf(Outcome::class)
        ->and($settled['bad'])->toBeInstanceOf(RuntimeException::class);

    try {
        Decision::forEach(['ok' => 'fine', 'bad' => 'broken'], $toState)->withClient($client)->noul('urgent', 'q')->decide();
        $this->fail('Expected a ParallelFailedException.');
    } catch (ParallelFailedException $e) {
        expect(array_keys($e->outcomes()))->toBe(['ok'])->and(array_keys($e->failures()))->toBe(['bad']);
    }
});
