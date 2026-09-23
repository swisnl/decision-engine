<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Testing;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\QuestionType;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Support\Math;

/**
 * A declarative fake answer that is rendered against the actual question at decision time, so the
 * probabilities are consistent with the requested `choice` / `score` / `confidence`.
 * Build these through `Fake::choice()`, `Fake::score()`, `Fake::noul()`.
 */
final class FakeAnswer
{
    /**
     * @param  array<string, mixed>  $extra  merged into the rendered answer (e.g. future fields)
     * @param  list<string>|null  $options  option names when the question is unknown
     */
    public function __construct(
        public readonly QuestionType $type,
        public readonly string|float|null $value,
        public readonly ?float $confidence = null,
        public readonly ?int $levels = null,
        public readonly ?array $options = null,
        public readonly array $extra = [],
    ) {}

    /**
     * Render the wire-format answer for the given question (null when the question is unknown).
     *
     * @return array<string, mixed>
     */
    public function render(?Question $question): array
    {
        return match ($this->type) {
            QuestionType::Choice => $this->renderChoice($question),
            QuestionType::Score => $this->renderScore($question),
            QuestionType::Noul => ['type' => 'noul', 'noul' => Math::clamp((float) ($this->value ?? 0.5))] + $this->extra,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function renderChoice(?Question $question): array
    {
        $choice = (string) ($this->value ?? '');
        $options = $this->options ?? ($question instanceof Choice ? $question->optionNames() : []);

        if (! in_array($choice, $options, true)) {
            $options[] = $choice;
        }

        $n = count($options);
        $confidence = Math::clamp($this->confidence ?? 0.9);
        $pMax = $n === 1 ? 1.0 : ($confidence * ($n - 1) + 1) / $n;
        $rest = $n === 1 ? 0.0 : round((1 - $pMax) / ($n - 1), 6);
        $pMax = 1.0 - $rest * ($n - 1); // the chosen option absorbs the rounding so the sum is exactly 1

        $probabilities = [];

        foreach ($options as $option) {
            $probabilities[$option] = $option === $choice ? $pMax : $rest;
        }

        return ['type' => 'choice', 'choice' => $choice, 'confidence' => round(Math::confidence($n, $pMax), 6), 'probabilities' => $probabilities] + $this->extra;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderScore(?Question $question): array
    {
        $criteria = $question instanceof Score ? $question->criteria() : [];
        $levels = $this->levels ?? ($criteria === [] ? 2 : count($criteria));
        $levels = max(2, $levels);
        $score = Math::clamp((float) ($this->value ?? 0.0), 0.0, (float) ($levels - 1));

        $floor = (int) floor($score);
        $ceil = min($levels - 1, $floor + 1);
        $upper = $score - $floor;

        $probabilities = [];
        $legend = [];

        for ($i = 0; $i < $levels; $i++) {
            $probabilities[(string) $i] = round(match (true) {
                $i === $floor && $i === $ceil => 1.0,
                $i === $floor => 1.0 - $upper,
                $i === $ceil => $upper,
                default => 0.0,
            }, 6);
            $legend[(string) $i] = $criteria[$i] ?? "Level {$i}";
        }

        $confidence = $this->confidence ?? Math::confidence($levels, $floor === $ceil ? 1.0 : max(1.0 - $upper, $upper));

        return ['type' => 'score', 'score' => round($score, 6), 'confidence' => round(Math::clamp($confidence), 6), 'legend' => $legend, 'probabilities' => $probabilities] + $this->extra;
    }
}
