<?php

declare(strict_types=1);

use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Description;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Questions\QuestionType;
use Swis\DecisionEngine\Questions\RawQuestion;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Serialization\QuestionFactory;

describe('Choice', function (): void {
    it('builds the wire form from the fluent API', function (): void {
        $q = Choice::make('department', 'Which team should handle this?')
            ->option('returns', what: 'Exchanges, wrong or damaged items', notFor: 'Refund requests', examples: ['wrong size', 'arrived broken'])
            ->option('billing', 'Charges, invoices, payment problems')
            ->option('other');

        expect($q->id())->toBe('department')
            ->and($q->type())->toBe(QuestionType::Choice)
            ->and($q->optionNames())->toBe(['returns', 'billing', 'other'])
            ->and($q->toArray())->toBe([
                'type' => 'choice',
                'instructions' => 'Which team should handle this?',
                'criteria' => [
                    'returns' => ['what' => 'Exchanges, wrong or damaged items', 'not_for' => 'Refund requests', 'examples' => ['wrong size', 'arrived broken']],
                    'billing' => 'Charges, invoices, payment problems',
                    'other' => null,
                ],
            ]);
    });

    it('accepts an options map in make()', function (): void {
        $q = Choice::make('lang', 'Language?', ['nl' => null, 'en' => null]);

        expect($q->count())->toBe(2)->and($q->hasOption('nl'))->toBeTrue();
    });

    it('is immutable', function (): void {
        $a = Choice::make('x', 'q')->option('a');
        $b = $a->option('b');

        expect($a->count())->toBe(1)->and($b->count())->toBe(2);
    });

    it('preserves unknown wire fields', function (): void {
        $q = Choice::fromArray('x', ['type' => 'choice', 'instructions' => 'q', 'criteria' => ['a' => null], 'future' => 1]);

        expect($q->toArray())->toBe(['type' => 'choice', 'instructions' => 'q', 'criteria' => ['a' => null], 'future' => 1]);
    });

    it('omits null instructions', function (): void {
        expect(Choice::make('x')->option('a')->toArray())->toBe(['type' => 'choice', 'criteria' => ['a' => null]]);
    });

    it('refuses to be built from another type', function (): void {
        Choice::fromArray('x', ['type' => 'score']);
    })->throws(InvalidArgumentException::class);
});

describe('Score', function (): void {
    it('builds ordered levels', function (): void {
        $q = Score::make('severity', 'How severe?')
            ->level('Cosmetic')
            ->level(what: 'Blocking', examples: ['data loss'])
            ->level(what: 'Fatal', signals: ['fire']);

        expect($q->toArray())->toBe([
            'type' => 'score',
            'instructions' => 'How severe?',
            'criteria' => ['Cosmetic', ['what' => 'Blocking', 'examples' => ['data loss']], ['what' => 'Fatal', 'signals' => ['fire']]],
        ]);
    });

    it('re-indexes levels given as a non-list array', function (): void {
        expect(Score::make('s', 'q', [3 => 'a', 7 => 'b'])->criteria())->toBe(['a', 'b']);
    });
});

describe('Noul', function (): void {
    it('omits criteria when none given', function (): void {
        expect(Noul::make('urgent', 'Is this urgent?')->toArray())->toBe(['type' => 'noul', 'instructions' => 'Is this urgent?']);
    });

    it('supports structured instructions and true/false criteria', function (): void {
        $q = Noul::make('same')
            ->withInstructions(['record' => ['id' => 1], 'question' => 'Same person?'])
            ->criteria(true: 'Mentions a prior ticket', false: null);

        expect($q->toArray())->toBe([
            'type' => 'noul',
            'instructions' => ['record' => ['id' => 1], 'question' => 'Same person?'],
            'criteria' => ['true' => 'Mentions a prior ticket'],
        ]);
    });

    it('drops criteria entirely when both sides are null', function (): void {
        expect(Noul::make('x', 'q')->criteria()->getCriteria())->toBeNull();
    });
});

describe('RawQuestion', function (): void {
    it('passes everything through untouched', function (): void {
        $data = ['type' => 'choice', 'instructions' => 'q', 'criteria' => ['a' => 1], 'future_field' => 1];

        expect(RawQuestion::make('r', $data)->toArray())->toBe($data)
            ->and(RawQuestion::make('r', $data)->type())->toBe(QuestionType::Choice);
    });

    it('reports unknown types without throwing on rawType()', function (): void {
        $q = RawQuestion::make('r', ['type' => 'vector']);

        expect($q->rawType())->toBe('vector')->and($q->isKnownType())->toBeFalse();
        expect(fn() => $q->type())->toThrow(LogicException::class);
    });
});

describe('Description', function (): void {
    it('keeps a plain value plain', function (): void {
        expect(Description::make('x'))->toBe('x')->and(Description::make(null))->toBeNull();
    });

    it('stringifies numbers, booleans, Stringable and unwraps JsonSerializable', function (): void {
        $json = new class implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['k' => 'v'];
            }
        };

        expect(Description::normalize(3))->toBe('3')
            ->and(Description::normalize(true))->toBe('true')
            ->and(Description::normalize(new class implements Stringable {
                public function __toString(): string
                {
                    return 'str';
                }
            }))->toBe('str')
            ->and(Description::normalize($json))->toBe(['k' => 'v']);
    });
});

describe('QuestionFactory / QuestionSet', function (): void {
    it('dispatches on type and falls back to RawQuestion', function (): void {
        expect(QuestionFactory::fromArray('a', ['type' => 'choice', 'criteria' => ['x' => null]]))->toBeInstanceOf(Choice::class)
            ->and(QuestionFactory::fromArray('b', ['type' => 'score', 'criteria' => ['x', 'y']]))->toBeInstanceOf(Score::class)
            ->and(QuestionFactory::fromArray('c', ['type' => 'noul']))->toBeInstanceOf(Noul::class)
            ->and(QuestionFactory::fromArray('d', ['type' => 'future']))->toBeInstanceOf(RawQuestion::class)
            ->and(QuestionFactory::fromArray('e', []))->toBeInstanceOf(RawQuestion::class);
    });

    it('keeps insertion order and replaces on duplicate id', function (): void {
        $set = QuestionSet::of(Noul::make('a', '1'), Noul::make('b', '2'))->with(Noul::make('a', '3'));

        expect($set->ids())->toBe(['a', 'b'])
            ->and($set->get('a')->toArray()['instructions'])->toBe('3')
            ->and(count($set))->toBe(2)
            ->and($set->without('a')->ids())->toBe(['b'])
            ->and($set->only(['b'])->ids())->toBe(['b']);
    });

    it('throws on unknown id', function (): void {
        QuestionSet::empty()->get('nope');
    })->throws(OutOfBoundsException::class);
});
