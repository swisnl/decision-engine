<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Typed;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Outcome\Outcome;

/**
 * A class that declares its questions with attributes and receives the answers as typed
 * properties: one definition for the questions, any engine and PHPStan-clean access.
 *
 * ```php
 * final class TicketTriage extends TypedOutcome
 * {
 *     #[Choice('Which team should handle this?')]
 *     public readonly Department $department;             // backed enum: options from its cases
 *
 *     #[Score('How severe is the issue?', levels: ['Cosmetic', 'Degraded', 'Blocking'])]
 *     public readonly ScoreAnswer $severity;
 *
 *     #[Noul('Is the customer asking for a human agent?')]
 *     public readonly float $wants_human;                  // probability of yes
 * }
 *
 * $triage = Decision::for($state)->decideAs(TicketTriage::class);
 * $triage->department;                 // Department::Billing
 * $triage->outcome()->usage;           // the underlying Outcome
 * Decision::for($state)->ask(...TicketTriage::questions())->lint();
 * ```
 *
 * Mapping is strict: a missing answer, another answer type or a choice that is not an enum case
 * throws OutcomeMismatchException. Missing answers are re-asked first (`retry.invalid_responses`).
 */
abstract class TypedOutcome implements \JsonSerializable
{
    private Outcome $outcome;

    /**
     * The questions this class declares, in property order.
     *
     * @return list<Question>
     */
    final public static function questions(): array
    {
        return Schema::for(static::class)->questions();
    }

    /**
     * Map an Outcome onto a new instance (constructors are not called).
     */
    final public static function fromOutcome(Outcome $outcome): static
    {
        $instance = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        Schema::for(static::class)->hydrate($instance, $outcome);
        $instance->outcome = $outcome;

        return $instance;
    }

    /**
     * @param  array<string, mixed>  $data  an `Outcome::toArray()` / `toArray()` of this class
     */
    public static function fromArray(array $data): static
    {
        return static::fromOutcome(Outcome::fromArray($data));
    }

    final public function outcome(): Outcome
    {
        return $this->outcome;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->outcome->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
