<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Contracts;

/**
 * A Transport that can also drive many requests concurrently (curl_multi). Required for
 * `Decision::parallel()` / `forEach()` to actually run in parallel; other transports fall back to
 * sequential execution.
 */
interface ConcurrentTransport extends Transport
{
    public function multi(): MultiHandle;
}
