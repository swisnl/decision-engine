<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Testing;

use Swis\DecisionEngine\Contracts\Transport;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * A Transport that answers from a queue or a resolver and records what was sent.
 * Queue items may be HttpResponse instances, Throwables (thrown on send) or closures.
 *
 * ```php
 * $transport = new FakeTransport([HttpResponse::jsonResponse(200, $body)]);
 * $transport->push(new TimeoutException('slow', 10.0));
 * $transport = new FakeTransport(resolver: fn (PreparedRequest $r): HttpResponse => …);
 * $transport->sent();     // list<PreparedRequest>
 * $transport->lastSent(); // ?PreparedRequest
 * ```
 */
class FakeTransport implements Transport
{
    /**
     * @var list<HttpResponse|\Throwable|\Closure(PreparedRequest, RequestOptions): (HttpResponse|\Throwable)>
     */
    private array $queue;

    /**
     * @var list<array{request: PreparedRequest, options: RequestOptions}>
     */
    private array $sent = [];

    /**
     * @param  list<HttpResponse|\Throwable|\Closure(PreparedRequest, RequestOptions): (HttpResponse|\Throwable)>  $queue
     * @param  (\Closure(PreparedRequest, RequestOptions): (HttpResponse|\Throwable))|null  $resolver  used when the queue is empty
     */
    public function __construct(array $queue = [], private readonly ?\Closure $resolver = null)
    {
        $this->queue = $queue;
    }

    public function push(HttpResponse|\Throwable|\Closure ...$items): static
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    public function send(PreparedRequest $request, RequestOptions $options): HttpResponse
    {
        return $this->dispatch($request, $options);
    }

    /**
     * Record the request and resolve the response without any simulated delay.
     */
    public function dispatch(PreparedRequest $request, RequestOptions $options): HttpResponse
    {
        $this->sent[] = ['request' => $request, 'options' => $options];

        $item = array_shift($this->queue) ?? $this->resolver ?? throw new \LogicException('FakeTransport has no queued response for ' . $request->method . ' ' . $request->url . '.');

        if ($item instanceof \Closure) {
            $item = $item($request, $options);
        }

        if ($item instanceof \Throwable) {
            throw $item;
        }

        return $item;
    }

    /**
     * @return list<PreparedRequest>
     */
    public function sent(): array
    {
        return array_map(static fn(array $entry): PreparedRequest => $entry['request'], $this->sent);
    }

    /**
     * @return list<RequestOptions>
     */
    public function sentOptions(): array
    {
        return array_map(static fn(array $entry): RequestOptions => $entry['options'], $this->sent);
    }

    public function lastSent(): ?PreparedRequest
    {
        $last = end($this->sent);

        return $last === false ? null : $last['request'];
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function remaining(): int
    {
        return count($this->queue);
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->queue = [];
    }
}
