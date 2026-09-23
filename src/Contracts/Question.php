<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Contracts;

use Swis\DecisionEngine\Questions\QuestionType;

/**
 * A typed question evaluated against the state.
 *
 * `toArray()` returns exactly the TypeSafe wire shape for a single question,
 * without its id: `{"type": "...", "instructions": ..., "criteria": ...}`.
 *
 * ```php
 * $question = Choice::make('department', 'Which team?')->option('billing', 'Charges and invoices');
 * $question->toArray(); // ['type' => 'choice', 'instructions' => 'Which team?', 'criteria' => ['billing' => 'Charges and invoices']]
 * ```
 */
interface Question extends Arrayable
{
    public function id(): string;

    public function type(): QuestionType;

    /**
     * Rebuild the question from its wire form.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $id, array $data): static;
}
