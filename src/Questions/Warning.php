<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

/**
 * An advisory finding from `Decision::lint()`. Never blocks a request.
 */
final class Warning
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $questionId = null,
    ) {}

    public function __toString(): string
    {
        return ($this->questionId === null ? '' : "[{$this->questionId}] ") . $this->message;
    }
}
