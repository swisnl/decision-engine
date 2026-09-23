<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Answers;

use Swis\DecisionEngine\Contracts\Answer;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Questions\QuestionType;

/**
 * Builds Answer objects from decoded answer arrays, using `type` as discriminator.
 * Unknown types become GenericAnswer. Known types with missing required fields throw an
 * \InvalidArgumentException whose message is the dotted field path (engines translate that into
 * a ResponseValidationException).
 *
 * ```php
 * AnswerFactory::fromArray('urgent', ['type' => 'noul', 'noul' => 0.99]); // NoulAnswer
 * AnswerFactory::fromArray('x', ['type' => 'vector']);                    // GenericAnswer
 * ```
 */
final class AnswerFactory
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(string $id, array $raw, ?Thresholds $thresholds = null): Answer
    {
        $type = $raw['type'] ?? null;
        $known = is_string($type) ? QuestionType::tryFrom($type) : null;

        try {
            return match ($known) {
                QuestionType::Choice => ChoiceAnswer::fromArray($id, $raw, $thresholds),
                QuestionType::Score => ScoreAnswer::fromArray($id, $raw, $thresholds),
                QuestionType::Noul => NoulAnswer::fromArray($id, $raw, $thresholds),
                null => new GenericAnswer($id, $raw, $thresholds ?? new Thresholds()),
            };
        } catch (MalformedAnswerException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            // Arr::float()/Arr::array() report "Expected [field] to be …"; attach the answer path.
            preg_match('/Expected \\[([^\\]]+)\\] to be (.+)\\./', $e->getMessage(), $m);

            throw new MalformedAnswerException("answers.{$id}" . (isset($m[1]) ? ".{$m[1]}" : ''), isset($m[2]) ? "must be {$m[2]}." : $e->getMessage());
        }
    }

    /**
     * Build a whole answers map.
     *
     * @param  array<array-key, mixed>  $answers
     * @return array<string, Answer>
     */
    public static function fromAnswers(array $answers, ?Thresholds $thresholds = null): array
    {
        $result = [];

        foreach ($answers as $id => $raw) {
            if (! is_array($raw)) {
                throw MalformedAnswerException::type("answers.{$id}", 'an object');
            }

            /** @var array<string, mixed> $raw */
            $result[(string) $id] = self::fromArray((string) $id, $raw, $thresholds);
        }

        return $result;
    }
}
