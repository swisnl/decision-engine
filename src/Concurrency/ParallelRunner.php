<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Concurrency;

use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Contracts\ConcurrentTransport;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Exceptions\ParallelFailedException;

/**
 * Behind `Decision::parallel()` / `settle()`: normalizes Decisions and closures into tasks, runs
 * them on a FiberScheduler over the client's transport, and either throws a
 * ParallelFailedException or returns Throwables in place (settle).
 *
 * When the transport is not a ConcurrentTransport the tasks run sequentially and a warning is
 * logged — never an error.
 *
 * ```php
 * $outcomes = ParallelRunner::for($client)->run(['a' => $decisionA, 'b' => fn () => $this->triage($ticket)], concurrency: 10, throw: true);
 * ```
 */
final class ParallelRunner
{
    public function __construct(private readonly Client $client) {}

    public static function for(Client $client): self
    {
        return new self($client);
    }

    /**
     * @template K of array-key
     *
     * @param  iterable<K, Decision|\Closure(): mixed>  $tasks
     * @return array<K, mixed>
     *
     * @throws ParallelFailedException when `$throw` and at least one task failed
     */
    public function run(iterable $tasks, ?int $concurrency = null, bool $throw = true): array
    {
        $normalized = self::normalize($tasks);
        $transport = $this->client->transport();
        $current = FiberScheduler::current();

        if ($current !== null) {
            $results = $current->run($normalized);
        } elseif ($transport instanceof ConcurrentTransport) {
            $results = (new FiberScheduler($transport, $concurrency ?? $this->client->concurrency()))->run($normalized);
        } else {
            $this->client->logger()->warning('Decision::parallel() is running sequentially: the configured transport does not support concurrency.', ['transport' => $transport::class]);
            $results = self::sequential($normalized);
        }

        if (! $throw) {
            return $results;
        }

        $failures = array_filter($results, static fn(mixed $r): bool => $r instanceof \Throwable);

        if ($failures !== []) {
            /** @var non-empty-array<K, \Throwable> $failures */
            throw new ParallelFailedException(array_diff_key($results, $failures), $failures);
        }

        return $results;
    }

    /**
     * @template K of array-key
     *
     * @param  iterable<K, Decision|\Closure(): mixed>  $tasks
     * @return \Generator<K, \Closure(): mixed>
     */
    private static function normalize(iterable $tasks): \Generator
    {
        foreach ($tasks as $key => $task) {
            if ($task instanceof Decision) {
                yield $key => static fn(): mixed => $task->decide();
            } elseif ($task instanceof \Closure) {
                yield $key => $task;
            } else {
                throw new \InvalidArgumentException('Parallel tasks must be Decision instances or closures, ' . get_debug_type($task) . " given for key [{$key}].");
            }
        }
    }

    /**
     * @template K of array-key
     *
     * @param  iterable<K, \Closure(): mixed>  $tasks
     * @return array<K, mixed>
     */
    private static function sequential(iterable $tasks): array
    {
        $results = [];

        foreach ($tasks as $key => $task) {
            try {
                $results[$key] = $task();
            } catch (\Throwable $e) {
                $results[$key] = $e;
            }
        }

        return $results;
    }
}
