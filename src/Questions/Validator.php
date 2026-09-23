<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Engines\Capabilities;
use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\State\State;

/**
 * Client-side validation of a question set against an engine's capabilities.
 * Runs before any I/O and collects every error, keyed by question id.
 *
 * ```php
 * Validator::validate($questions, Capabilities::jev(), $state); // throws InvalidDecisionException
 * Validator::errors($questions, Capabilities::jev(), $state);   // ['severity' => ['...']]
 * ```
 */
final class Validator
{
    /**
     * @throws InvalidDecisionException
     */
    public static function validate(QuestionSet $questions, Capabilities $capabilities, ?State $state = null): void
    {
        $errors = self::errors($questions, $capabilities, $state);

        if ($errors !== []) {
            throw new InvalidDecisionException($errors);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public static function errors(QuestionSet $questions, Capabilities $capabilities, ?State $state = null): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        if ($questions->isEmpty()) {
            $errors['questions'] = ['At least one question is required.'];
        }

        if ($state !== null && $state->isNull() && ! $capabilities->supportsNullState) {
            $errors['state'] = ['This engine does not accept a null state; pass \'\' or [] when the questions carry all the context.'];
        }

        foreach ($questions as $id => $question) {
            $messages = self::errorsForQuestion($id, $question, $capabilities);

            if ($messages !== []) {
                $errors[$id] = $messages;
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function errorsForQuestion(string $id, Question $question, Capabilities $capabilities): array
    {
        $messages = [];

        if (trim($id) === '') {
            $messages[] = 'Question ids must be non-empty strings.';
        }

        if ($question instanceof RawQuestion) {
            $type = $question->rawType();

            if ($type === null || $type === '') {
                $messages[] = 'A raw question must declare a non-empty `type`.';
            }

            return $messages;
        }

        if ($question instanceof Choice) {
            $count = $question->count();

            if ($count === 0) {
                $messages[] = 'A choice question needs at least one option.';
            } elseif ($count > $capabilities->maxChoiceOptions) {
                $messages[] = "A choice question supports at most {$capabilities->maxChoiceOptions} options, {$count} given.";
            }

            if ($count > 0 && array_is_list($question->criteria())) {
                $messages[] = 'Choice option names must be strings; a 0-based list of numeric names would be sent as a JSON array. Use a score question for ordered numeric levels.';
            }
        }

        if ($question instanceof Score) {
            $count = $question->count();

            if ($count < $capabilities->minScoreLevels || $count > $capabilities->maxScoreLevels) {
                $messages[] = "A score question needs between {$capabilities->minScoreLevels} and {$capabilities->maxScoreLevels} levels, {$count} given.";
            }
        }

        if ($question instanceof Noul) {
            $unknown = array_diff(array_keys($question->getCriteria() ?? []), ['true', 'false']);

            if ($unknown !== []) {
                $messages[] = 'Noul criteria may only contain the keys `true` and `false`; found: ' . implode(', ', array_map(strval(...), $unknown)) . '.';
            }
        }

        return $messages;
    }
}
