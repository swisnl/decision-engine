<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

/**
 * Thrown by client-side validation before any I/O happens, and by `fromArray()` when the
 * given array cannot be interpreted.
 *
 * ```php
 * try {
 *     Decision::for($state)->score('x', 'How bad?', ['only one level'])->decide();
 * } catch (InvalidDecisionException $e) {
 *     $e->errors(); // ['x' => ['A score question needs between 2 and 10 levels, 1 given.']]
 * }
 * ```
 */
final class InvalidDecisionException extends \InvalidArgumentException implements DecisionEngineException
{
    /**
     * @param  array<string, list<string>>  $errors  keyed by question id (or a path such as `state` / `questions`)
     */
    public function __construct(
        private readonly array $errors,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?? self::summarize($errors), 0, $previous);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function errorsFor(string $key): array
    {
        return $this->errors[$key] ?? [];
    }

    public static function single(string $key, string $message): self
    {
        return new self([$key => [$message]]);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function summarize(array $errors): string
    {
        $lines = [];

        foreach ($errors as $key => $messages) {
            foreach ($messages as $message) {
                $lines[] = "[{$key}] {$message}";
            }
        }

        return $lines === [] ? 'The decision is invalid.' : 'The decision is invalid: ' . implode(' ', $lines);
    }
}
