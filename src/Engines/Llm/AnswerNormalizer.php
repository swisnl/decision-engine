<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines\Llm;

use Swis\DecisionEngine\Answers\MalformedAnswerException;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Support\Json;
use Swis\DecisionEngine\Support\Math;

/**
 * Turns the model-filled schema object into Jev-shaped answers: clamp to [0, 1], renormalize to
 * sum 1 (uniform when everything is 0), derive `choice` (argmax, first on tie), `score`
 * (Σ level × p), `legend` and `confidence` with the shared formulas.
 *
 * ```php
 * AnswerNormalizer::normalize($questions, ['department' => ['probabilities' => ['billing' => 0.9, 'other' => 0.3]]]);
 * // ['department' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 0.5, 'probabilities' => ['billing' => 0.75, 'other' => 0.25]]]
 * ```
 */
final class AnswerNormalizer
{
    /**
     * @param  array<array-key, mixed>  $output  decoded structured output, keyed by question id
     * @return array<string, array<string, mixed>>
     *
     * @throws MalformedAnswerException when a question has no usable answer
     */
    public static function normalize(QuestionSet $questions, array $output): array
    {
        $answers = [];

        foreach ($questions as $id => $question) {
            $raw = $output[$id] ?? null;

            if (! is_array($raw)) {
                throw MalformedAnswerException::required("answers.{$id}");
            }

            $question = SchemaBuilder::resolve($id, $question);

            $answers[$id] = match (true) {
                $question instanceof Choice => self::choice($id, $question, $raw),
                $question instanceof Score => self::score($id, $question, $raw),
                $question instanceof Noul => self::noul($id, $raw),
                default => throw MalformedAnswerException::type("answers.{$id}", 'a choice, score or noul answer'),
            };
        }

        return $answers;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function choice(string $id, Choice $question, array $raw): array
    {
        $options = $question->optionNames();
        $probabilities = self::distribution("answers.{$id}.probabilities", $raw['probabilities'] ?? null, $options);
        /** @var non-empty-array<string, float> $probabilities */
        $choice = (string) Math::argmax($probabilities);

        return [
            'type' => 'choice',
            'choice' => $choice,
            'confidence' => self::round(Math::confidenceOf($probabilities)),
            'probabilities' => array_map(self::round(...), $probabilities),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function score(string $id, Score $question, array $raw): array
    {
        $levels = array_map(strval(...), range(0, max(0, $question->count() - 1)));
        $probabilities = self::distribution("answers.{$id}.probabilities", $raw['probabilities'] ?? null, $levels);
        /** @var non-empty-array<string, float> $probabilities */
        $legend = [];

        foreach ($question->criteria() as $index => $description) {
            $legend[(string) $index] = $description;
        }

        return [
            'type' => 'score',
            'score' => self::round(Math::expectedScore($probabilities)),
            'confidence' => self::round(Math::confidenceOf($probabilities)),
            'legend' => $legend,
            'probabilities' => array_map(self::round(...), $probabilities),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function noul(string $id, array $raw): array
    {
        $value = $raw['noul'] ?? null;

        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }

        if (is_bool($value)) {
            $value = $value ? 1.0 : 0.0;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw MalformedAnswerException::type("answers.{$id}.noul", 'a number');
        }

        return ['type' => 'noul', 'noul' => self::round(Math::clamp((float) $value))];
    }

    /**
     * Read the reported probabilities for exactly `$keys`: unknown keys are dropped, missing keys
     * count as 0, values are clamped and the result renormalized.
     *
     * @param  list<string>  $keys
     * @return array<string, float>
     */
    private static function distribution(string $path, mixed $reported, array $keys): array
    {
        if ($keys === []) {
            throw MalformedAnswerException::type($path, 'a distribution over at least one option');
        }

        if (! is_array($reported)) {
            throw MalformedAnswerException::required($path);
        }

        $values = [];

        foreach ($keys as $key) {
            $value = $reported[$key] ?? 0.0;

            if (is_string($value) && is_numeric($value)) {
                $value = (float) $value;
            }

            if (! is_int($value) && ! is_float($value)) {
                throw MalformedAnswerException::type("{$path}.{$key}", 'a number, got ' . Json::encode($value));
            }

            $values[$key] = (float) $value;
        }

        return Math::normalize($values);
    }

    private static function round(float $value): float
    {
        return round($value, 6);
    }
}
