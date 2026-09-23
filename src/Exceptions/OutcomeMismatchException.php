<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

/**
 * An Outcome does not fit a TypedOutcome class: an answer is missing, has another type than the
 * property expects, or is not a case of the property's enum.
 *
 * ```php
 * try { TicketTriage::fromOutcome($outcome); }
 * catch (OutcomeMismatchException $e) { $e->questionId; }   // 'department'
 * ```
 */
final class OutcomeMismatchException extends \UnexpectedValueException implements DecisionEngineException
{
    public function __construct(string $message, public readonly string $questionId)
    {
        parent::__construct($message);
    }

    public static function missing(string $target, string $id): self
    {
        return new self("{$target} expects an answer for question [{$id}], the outcome has none.", $id);
    }

    public static function type(string $target, string $id, string $expected, string $actual): self
    {
        return new self("{$target} expects a {$expected} for question [{$id}], got {$actual}.", $id);
    }

    public static function enumCase(string $target, string $id, string $enum, string $choice): self
    {
        return new self("{$target} expects a case of {$enum} for question [{$id}], got [{$choice}].", $id);
    }
}
