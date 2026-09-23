<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Testing;

use Swis\DecisionEngine\Contracts\ConcurrentTransport;
use Swis\DecisionEngine\Contracts\MultiHandle;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * A FakeTransport that also supports the Fiber scheduler, simulating latency in-process: each
 * response completes `latencyMs` after it was added (a response's own `latencyMs`, when set,
 * wins). Lets concurrency tests prove overlap without a network or a server.
 *
 * ```php
 * $transport = new FakeConcurrentTransport(resolver: fn () => HttpResponse::jsonResponse(200, $body)->withLatency(200));
 * ```
 */
final class FakeConcurrentTransport extends FakeTransport implements ConcurrentTransport
{
    /**
     * @param  list<HttpResponse|\Throwable|\Closure(PreparedRequest, RequestOptions): (HttpResponse|\Throwable)>  $queue
     * @param  (\Closure(PreparedRequest, RequestOptions): (HttpResponse|\Throwable))|null  $resolver
     * @param  float  $latencyMs  simulated latency for responses that carry none
     */
    public function __construct(array $queue = [], ?\Closure $resolver = null, private readonly float $latencyMs = 0.0)
    {
        parent::__construct($queue, $resolver);
    }

    /**
     * Blocking sends sleep for the simulated latency so sequential-vs-parallel comparisons are honest.
     */
    public function send(PreparedRequest $request, RequestOptions $options): HttpResponse
    {
        $response = $this->dispatch($request, $options);
        $latency = $response->latencyMs ?? $this->latencyMs;

        if ($latency > 0) {
            usleep((int) round($latency * 1000));
        }

        return $response;
    }

    public function multi(): MultiHandle
    {
        return new class ($this, $this->latencyMs) implements MultiHandle {
            private int $nextId = 1;

            /**
             * @var array<int, array{0: float, 1: HttpResponse|\Throwable}>  id → [completes at, result]
             */
            private array $inFlight = [];

            public function __construct(private readonly FakeConcurrentTransport $transport, private readonly float $defaultLatencyMs) {}

            public function add(PreparedRequest $request, RequestOptions $options): int
            {
                try {
                    $result = $this->transport->dispatch($request, $options);
                } catch (\Throwable $e) {
                    $result = $e;
                }

                $latency = $result instanceof HttpResponse ? ($result->latencyMs ?? $this->defaultLatencyMs) : $this->defaultLatencyMs;
                $timeout = $options->timeout;

                if ($result instanceof HttpResponse && $timeout !== null && $latency / 1000 > $timeout) {
                    $result = new \Swis\DecisionEngine\Exceptions\TimeoutException("Simulated timeout after {$timeout}s", $timeout, $request);
                    $latency = $timeout * 1000;
                }

                $this->inFlight[$this->nextId] = [microtime(true) + $latency / 1000, $result];

                return $this->nextId++;
            }

            public function tick(float $timeout): array
            {
                if ($this->inFlight === []) {
                    return [];
                }

                $next = min(array_map(static fn(array $e): float => $e[0], $this->inFlight));
                $wait = min($timeout, max(0.0, $next - microtime(true)));

                if ($wait > 0) {
                    usleep((int) round($wait * 1_000_000));
                }

                $now = microtime(true);
                $completed = [];

                foreach ($this->inFlight as $id => [$at, $result]) {
                    if ($at <= $now) {
                        $completed[] = [$id, $result];
                        unset($this->inFlight[$id]);
                    }
                }

                return $completed;
            }

            public function inFlight(): int
            {
                return count($this->inFlight);
            }

            public function close(): void
            {
                $this->inFlight = [];
            }
        };
    }
}
