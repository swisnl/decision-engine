<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

/**
 * Marker interface implemented by every exception this package throws.
 *
 * ```php
 * try {
 *     $outcome = Decision::for($state)->noul('urgent', 'Is this urgent?')->decide();
 * } catch (DecisionEngineException $e) {
 *     // configuration, validation, transport or API failure
 * }
 * ```
 */
interface DecisionEngineException extends \Throwable {}
