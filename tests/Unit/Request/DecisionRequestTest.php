<?php

declare(strict_types=1);

use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Questions\RawQuestion;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\State\State;
use Swis\DecisionEngine\Support\Json;
use Swis\DecisionEngine\Transport\RetryPolicy;

it('round-trips the documented fixtures byte-identically', function (string $fixture): void {
    $array = $this->fixture($fixture);
    $request = DecisionRequest::fromArray($array);

    // Same keys and values; the canonical form orders top-level keys as state, questions, model.
    expect(Json::canonical($request->toArray()))->toBe(Json::canonical($array))
        ->and($request->toArray())->toEqual($array)
        ->and($request->toArray()['questions'])->toBe($array['questions']);
})->with(['quickstart-request', 'triage-request']);

it('builds the canonical payload with model only when set', function (): void {
    $request = new DecisionRequest(State::from('Hello'), QuestionSet::of(Noul::make('g', 'Greeting?')));

    expect($request->payload())->toBe(['state' => 'Hello', 'questions' => ['g' => ['type' => 'noul', 'instructions' => 'Greeting?']]])
        ->and($request->withModel('jev-1.13.0')->payload())->toBe([
            'state' => 'Hello',
            'model' => 'jev-1.13.0',
            'questions' => ['g' => ['type' => 'noul', 'instructions' => 'Greeting?']],
        ]);
});

it('serializes engine, model, options, engine options and merge in a fixed order', function (): void {
    $request = (new DecisionRequest(State::from(['a' => 1]), QuestionSet::of(Noul::make('n', 'q'))))
        ->withEngine('luna')
        ->withModel('gpt-5.6-luna')
        ->withOptions(new RequestOptions(timeout: 3.0, retry: RetryPolicy::none(), headers: ['X-Trace-Id' => 't']))
        ->withEngineOptions(['reasoning' => ['effort' => 'low']])
        ->withMerge(['experimental' => true]);

    $array = $request->toArray();

    expect(array_keys($array))->toBe(['state', 'questions', 'engine', 'model', 'options', 'engine_options', 'payload'])
        ->and($array['engine'])->toBe('luna')
        ->and($array['model'])->toBe('gpt-5.6-luna')
        ->and($array['options']['timeout'])->toBe(3.0)
        ->and($array['options']['retry']['max_retries'])->toBe(0)
        ->and($array['options']['headers'])->toBe(['X-Trace-Id' => 't'])
        ->and($array['engine_options'])->toBe(['reasoning' => ['effort' => 'low']])
        ->and($array['payload'])->toBe(['merge' => ['experimental' => true]]);

    $again = DecisionRequest::fromArray($array);

    expect($again->toArray())->toBe($array)
        ->and($again->engine)->toBe('luna')
        ->and($again->options->retry?->maxRetries)->toBe(0)
        ->and($again->fingerprint())->toBe($request->fingerprint());
});

it('applies payload mutators at toArray() time and flattens their effect', function (): void {
    $request = (new DecisionRequest(State::from('s'), QuestionSet::of(Noul::make('n', 'q'))))
        ->withMutator(fn(array $p): array => $p + ['model' => 'jev-preview', 'extra' => 1])
        ->withMutator(function (array $p): array {
            $p['questions']['n']['instructions'] = 'mutated';

            return $p;
        });

    $array = $request->toArray();

    expect($array['model'])->toBe('jev-preview')
        ->and($array['payload'])->toBe(['merge' => ['extra' => 1]])
        ->and($array['questions']['n']['instructions'])->toBe('mutated')
        ->and($request->hasMutators())->toBeTrue()
        ->and(DecisionRequest::fromArray($array)->toArray())->toBe($array);
});

it('accepts the bare wire form and keeps unknown top-level keys as merge', function (): void {
    $request = DecisionRequest::fromArray([
        'state' => 'x',
        'model' => 'jev-latest',
        'questions' => ['n' => ['type' => 'noul', 'instructions' => 'q']],
        'experimental' => true,
    ]);

    expect($request->model)->toBe('jev-latest')
        ->and($request->engine)->toBeNull()
        ->and($request->merge)->toBe(['experimental' => true])
        ->and($request->payload()['experimental'])->toBeTrue();
});

