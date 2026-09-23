<?php

declare(strict_types=1);

use Swis\DecisionEngine\Engines\Capabilities;
use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Questions\RawQuestion;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Questions\Validator;
use Swis\DecisionEngine\State\State;

it('requires at least one question', function (): void {
    expect(Validator::errors(QuestionSet::empty(), Capabilities::jev()))->toBe(['questions' => ['At least one question is required.']]);
});

it('accepts a valid set', function (): void {
    $set = QuestionSet::of(
        Choice::make('c', 'q', ['a' => null, 'b' => null]),
        Score::make('s', 'q', ['low', 'high']),
        Noul::make('n', 'q'),
        RawQuestion::make('r', ['type' => 'future']),
    );

    expect(Validator::errors($set, Capabilities::jev(), State::from('x')))->toBe([]);
    Validator::validate($set, Capabilities::jev());
});

it('collects every error keyed by id', function (): void {
    $set = QuestionSet::of(
        Choice::make('c', 'q'),
        Score::make('s', 'q', ['only one']),
        RawQuestion::make('r', ['instructions' => 'no type']),
    );

    $errors = Validator::errors($set, Capabilities::jev());

    expect($errors)->toHaveKeys(['c', 's', 'r'])
        ->and($errors['c'][0])->toContain('at least one option')
        ->and($errors['s'][0])->toBe('A score question needs between 2 and 10 levels, 1 given.')
        ->and($errors['r'][0])->toContain('non-empty `type`');
});

it('enforces maximum options and levels from capabilities', function (): void {
    $options = array_fill_keys(array_map(fn(int $i): string => "o{$i}", range(1, 256)), null);
    $levels = array_fill(0, 11, 'x');

    $errors = Validator::errors(QuestionSet::of(Choice::make('c', 'q', $options), Score::make('s', 'q', $levels)), Capabilities::jev());

    expect($errors['c'][0])->toContain('at most 255 options, 256 given')
        ->and($errors['s'][0])->toContain('between 2 and 10 levels, 11 given');
});

it('rejects 0-based numeric option names that would serialize as a JSON array', function (): void {
    $errors = Validator::errors(QuestionSet::of(Choice::make('c', 'q', ['zero', 'one'])), Capabilities::jev());

    expect($errors['c'][0])->toContain('JSON array');
});

it('rejects a null state when the engine does not support it', function (): void {
    $set = QuestionSet::of(Noul::make('n', 'q'));

    expect(Validator::errors($set, Capabilities::jev(), State::null())['state'][0])->toContain("pass '' or []")
        ->and(Validator::errors($set, Capabilities::jev(), State::from('')))->toBe([])
        ->and(Validator::errors($set, Capabilities::jev(), State::from([])))->toBe([])
        ->and(Validator::errors($set, Capabilities::llm(), State::null()))->toBe([]);
});

it('rejects unknown noul criteria keys', function (): void {
    $noul = Noul::fromArray('n', ['type' => 'noul', 'criteria' => ['true' => 'a', 'maybe' => 'b']]);

    expect(Validator::errors(QuestionSet::of($noul), Capabilities::jev())['n'][0])->toContain('maybe');
});

it('throws InvalidDecisionException with the error map', function (): void {
    try {
        Validator::validate(QuestionSet::of(Score::make('s', 'q', ['x'])), Capabilities::jev());
        $this->fail('Expected exception');
    } catch (InvalidDecisionException $e) {
        expect($e->errors())->toHaveKey('s')
            ->and($e->errorsFor('s'))->toHaveCount(1)
            ->and($e->errorsFor('other'))->toBe([])
            ->and($e->getMessage())->toContain('[s]');
    }
});
