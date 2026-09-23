<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Answers;

/**
 * A known answer type is missing a required field or has a wrongly typed one.
 * `fieldPath` is dotted, e.g. `answers.tone.confidence`. Engines convert this into a
 * ResponseValidationException that also carries the HTTP response.
 */
final class MalformedAnswerException extends \InvalidArgumentException
{
    public function __construct(public readonly string $fieldPath, string $problem)
    {
        parent::__construct("{$fieldPath} {$problem}");
    }

    public static function required(string $fieldPath): self
    {
        return new self($fieldPath, 'is required.');
    }

    public static function type(string $fieldPath, string $expected): self
    {
        return new self($fieldPath, "must be {$expected}.");
    }
}
