<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

/**
 * HTTP 529 — the service is overloaded. Retryable.
 */
final class OverloadedException extends ApiException {}
