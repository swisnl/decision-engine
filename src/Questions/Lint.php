<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

use Swis\DecisionEngine\State\State;
use Swis\DecisionEngine\Support\Json;

/**
 * Advisory checks derived from Jev's known jaggedness (literal reading, no arithmetic, distraction
 * by irrelevant state). Non-throwing; never runs automatically.
 *
 * ```php
 * foreach (Decision::for($state)->score('age', 'How old?', ['under 18', '18 to 65', 'over 65'])->lint() as $warning) {
 *     echo $warning; // [age] Level descriptions contain numbers; the model does not compare or count. Extract values in code instead.
 * }
 * ```
 */
final class Lint
{
    public const MAX_QUESTIONS = 20;

    public const MAX_STATE_BYTES = 64 * 1024;

    private const CATCH_ALL_OPTIONS = ['other', 'none', 'unknown', 'unclear', 'n/a', 'not_applicable', 'neither', 'no_match'];

    /**
     * @return list<Warning>
     */
    public static function run(QuestionSet $questions, State $state, int $maxQuestions = self::MAX_QUESTIONS, int $maxStateBytes = self::MAX_STATE_BYTES): array
    {
        $warnings = [];

        if (count($questions) > $maxQuestions) {
            $warnings[] = new Warning('too_many_questions', count($questions) . ' questions in one request; consider splitting once you approach the token limits (32k for state + longest question, 64k total).');
        }

        $bytes = $state->byteLength();

        if ($bytes > $maxStateBytes) {
            $warnings[] = new Warning('large_state', "State is {$bytes} bytes; irrelevant state distracts the model and costs tokens. Send only the fields the questions need (State::only()).");
        }

        foreach ($questions as $id => $question) {
            if ($question instanceof RawQuestion) {
                continue;
            }

            $instructions = match (true) {
                $question instanceof Choice, $question instanceof Score, $question instanceof Noul => $question->instructions(),
                default => null,
            };

            if ($instructions === null || $instructions === '') {
                $warnings[] = new Warning('missing_instructions', 'No instructions given; the model reads questions literally, so say exactly what to evaluate.', $id);
            }

            if ($question instanceof Choice) {
                $warnings = [...$warnings, ...self::lintChoice($id, $question)];
            }

            if ($question instanceof Score) {
                $warnings = [...$warnings, ...self::lintScore($id, $question)];
            }
        }

        return $warnings;
    }

    /**
     * @return list<Warning>
     */
    private static function lintChoice(string $id, Choice $choice): array
    {
        $warnings = [];
        $names = array_map(strtolower(...), $choice->optionNames());

        if ($choice->count() >= 3 && array_intersect($names, self::CATCH_ALL_OPTIONS) === []) {
            $warnings[] = new Warning('no_catch_all_option', 'No catch-all option (e.g. `other`); the model must pick one of the given options even when none fits, which shows up as low confidence rather than a clear signal.', $id);
        }

        $seen = [];

        foreach ($choice->criteria() as $name => $description) {
            $key = Json::canonical($description);

            if ($description !== null && isset($seen[$key])) {
                $warnings[] = new Warning('duplicate_option_description', "Options `{$seen[$key]}` and `{$name}` have identical descriptions; the model cannot tell them apart.", $id);
            }

            $seen[$key] ??= $name;

            if (self::containsNumber($description)) {
                $warnings[] = new Warning('numbers_in_criteria', "Option `{$name}` relies on numbers; the model does not compare, count or do arithmetic. Extract values in code and pass a pre-computed label instead.", $id);
            }
        }

        return $warnings;
    }

    /**
     * @return list<Warning>
     */
    private static function lintScore(string $id, Score $score): array
    {
        foreach ($score->criteria() as $description) {
            if (self::containsNumber($description)) {
                return [new Warning('numbers_in_criteria', 'Level descriptions contain numbers; the model does not compare or count. Extract values in code instead.', $id)];
            }
        }

        return [];
    }

    private static function containsNumber(mixed $description): bool
    {
        if ($description === null) {
            return false;
        }

        $text = is_string($description) ? $description : Json::encode($description);

        return preg_match('/(?<![\w#])\d+(\.\d+)?\s*(%|days?|weeks?|months?|years?|hours?|minutes?|eur|usd|€|\$|k\b|x\b|times|or more|or less|\+)/i', $text) === 1
            || preg_match('/\b(more|less|greater|fewer|at least|at most|over|under|above|below)\s+(than\s+)?\d/i', $text) === 1;
    }
}
