<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Concurrency;

use Swis\DecisionEngine\Contracts\ConcurrentTransport;
use Swis\DecisionEngine\Contracts\MultiHandle;
use Swis\DecisionEngine\Contracts\Transport;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * Runs closures in Fibers and multiplexes their HTTP requests over curl_multi.
 *
 * `Client::execute()` calls `await()` when a scheduler is current, so the *same* `decide()` call
 * suspends inside a task and blocks outside. Tasks are started lazily from the iterable, retry
 * backoff sleeps are timers on the loop (a sleeping task holds no request slot), a failing task
 * records its Throwable and the rest continue, and `run()` inside a task reuses the active loop.
 *
 * Each request goes out over the transport of the client that made it: the scheduler's own
 * transport by default, so a Decision bound to another client (a fake, a recording, a PSR-18 stack)
 * keeps its transport. Concurrent transports each get their own multi handle; others send blocking.
 *
 * `concurrency` bounds in-flight HTTP requests (across transports) and active (non-sleeping) tasks.
 *
 * ```php
 * $scheduler = new FiberScheduler(new CurlTransport(), concurrency: 10);
 * $results = $scheduler->run([
 *     'a' => fn () => $client->execute($requestA),
 *     'b' => fn () => $client->execute($requestB),
 * ]); // ['a' => Outcome, 'b' => Outcome|Throwable]
 * ```
 */
final class FiberScheduler
{
    private const TICK_SECONDS = 0.05;

    /**
     * @var list<self>
     */
    private static array $stack = [];

    /**
     * @var array<int, MultiHandle>  spl_object_id(transport) → its multi handle
     */
    private array $multis = [];

    private int $nextFiberId = 1;

    private int $nextGroupId = 1;

    /**
     * @var array<int, array{fiber: \Fiber<mixed, mixed, mixed, mixed>, key: array-key, group: int, state: string}>
     */
    private array $tasks = [];

    /**
     * @var \SplObjectStorage<\Fiber<mixed, mixed, mixed, mixed>, int>
     */
    private \SplObjectStorage $fiberIds;

    /**
     * @var array<int, array{pending: \Iterator<array-key, \Closure(): mixed>, started: int, finished: int, results: array<array-key, mixed>, order: list<array-key>, parent: int|null, done: bool}>
     */
    private array $groups = [];

    /**
     * @var list<array{0: int, 1: PreparedRequest, 2: RequestOptions, 3: ConcurrentTransport}>  requests waiting for a slot
     */
    private array $queue = [];

    /**
     * @var array<int, array<int, int>>  multi handle key → multi request id → fiber id
     */
    private array $awaiting = [];

    /**
     * @var list<array{0: float, 1: int}>  wake-up time (seconds, hrtime based) → fiber id
     */
    private array $timers = [];

    /**
     * @var list<array{0: int, 1: string, 2: mixed}>  fiber id, 'resume'|'throw', value
     */
    private array $ready = [];

    public function __construct(
        private readonly ConcurrentTransport $transport,
        private readonly int $concurrency = 10,
    ) {
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('Concurrency must be at least 1.');
        }

