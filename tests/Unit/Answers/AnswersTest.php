<?php

declare(strict_types=1);

use Swis\DecisionEngine\Answers\AnswerFactory;
use Swis\DecisionEngine\Answers\ChoiceAnswer;
use Swis\DecisionEngine\Answers\GenericAnswer;
use Swis\DecisionEngine\Answers\NoulAnswer;
use Swis\DecisionEngine\Answers\ScoreAnswer;
use Swis\DecisionEngine\Outcome\Certainty;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Questions\QuestionType;

describe('ChoiceAnswer', function (): void {
    $raw = ['type' => 'choice', 'choice' => 'billing', 'confidence' => 0.82, 'probabilities' => ['returns' => 0.05, 'shipping' => 0.04, 'billing' => 0.91]];

    it('exposes the documented fields and helpers', function () use ($raw): void {
        $a = ChoiceAnswer::fromArray('department', $raw);

        expect($a->id())->toBe('department')
            ->and($a->type())->toBe(QuestionType::Choice)
            ->and($a->choice)->toBe('billing')
            ->and($a->confidence)->toBe(0.82)
            ->and($a->is('billing'))->toBeTrue()
            ->and($a->probability('returns'))->toBe(0.05)
            ->and($a->probability('missing'))->toBe(0.0)
            ->and($a->ranked())->toBe(['billing' => 0.91, 'returns' => 0.05, 'shipping' => 0.04])
            ->and($a->top(2))->toBe(['billing' => 0.91, 'returns' => 0.05])
            ->and($a->options())->toBe(['returns', 'shipping', 'billing'])
            ->and($a->isConfident(0.9))->toBeFalse()
            ->and($a->isConfident(0.8))->toBeTrue()
            ->and($a->certainty())->toBe(0.82)
            ->and($a->band())->toBe(Certainty::Medium)
            ->and($a->band(new Thresholds(high: 0.8, medium: 0.5)))->toBe(Certainty::High)
            ->and($a->withThresholds(new Thresholds(0.8, 0.5))->band())->toBe(Certainty::High)
            ->and($a->raw())->toBe($raw)
            ->and($a->toArray())->toBe($raw)
            ->and($a->get('choice'))->toBe('billing')
            ->and($a->get('nope', 'dflt'))->toBe('dflt');
    });

    it('requires choice, probabilities and confidence', function (): void {
        expect(fn() => ChoiceAnswer::fromArray('x', ['type' => 'choice', 'probabilities' => [], 'confidence' => 1.0]))->toThrow(InvalidArgumentException::class, 'answers.x.choice')
            ->and(fn() => ChoiceAnswer::fromArray('x', ['type' => 'choice', 'choice' => 'a', 'confidence' => 1.0]))->toThrow(InvalidArgumentException::class, 'answers.x.probabilities')
            ->and(fn() => ChoiceAnswer::fromArray('x', ['type' => 'choice', 'choice' => 'a', 'probabilities' => ['a' => 'no']]))->toThrow(InvalidArgumentException::class, 'answers.x.probabilities.a')
            ->and(fn() => ChoiceAnswer::fromArray('x', ['type' => 'choice', 'choice' => 'a', 'probabilities' => ['a' => 1]]))->toThrow(InvalidArgumentException::class, 'answers.x.confidence');
    });
});

describe('ScoreAnswer', function (): void {
    $raw = [
        'type' => 'score', 'score' => 1.43, 'confidence' => 0.35,
        'legend' => ['0' => 'Cosmetic', '1' => 'Degraded', '2' => 'Blocking'],
        'probabilities' => ['0' => 0.0, '1' => 0.57, '2' => 0.43],
    ];

    it('exposes the documented fields and helpers', function () use ($raw): void {
        $a = ScoreAnswer::fromArray('severity', $raw);

        expect($a->score)->toBe(1.43)
            ->and($a->levels())->toBe(3)
            ->and(round($a->normalized(), 3))->toBe(0.715)
            ->and($a->level())->toBe(1)
            ->and($a->levelDescription())->toBe('Degraded')
            ->and($a->levelDescription(2))->toBe('Blocking')
            ->and($a->probability(2))->toBe(0.43)
            ->and(round($a->probabilityAtLeast(1), 2))->toBe(1.0)
            ->and($a->probabilityAtLeast(2))->toBe(0.43)
            ->and($a->atLeast(1.5))->toBeFalse()
            ->and($a->atLeast(1.4))->toBeTrue()
            ->and($a->confidence)->toBe(0.35)
            ->and($a->band())->toBe(Certainty::Low)
            ->and($a->legend)->toBe([0 => 'Cosmetic', 1 => 'Degraded', 2 => 'Blocking'])
            ->and($a->probabilities)->toBe([0 => 0.0, 1 => 0.57, 2 => 0.43]);
    });

    it('uses argmax for level(), not rounding, on bimodal distributions', function (): void {
        $a = ScoreAnswer::fromArray('s', ['type' => 'score', 'score' => 1.0, 'confidence' => 0.1, 'legend' => [], 'probabilities' => ['0' => 0.45, '1' => 0.1, '2' => 0.45]]);

        expect($a->level())->toBe(0)->and($a->normalized())->toBe(0.5);
    });

    it('sorts levels numerically even when the server sends them out of order', function (): void {
        $a = ScoreAnswer::fromArray('s', ['type' => 'score', 'score' => 1.0, 'confidence' => 1.0, 'probabilities' => ['2' => 0.0, '0' => 0.0, '1' => 1.0]]);

        expect(array_keys($a->probabilities))->toBe([0, 1, 2]);
    });
});