it('round-trips from and to JSON', function (): void {
    $request = DecisionRequest::fromArray($this->fixture('triage-request'));

    expect(DecisionRequest::fromJson($request->toJson())->toArray())->toBe($request->toArray());
});

it('wraps deserialization problems in InvalidDecisionException', function (): void {
    expect(fn() => DecisionRequest::fromArray(['state' => 'x']))->toThrow(InvalidDecisionException::class, 'Missing [questions]')
        ->and(fn() => DecisionRequest::fromArray(['questions' => 'nope']))->toThrow(InvalidDecisionException::class)
        ->and(fn() => DecisionRequest::fromArray(['questions' => ['a' => 'nope']]))->toThrow(InvalidDecisionException::class)
        ->and(fn() => DecisionRequest::fromArray(['questions' => [], 'options' => ['retry' => 5]]))->toThrow(InvalidDecisionException::class);
});

it('keeps question order and unknown question types across a round trip', function (): void {
    $array = [
        'state' => null,
        'questions' => [
            'z' => ['type' => 'noul', 'instructions' => 'q'],
            'a' => ['type' => 'vector', 'dims' => 3],
            'm' => ['type' => 'choice', 'criteria' => ['x' => null], 'weight' => 2],
        ],
    ];

    $request = DecisionRequest::fromArray($array);

    expect($request->questionIds())->toBe(['z', 'a', 'm'])
        ->and($request->question('a'))->toBeInstanceOf(RawQuestion::class)
        ->and($request->question('m'))->toBeInstanceOf(Choice::class)
        ->and($request->toArray())->toBe($array);
});

it('withers return copies and leave the original untouched', function (): void {
    $a = new DecisionRequest(State::from('s'), QuestionSet::of(Noul::make('n', 'q')));
    $b = $a->withEngine('haiku')->withEngineOptions(['x' => 1])->withEngineOptions(['y' => ['z' => 1]])->withEngineOptions(['y' => ['w' => 2]]);

    expect($a->engine)->toBeNull()
        ->and($b->engine)->toBe('haiku')
        ->and($b->engineOptions)->toBe(['x' => 1, 'y' => ['z' => 1, 'w' => 2]])
        ->and($b->withEngine(null)->engine)->toBeNull();
});

it('round-trips randomly generated question sets (property test)', function (): void {
    mt_srand(20260922);

    for ($i = 0; $i < 200; $i++) {
        $questions = QuestionSet::empty();
        $count = mt_rand(1, 6);

        for ($j = 0; $j < $count; $j++) {
            $id = 'q' . $j . '_' . mt_rand(0, 999);
            $instructions = mt_rand(0, 2) === 0 ? null : (mt_rand(0, 1) === 0 ? 'Question ' . $j : ['text' => 'Question ' . $j, 'record' => ['id' => $j]]);
            $questions = $questions->with(match (mt_rand(0, 3)) {
                0 => Choice::make($id, $instructions, array_combine(
                    array_map(fn(int $k): string => "opt{$k}", range(1, $n = mt_rand(1, 5))),
                    array_map(fn(int $k): array|string|null => match ($k % 3) {
                        0 => null,
                        1 => "desc {$k}",
                        default => ['what' => "w{$k}", 'examples' => ["e{$k}"]],
                    }, range(1, $n)),
                )),
                1 => Score::make($id, $instructions, array_map(fn(int $k): string => "level {$k}", range(0, mt_rand(1, 9)))),
                2 => Noul::make($id, $instructions)->criteria(true: mt_rand(0, 1) === 0 ? null : 'yes when', false: mt_rand(0, 1) === 0 ? null : 'no when'),
                default => RawQuestion::make($id, ['type' => 'future' . $j, 'anything' => [1, 2, ['deep' => true]]]),
            });
        }

        $state = match (mt_rand(0, 2)) {
            0 => null,
            1 => 'text ' . $i,
            default => ['message' => 'm' . $i, 'nested' => ['n' => $i, 'list' => [1, 2, 3]]],
        };

        $request = new DecisionRequest(State::from($state), $questions, mt_rand(0, 1) === 0 ? null : 'jev', mt_rand(0, 1) === 0 ? null : 'jev-1.13.0');
        $array = $request->toArray();

        expect(DecisionRequest::fromArray($array)->toArray())->toBe($array);
        expect(DecisionRequest::fromJson(Json::encode($array))->toArray())->toBe($array);
    }
});