        $this->fiberIds = new \SplObjectStorage();
    }

    /**
     * The scheduler whose loop is currently running, if any.
     */
    public static function current(): ?self
    {
        return self::$stack === [] ? null : self::$stack[count(self::$stack) - 1];
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    /**
     * Run all tasks and return their results keyed like the input. A task that throws contributes
     * its Throwable instead of a value.
     *
     * @template K of array-key
     * @template T
     *
     * @param  iterable<K, \Closure(): T>  $tasks
     * @return array<K, T|\Throwable>
     */
    public function run(iterable $tasks): array
    {
        $current = self::current();

        if ($current !== null && $current !== $this) {
            return $current->run($tasks);
        }

        $pending = self::iterator($tasks);

        if ($current === $this) {
            /** @var array<K, T|\Throwable> $results */
            $results = $this->runNested($pending);

            return $results;
        }

        $groupId = $this->openGroup($pending, null);
        self::$stack[] = $this;

        try {
            $this->loop($groupId);

            /** @var array<K, T|\Throwable> $results */
            $results = $this->groups[$groupId]['results'];

            return $results;
        } finally {
            array_pop(self::$stack);
            unset($this->groups[$groupId]);

            if ($this->groups === []) {
                foreach ($this->multis as $multi) {
                    $multi->close();
                }

                $this->multis = [];
            }
        }
    }

    /**
     * Called by Client::execute() from inside a task: suspends until the response arrives.
     * Outside a managed Fiber, or for a transport that cannot multiplex, it sends blocking.
     */
    public function await(PreparedRequest $request, RequestOptions $options, ?Transport $transport = null): HttpResponse
    {
        $transport ??= $this->transport;
        $fiberId = $this->currentFiberId();

        if ($fiberId === null || ! $transport instanceof ConcurrentTransport) {
            return $transport->send($request, $options);
        }

        $this->tasks[$fiberId]['state'] = 'awaiting';
        $this->queue[] = [$fiberId, $request, $options, $transport];

        $response = \Fiber::suspend();

        if (! $response instanceof HttpResponse) {
            throw new \LogicException('Scheduler resumed an awaiting task without a response.');
        }

        return $response;
    }

    /**
     * Non-blocking sleep for retry backoff. Outside a managed Fiber it blocks.
     */
    public function sleep(float $seconds): void
    {
        $fiberId = $this->currentFiberId();

        if ($fiberId === null) {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }

            return;
        }

        $this->tasks[$fiberId]['state'] = 'sleeping';
        $this->timers[] = [self::now() + max(0.0, $seconds), $fiberId];
        \Fiber::suspend();
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /**
     * @param  \Iterator<array-key, \Closure(): mixed>  $pending
     * @return array<array-key, mixed>
     */
    private function runNested(\Iterator $pending): array
    {
        $parentId = $this->currentFiberId();

        if ($parentId === null) {
            // A nested run() from non-fiber code while the loop is active cannot happen in practice;
            // execute sequentially to stay correct.
            $results = [];

            foreach ($pending as $key => $task) {
                try {
                    $results[$key] = $task();
                } catch (\Throwable $e) {
                    $results[$key] = $e;
                }
            }

            return $results;
        }

        $this->openGroup($pending, $parentId);
        $this->tasks[$parentId]['state'] = 'waiting';

        $results = \Fiber::suspend();

        if (! is_array($results)) {
            throw new \LogicException('Scheduler resumed a waiting task without child results.');
        }

        return $results;
    }

    /**
     * @param  \Iterator<array-key, \Closure(): mixed>  $pending
     */
    private function openGroup(\Iterator $pending, ?int $parent): int
    {
        $id = $this->nextGroupId++;
        $pending->rewind();
        $this->groups[$id] = ['pending' => $pending, 'started' => 0, 'finished' => 0, 'results' => [], 'order' => [], 'parent' => $parent, 'done' => false];

        return $id;
    }

    private function loop(int $rootGroup): void
    {
        while (true) {
            $this->drainReady();
            $this->startTasks();
            $this->flushQueue();
            $this->completeGroups();

            if ($this->groups[$rootGroup]['done']) {
                return;
            }

            if ($this->ready !== []) {
                continue;
            }

            $timeout = $this->nextTimeout();
            $busy = array_filter($this->multis, static fn(MultiHandle $multi): bool => $multi->inFlight() > 0);

            if ($busy !== []) {
                // Share the wait between handles so none of them delays the others' completions.
                $share = $timeout / count($busy);

                foreach ($busy as $key => $multi) {
                    foreach ($multi->tick($share) as [$requestId, $result]) {
                        $fiberId = $this->awaiting[$key][$requestId] ?? null;
                        unset($this->awaiting[$key][$requestId]);

                        if ($fiberId !== null) {
                            $this->ready[] = [$fiberId, $result instanceof \Throwable ? 'throw' : 'resume', $result];
                        }
                    }
                }
            } elseif ($this->timers !== []) {
                if ($timeout > 0) {
                    usleep((int) round($timeout * 1_000_000));
                }
            } elseif ($this->queue === [] && ! $this->hasStartableTasks()) {
                throw new \LogicException('FiberScheduler deadlock: tasks are suspended but nothing can wake them.');
            }

            $this->fireTimers();
        }
    }

    private function drainReady(): void
    {
        while ($this->ready !== []) {
            [$fiberId, $mode, $value] = array_shift($this->ready);
            $task = $this->tasks[$fiberId] ?? null;

            if ($task === null) {
                continue;
            }

            $task['state'] = 'running';
            $this->tasks[$fiberId] = $task;

            try {
                if ($mode === 'throw' && $value instanceof \Throwable) {
                    $task['fiber']->throw($value);
                } else {
                    $task['fiber']->resume($value);
                }
            } catch (\Throwable $e) {
                $this->finish($fiberId, $e);

                continue;
            }

            if ($task['fiber']->isTerminated()) {
                $this->finish($fiberId, $task['fiber']->getReturn());
            }
        }
    }

    private function startTasks(): void
    {
        foreach ($this->groups as $groupId => $group) {
            $pending = $group['pending'];

            while ($this->activeTasks() < $this->concurrency && $pending->valid()) {
                $task = $pending->current();
                $key = $pending->key();
                $pending->next();

                if (! $task instanceof \Closure || (! is_int($key) && ! is_string($key))) {
                    throw new \InvalidArgumentException('Parallel tasks must be closures keyed by int|string.');
                }

                $record = $this->groups[$groupId];
                $record['started']++;
                $record['order'][] = $key;
                $this->groups[$groupId] = $record;

                $this->startFiber($task, $key, $groupId);
            }
        }
    }

    /**
     * @param  \Closure(): mixed  $task
     */
    private function startFiber(\Closure $task, int|string $key, int $groupId): void
    {
        $fiber = new \Fiber($task);
        $fiberId = $this->nextFiberId++;

        $this->tasks[$fiberId] = ['fiber' => $fiber, 'key' => $key, 'group' => $groupId, 'state' => 'running'];
        $this->fiberIds[$fiber] = $fiberId;

        try {
            $fiber->start();
        } catch (\Throwable $e) {
            $this->finish($fiberId, $e);

            return;
        }

        if ($fiber->isTerminated()) {
            $this->finish($fiberId, $fiber->getReturn());
        }
    }

    private function finish(int $fiberId, mixed $result): void
    {
        $task = $this->tasks[$fiberId] ?? null;

        if ($task === null) {
            return;
        }

        unset($this->tasks[$fiberId]);
        $this->fiberIds->detach($task['fiber']);

        $group = &$this->groups[$task['group']];
        $group['results'][$task['key']] = $result;
        $group['finished']++;
        unset($group);
    }

    private function completeGroups(): void
    {
        foreach ($this->groups as $groupId => $group) {
            if ($group['done'] || $group['pending']->valid() || $group['started'] !== $group['finished']) {
                continue;
            }

            $this->groups[$groupId]['done'] = true;
            $this->groups[$groupId]['results'] = self::inInputOrder($group['results'], $group['order']);

            if ($group['parent'] !== null) {
                $this->ready[] = [$group['parent'], 'resume', $this->groups[$groupId]['results']];
                unset($this->groups[$groupId]);
            }
        }
    }

    private function flushQueue(): void
    {
        while ($this->queue !== [] && $this->inFlight() < $this->concurrency) {
            [$fiberId, $request, $options, $transport] = array_shift($this->queue);
            $key = spl_object_id($transport);
            $multi = $this->multis[$key] ??= $transport->multi();
            $this->awaiting[$key][$multi->add($request, $options)] = $fiberId;
        }
    }

    private function fireTimers(): void
    {
        if ($this->timers === []) {
            return;
        }

        $now = self::now();
        $remaining = [];

        foreach ($this->timers as $timer) {
            if ($timer[0] <= $now) {
                $this->ready[] = [$timer[1], 'resume', null];
            } else {
                $remaining[] = $timer;
            }
        }

        $this->timers = $remaining;
    }

    private function nextTimeout(): float
    {
        $timeout = self::TICK_SECONDS;

        if ($this->timers !== []) {
            $now = self::now();

            foreach ($this->timers as [$wakeAt]) {
                $timeout = min($timeout, max(0.0, $wakeAt - $now));
            }
        }

        return $timeout;
    }

    private function activeTasks(): int
    {
        $active = 0;

        foreach ($this->tasks as $task) {
            if ($task['state'] !== 'sleeping' && $task['state'] !== 'waiting') {
                $active++;
            }
        }

        return $active;
    }

    private function hasStartableTasks(): bool
    {
        foreach ($this->groups as $group) {
            if ($group['pending']->valid()) {
                return true;
            }
        }

        return false;
    }

    private function currentFiberId(): ?int
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null || ! $this->fiberIds->contains($fiber)) {
            return null;
        }

        return $this->fiberIds[$fiber];
    }

    private function inFlight(): int
    {
        return array_sum(array_map(static fn(MultiHandle $multi): int => $multi->inFlight(), $this->multis));
    }

    /**
     * @param  iterable<array-key, \Closure(): mixed>  $tasks
     * @return \Iterator<array-key, \Closure(): mixed>
     */
    private static function iterator(iterable $tasks): \Iterator
    {
        if ($tasks instanceof \Iterator) {
            return $tasks;
        }

        if (is_array($tasks)) {
            return new \ArrayIterator($tasks);
        }

        return (static function () use ($tasks): \Generator {
            yield from $tasks;
        })();
    }

    /**
     * Results arrive in completion order; hand them back in the order the tasks were given.
     *
     * @param  array<array-key, mixed>  $results
     * @param  list<array-key>  $order
     * @return array<array-key, mixed>
     */
    private static function inInputOrder(array $results, array $order): array
    {
        $ordered = [];

        foreach ($order as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
