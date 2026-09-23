<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Serialization;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\QuestionType;
use Swis\DecisionEngine\Questions\RawQuestion;
use Swis\DecisionEngine\Questions\Score;

/**
 * Builds the right Question object from a wire array, using `type` as discriminator.
 * Unknown or missing types become RawQuestion so nothing is ever dropped.
 *
 * ```php
 * QuestionFactory::fromArray('urgent', ['type' => 'noul', 'instructions' => 'Is this urgent?']); // Noul
 * QuestionFactory::fromArray('x', ['type' => 'future']);                                       // RawQuestion
 * ```
 */
final class QuestionFactory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $id, array $data): Question
    {
        $type = $data['type'] ?? null;
        $known = is_string($type) ? QuestionType::tryFrom($type) : null;

        return match ($known) {
            QuestionType::Choice => Choice::fromArray($id, $data),
            QuestionType::Score => Score::fromArray($id, $data),
            QuestionType::Noul => Noul::fromArray($id, $data),
            null => RawQuestion::fromArray($id, $data),
        };
    }
}