describe('NoulAnswer', function (): void {
    it('exposes thresholds-based helpers and derived certainty', function (): void {
        $a = NoulAnswer::fromArray('wants_human', ['type' => 'noul', 'noul' => 0.84]);

        expect($a->noul)->toBe(0.84)
            ->and($a->type())->toBe(QuestionType::Noul)
            ->and($a->isTrue())->toBeTrue()
            ->and($a->isTrue(0.9))->toBeFalse()
            ->and($a->isFalse())->toBeFalse()
            ->and($a->isFalse(0.1))->toBeTrue()
            ->and($a->isUncertain(0.2, 0.8))->toBeFalse()
            ->and($a->isUncertain(0.2, 0.9))->toBeTrue()
            ->and($a->toBool())->toBeTrue()
            ->and(round($a->complement(), 2))->toBe(0.16)
            ->and(round($a->certainty(), 2))->toBe(0.68)
            ->and($a->band())->toBe(Certainty::Medium)
            ->and(NoulAnswer::fromArray('x', ['type' => 'noul', 'noul' => 0.99])->band())->toBe(Certainty::High)
            ->and(NoulAnswer::fromArray('x', ['type' => 'noul', 'noul' => 0.6])->band())->toBe(Certainty::Low);
    });

    it('requires noul', function (): void {
        NoulAnswer::fromArray('x', ['type' => 'noul']);
    })->throws(InvalidArgumentException::class, 'answers.x.noul');
});

describe('GenericAnswer / AnswerFactory', function (): void {
    it('preserves unknown answer types and reads confidence when present', function (): void {
        $raw = ['type' => 'vector', 'values' => [0.1, 0.2], 'confidence' => 0.5];
        $a = AnswerFactory::fromArray('mystery', $raw);

        expect($a)->toBeInstanceOf(GenericAnswer::class)
            ->and($a->type())->toBeNull()
            ->and($a->raw())->toBe($raw)
            ->and($a->get('values'))->toBe([0.1, 0.2])
            ->and($a->certainty())->toBe(0.5)
            ->and($a->band())->toBe(Certainty::Medium)
            ->and(AnswerFactory::fromArray('x', ['type' => 'vector', 'confidence' => 'bad'])->certainty())->toBe(0.0)
            ->and(AnswerFactory::fromArray('x', [])->certainty())->toBe(0.0);

        expect($a instanceof GenericAnswer ? $a->rawType() : null)->toBe('vector');
    });

    it('dispatches on type', function (): void {
        expect(AnswerFactory::fromArray('a', ['type' => 'choice', 'choice' => 'x', 'probabilities' => ['x' => 1], 'confidence' => 1]))->toBeInstanceOf(ChoiceAnswer::class)
            ->and(AnswerFactory::fromArray('b', ['type' => 'score', 'score' => 0, 'probabilities' => ['0' => 1], 'confidence' => 1]))->toBeInstanceOf(ScoreAnswer::class)
            ->and(AnswerFactory::fromArray('c', ['type' => 'noul', 'noul' => 1]))->toBeInstanceOf(NoulAnswer::class);
    });

    it('builds a whole answers map and rejects non-object entries', function (): void {
        expect(AnswerFactory::fromAnswers(['a' => ['type' => 'noul', 'noul' => 0.5]]))->toHaveKey('a');
        expect(fn() => AnswerFactory::fromAnswers(['a' => 'x']))->toThrow(InvalidArgumentException::class, 'answers.a must be an object');
    });
});

describe('Thresholds', function (): void {
    it('bands values and rejects inverted thresholds', function (): void {
        $t = Thresholds::fromArray(['high' => 0.95]);

        expect($t->band(0.96))->toBe(Certainty::High)
            ->and($t->band(0.95))->toBe(Certainty::Medium)
            ->and($t->band(0.5))->toBe(Certainty::Medium)
            ->and($t->band(0.49))->toBe(Certainty::Low)
            ->and($t->toArray())->toBe(['high' => 0.95, 'medium' => 0.5])
            ->and(Certainty::High->atLeast(Certainty::Medium))->toBeTrue()
            ->and(Certainty::Low->atLeast(Certainty::Medium))->toBeFalse();

        expect(fn() => new Thresholds(high: 0.4, medium: 0.5))->toThrow(InvalidArgumentException::class);
    });
});
