<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines\Llm;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Questions\RawQuestion;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Serialization\QuestionFactory;

/**
 * Builds the strict JSON schema a structured-output model must fill: one required property per
 * question id, every option / level required, `additionalProperties: false` everywhere.
 *
 * `$numericConstraints` adds `minimum: 0` / `maximum: 1` where the provider's strict mode allows
 * it (OpenAI yes, Anthropic no); values are clamped client-side regardless.
 *
 * ```php
 * SchemaBuilder::build($questions, numericConstraints: true);
 * // ['type' => 'object', 'properties' => (object) ['department' => [...]], 'required' => ['department'], 'additionalProperties' => false]
 * ```
 */
final class SchemaBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(QuestionSet $questions, bool $numericConstraints): array
    {
        $properties = [];

        foreach ($questions as $id => $question) {
            $properties[$id] = self::forQuestion($id, self::resolve($id, $question), $numericConstraints);
        }

        return self::object($properties, array_map(strval(...), array_keys($properties)));
    }

    /**
     * Raw questions of a known type are upgraded to their typed class so options/levels are known.
     * Unknown types cannot be expressed as a schema and are rejected for structured-output engines.
     */
    public static function resolve(string $id, Question $question): Question
    {
        if (! $question instanceof RawQuestion) {
            return $question;
        }

        if (! $question->isKnownType()) {
            throw InvalidDecisionException::single($id, 'Structured-output engines only support the choice, score and noul question types; got [' . ($question->rawType() ?? 'null') . '].');
        }

        return QuestionFactory::fromArray($id, $question->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private static function forQuestion(string $id, Question $question, bool $numericConstraints): array
    {
        $probability = ['type' => 'number'] + ($numericConstraints ? ['minimum' => 0, 'maximum' => 1] : []);

        if ($question instanceof Choice) {
            $keys = $question->optionNames();

            return self::object(['probabilities' => self::object(array_fill_keys($keys, $probability), $keys, 'Probability of each option; must sum to 1.')], ['probabilities']);
        }

        if ($question instanceof Score) {
            $keys = array_map(strval(...), range(0, max(0, $question->count() - 1)));

            return self::object(['probabilities' => self::object(array_fill_keys($keys, $probability), $keys, 'Probability of each level index (0 = lowest); must sum to 1.')], ['probabilities']);
        }

        if ($question instanceof Noul) {
            return self::object(['noul' => $probability + ['description' => 'Probability that the answer is yes.']], ['noul']);
        }

        throw InvalidDecisionException::single($id, 'Unsupported question class ' . $question::class . ' for structured-output engines.');
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required, ?string $description = null): array
    {
        $schema = ['type' => 'object'];

        if ($description !== null) {
            $schema['description'] = $description;
        }

        // An object even when the keys are numeric ("0", "1", … for score levels): PHP turns such
        // arrays into lists, which json_encode would render as a JSON array.
        $schema['properties'] = (object) $properties;
        $schema['required'] = $required;
        $schema['additionalProperties'] = false;

        return $schema;
    }
}
