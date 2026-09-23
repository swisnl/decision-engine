<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Contracts;

use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * Drives many in-flight requests at once. Used by the FiberScheduler.
 *
 * ```php
 * $multi = $transport->multi();
 * $id = $multi->add($prepared, $options);
 * foreach ($multi->tick(0.05) as [$doneId, $result]) { … } // $result is HttpResponse|Throwable
 * ```
 */
interface MultiHandle
{
    /**
     * Enqueue a request; returns an id that `tick()` reports back once it completes.
     */
    public function add(PreparedRequest $request, RequestOptions $options): int;

    /**
     * Make progress and wait at most `$timeout` seconds for activity.
     *
     * @return list<array{0: int, 1: HttpResponse|\Throwable}> completed requests since the last tick
     */
    public function tick(float $timeout): array;

    /**
     * Number of requests added but not yet reported by `tick()`.
     */
    public function inFlight(): int;

    public function close(): void;
}
