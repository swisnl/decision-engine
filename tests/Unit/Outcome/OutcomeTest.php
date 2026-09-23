<?php

declare(strict_types=1);

use Swis\DecisionEngine\Answers\AnswerFactory;
use Swis\DecisionEngine\Answers\ChoiceAnswer;
use Swis\DecisionEngine\Answers\GenericAnswer;
use Swis\DecisionEngine\Outcome\Certainty;
use Swis\DecisionEngine\Outcome\Meta;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Outcome\Usage;

function outcomeFromFixture(array $body): Outcome
{
    return new Outcome(
        answers: AnswerFactory::fromAnswers($body['answers']),
        model: $body['model'],
        engine: 'jev',
        usage: Usage::fromArray($body['usage']),
        meta: new Meta(calibrated: true, requestId: 'req_1', latencyMs: 104.2),
        raw: $body,
    );
}

it('exposes answers as properties, offsets and typed accessors', function (): void {
    $outcome = outcomeFromFixture($this->fixture('triage-response'));

    expect($outcome->department->choice)->toBe('billing')
        ->and($outcome['department'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($outcome->choice('department')->confidence)->toBe(0.82)
        ->and($outcome->score('bug_severity')->score)->toBe(1.43)
        ->and(round($outcome->score('bug_severity')->normalized(), 3))->toBe(0.715)
        ->and($outcome->noul('is_human_escalation')->isTrue(0.9))->toBeTrue()
        ->and($outcome->answer('mystery'))->toBeInstanceOf(GenericAnswer::class)
        ->and($outcome->usage->inputTokens)->toBe(360)
        ->and($outcome->usage->total())->toBe(399)
        ->and($outcome->model)->toBe('jev-1.13.0')
        ->and($outcome->engine)->toBe('jev')
        ->and($outcome->meta->calibrated)->toBeTrue()
        ->and($outcome->meta->requestId)->toBe('req_1')
        ->and(isset($outcome->department))->toBeTrue()
        ->and(isset($outcome['nope']))->toBeFalse()
        ->and($outcome->has('nope'))->toBeFalse()
        ->and(count($outcome))->toBe(5)
        ->and(array_keys($outcome->only(['department', 'mystery'])))->toBe(['department', 'mystery'])
        ->and(array_keys(iterator_to_array($outcome)))->toBe($outcome->ids())
        ->and($outcome->raw()['usage'])->toBe(['input_tokens' => 360, 'output_tokens' => 39]);
});

it('throws helpful errors on missing or mistyped answers', function (): void {
    $outcome = outcomeFromFixture($this->fixture('triage-response'));

    expect(fn() => $outcome->answer('nope'))->toThrow(OutOfBoundsException::class, 'Available: department')
        ->and(fn() => $outcome->choice('bug_severity'))->toThrow(UnexpectedValueException::class)
        ->and(fn() => $outcome->score('department'))->toThrow(UnexpectedValueException::class)
        ->and(fn() => $outcome->noul('department'))->toThrow(UnexpectedValueException::class)
        ->and(fn() => $outcome['x'] = 1)->toThrow(LogicException::class)
        ->and(function () use ($outcome): void {
            unset($outcome['x']);
        })->toThrow(LogicException::class)
        ->and(fn() => $outcome[0])->toThrow(InvalidArgumentException::class);
});

it('round-trips through toArray()/fromArray() with and without raw', function (): void {
    $outcome = outcomeFromFixture($this->fixture('triage-response'));

    $array = $outcome->toArray();
    expect(array_keys($array))->toBe(['engine', 'model', 'answers', 'usage', 'meta'])
        ->and($array['meta'])->toBe(['calibrated' => true, 'request_id' => 'req_1', 'latency_ms' => 104.2]);

    $again = Outcome::fromArray($array);
    expect($again->toArray())->toBe($array)
        ->and($again->department->choice)->toBe('billing')
        ->and($again->answer('mystery')->get('values'))->toBe([0.1, 0.2])
        ->and($again->meta->latencyMs)->toBe(104.2)
        ->and($again->raw()['answers'])->toBe($array['answers']);

    $withRaw = $outcome->toArray(includeRaw: true);
    expect($withRaw)->toHaveKey('raw')
        ->and(Outcome::fromArray($withRaw)->raw())->toBe($withRaw['raw'])
        ->and(Outcome::fromJson($outcome->toJson())->toArray())->toBe($array);
});

it('applies thresholds to every answer', function (): void {
    $outcome = outcomeFromFixture($this->fixture('triage-response'))->withThresholds(new Thresholds(high: 0.8, medium: 0.3));

    expect($outcome->department->band())->toBe(Certainty::High)
        ->and($outcome->bug_severity->band())->toBe(Certainty::Medium)
        ->and(Outcome::fromArray($outcome->toArray(), new Thresholds(0.99, 0.98))->department->band())->toBe(Certainty::Low);
});

it('requires an answers key', function (): void {
    Outcome::fromArray(['model' => 'x']);
})->throws(InvalidArgumentException::class, 'missing [answers]');

it('sums usage', function (): void {
    expect(Usage::fromArray(['input_tokens' => 1, 'output_tokens' => 2])->add(new Usage(3, 4))->toArray())->toBe(['input_tokens' => 4, 'output_tokens' => 6])
        ->and(Usage::fromArray(['inputTokens' => 5])->inputTokens)->toBe(5)
        ->and(Usage::zero()->total())->toBe(0);
});
