<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

/**
 * One or more tasks in Decision::parallel() / forEach() failed. Every task ran to completion first;
 * successful outcomes and failures are both available, keyed like the input.
 *
 * ```php
 * try {
 *     $outcomes = Decision::parallel($decisions);
 * } catch (ParallelFailedException $e) {
 *     $e->outcomes(); // array<key, mixed>      — the ones that succeeded
 *     $e->failures(); // array<key, \Throwable> — the ones that did not
 * }
 * ```
 */
final class ParallelFailedException extends \RuntimeException implements DecisionEngineException
{
    /**
     * @param  array<array-key, mixed>  $outcomes
     * @param  non-empty-array<array-key, \Throwable>  $failures
     */
    public function __construct(
        private readonly array $outcomes,
        private readonly array $failures,
    ) {
        $first = reset($failures);
        $count = count($failures);

        parent::__construct(
            "{$count} of " . ($count + count($outcomes)) . ' parallel decisions failed. First failure [' . array_key_first($failures) . ']: ' . $first->getMessage(),
            0,
            $first,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /**
     * @return non-empty-array<array-key, \Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
