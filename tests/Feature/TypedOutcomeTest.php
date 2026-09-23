<?php

declare(strict_types=1);

use Swis\DecisionEngine\Answers\ChoiceAnswer;
use Swis\DecisionEngine\Answers\NoulAnswer;
use Swis\DecisionEngine\Answers\ScoreAnswer;
use Swis\DecisionEngine\Attributes\Choice;
use Swis\DecisionEngine\Attributes\Noul;
use Swis\DecisionEngine\Attributes\Option;
use Swis\DecisionEngine\Attributes\Score;
use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\Exceptions\OutcomeMismatchException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Testing\Fake;
use Swis\DecisionEngine\Testing\FakeTransport;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Transport\RetryPolicy;
use Swis\DecisionEngine\Typed\TypedOutcome;

enum TypedDepartment: string
{
    #[Option('Exchanges, wrong or damaged items', notFor: 'Refund requests', examples: ['arrived broken'])]
    case Returns = 'returns';

    #[Option('Charges, invoices, payment problems')]
    case Billing = 'billing';

    case Other = 'other';
}

enum TypedStars: int
{
    case One = 1;
    case Two = 2;
}

final class TypedTriage extends TypedOutcome
{
    #[Choice('Which team should handle this?')]
    public readonly TypedDepartment $department;

    #[Choice('Which language is the message in?', options: ['nl' => 'Dutch', 'en' => 'English', 'other' => null])]
    public readonly string $language;

    #[Choice('Which team, as a full answer?', options: TypedDepartment::class, id: 'team')]
    public readonly ChoiceAnswer $teamAnswer;

    #[Score('How severe is the issue?', levels: ['Cosmetic', 'Degraded', 'Blocking'])]
    public readonly ScoreAnswer $severity;

    #[Score('How angry is the customer?', levels: ['Calm', 'Annoyed', 'Furious'])]
    public readonly float $anger;

    #[Noul('Is the customer asking for a human agent?', true: 'Asks for a person or a call')]
    public readonly NoulAnswer $wants_human;

    #[Noul('Does the message mention a refund?')]
    public readonly float $refund;

    public string $notAQuestion = 'untouched';
}

final class TypedRating extends TypedOutcome
{
    #[Choice('How many stars?')]
    public readonly TypedStars $stars;
}

final class TypedUrgent extends TypedOutcome
{
    #[Noul('Is this urgent?')]
    public readonly float $urgent;
}

function typedClient(FakeTransport $transport, int $invalidResponses = 1): Client
{
    $engines = EngineManager::fromArray(['engines' => ['jev' => ['driver' => 'jev', 'api_key' => 'k']]]);

    return new Client($engines, $transport, new RequestOptions(), new RetryPolicy(maxRetries: 0, invalidResponses: $invalidResponses));
}

afterEach(fn() => Decision::restore());

it('derives the questions from the property attributes', function (): void {
    $questions = [];
    foreach (TypedTriage::questions() as $question) {
        $questions[$question->id()] = $question->toArray();
    }

    expect(array_keys($questions))->toBe(['department', 'language', 'team', 'severity', 'anger', 'wants_human', 'refund'])
        ->and($questions['department']['criteria'])->toBe([
            'returns' => ['what' => 'Exchanges, wrong or damaged items', 'not_for' => 'Refund requests', 'examples' => ['arrived broken']],
            'billing' => 'Charges, invoices, payment problems',
            'other' => null,
        ])
        ->and($questions['team']['criteria'])->toBe($questions['department']['criteria'])
        ->and($questions['language']['criteria'])->toBe(['nl' => 'Dutch', 'en' => 'English', 'other' => null])
        ->and($questions['severity']['criteria'])->toBe(['Cosmetic', 'Degraded', 'Blocking'])
        ->and($questions['wants_human']['criteria'])->toBe(['true' => 'Asks for a person or a call'])
        ->and(Decision::for('s')->ask(...TypedTriage::questions())->lint())->toBeArray();
});

it('decides into typed properties by property type', function (): void {
    Decision::fake([
        'department' => Fake::choice('billing', 0.9),
        'language' => Fake::choice('nl', 0.8),
        'team' => Fake::choice('returns', 0.7),
        'severity' => Fake::score(1.5, levels: 3),
        'anger' => Fake::score(0.5, levels: 3),
        'wants_human' => Fake::noul(0.2),
        'refund' => Fake::noul(0.95),
    ]);

    $builder = Decision::for('Mijn factuur klopt niet');
    $triage = $builder->decideAs(TypedTriage::class);

    expect($triage->department)->toBe(TypedDepartment::Billing)
        ->and($triage->language)->toBe('nl')
        ->and($triage->teamAnswer->as(TypedDepartment::class))->toBe(TypedDepartment::Returns)
        ->and(round($triage->teamAnswer->confidence, 2))->toBe(0.7)
        ->and(round($triage->severity->score, 6))->toBe(1.5)
        ->and(round($triage->anger, 6))->toBe(0.5)
        ->and($triage->wants_human->noul)->toBe(0.2)
        ->and($triage->refund)->toBe(0.95)
        ->and($triage->notAQuestion)->toBe('untouched')
        ->and($triage->outcome()->engine)->toBe('fake')
        ->and($builder->questions()->ids())->toBe([]); // the builder is left unchanged

    $again = TypedTriage::fromArray(json_decode(json_encode($triage, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    expect($again->department)->toBe(TypedDepartment::Billing)
        ->and(json_encode($again))->toBe(json_encode($triage));
});

it('supports int-backed enums', function (): void {
    Decision::fake(['stars' => Fake::choice('2', 0.9)]);

    expect(TypedRating::questions()[0]->toArray()['criteria'])->toBe(['1' => null, '2' => null])
        ->and(Decision::for('s')->decideAs(TypedRating::class)->stars)->toBe(TypedStars::Two);
});

it('throws when the outcome does not fit the class', function (): void {
    Decision::fake(['department' => Fake::choice('sales', 0.9)]); // not a case of TypedDepartment

    expect(fn() => Decision::for('s')->decideAs(TypedTriage::class))
        ->toThrow(OutcomeMismatchException::class, 'expects a case of TypedDepartment for question [department], got [sales]');

    Decision::fake();
    $outcome = Decision::for('s')->noul('urgent', 'q')->decide();
    expect(fn() => TypedTriage::fromOutcome($outcome))->toThrow(OutcomeMismatchException::class, 'TypedTriage::$department expects an answer for question [department]')
        ->and(fn() => TypedUrgent::fromOutcome(Decision::for('s')->choice('urgent', 'q', ['a' => null])->decide()))
        ->toThrow(OutcomeMismatchException::class, 'expects a NoulAnswer for question [urgent], got ChoiceAnswer');
});

it('re-asks when an answer is missing, then throws', function (): void {
    $missing = HttpResponse::jsonResponse(200, ['model' => 'jev-1', 'answers' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]);
    $complete = HttpResponse::jsonResponse(200, ['model' => 'jev-1', 'answers' => ['urgent' => ['type' => 'noul', 'noul' => 0.9]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);

    $transport = new FakeTransport([$missing, $complete]);
    expect(typedClient($transport)->for('s')->decideAs(TypedUrgent::class)->urgent)->toBe(0.9)
        ->and($transport->count())->toBe(2);

    $transport = new FakeTransport([$missing, $missing]);
    expect(fn() => typedClient($transport)->for('s')->decideAs(TypedUrgent::class))
        ->toThrow(ResponseValidationException::class, 'no answer for question [urgent]')
        ->and($transport->count())->toBe(2);

    $transport = new FakeTransport([$missing]);
    expect(fn() => typedClient($transport, invalidResponses: 0)->for('s')->noul('urgent', 'q')->decide())->toThrow(ResponseValidationException::class)
        ->and($transport->count())->toBe(1);
});

it('decides typed outcomes for many states', function (): void {
    Decision::fake(fn(DecisionRequest $r) => $r->state->value() === 'boom' ? new RuntimeException('down') : ['urgent' => Fake::noul($r->state->value() === 'fire' ? 0.99 : 0.1)]);

    $typed = Decision::forEach(['a' => 'fire', 'b' => 'hello'])->decideAs(TypedUrgent::class);
    $settled = Decision::forEach(['a' => 'fire', 'x' => 'boom'])->settleAs(TypedUrgent::class);

    expect($typed['a']->urgent)->toBe(0.99)
        ->and($typed['b']->urgent)->toBe(0.1)
        ->and($settled['a'])->toBeInstanceOf(TypedUrgent::class)
        ->and($settled['x'])->toBeInstanceOf(RuntimeException::class);
});

it('rejects invalid class definitions before sending anything', function (TypedOutcome|string $class, string $message): void {
    expect(fn() => $class::questions())->toThrow(InvalidDecisionException::class, $message);
})->with([
    'wrong type' => [new class extends TypedOutcome {
        #[Choice('q', options: ['a' => null])]
        public int $x;
    }, 'type it as ChoiceAnswer, string or a backed enum, not int'],
    'nullable' => [new class extends TypedOutcome {
        #[Noul('q')]
        public ?float $x;
    }, 'needs a single, non-nullable type'],
    'no options' => [new class extends TypedOutcome {
        #[Choice('q')]
        public string $x;
    }, 'needs `options`'],
    'enum conflict' => [new class extends TypedOutcome {
        #[Choice('q', options: ['a' => null])]
        public TypedDepartment $x;
    }, 'takes its options from TypedDepartment'],
    'score as bool' => [new class extends TypedOutcome {
        #[Score('q', levels: ['lo', 'hi'])]
        public bool $x;
    }, 'type it as ScoreAnswer or float, not bool'],
    'duplicate id' => [new class extends TypedOutcome {
        #[Noul('q')]
        public float $x;

        #[Noul('q', id: 'x')]
        public float $y;
    }, 'reuses question id [x]'],
    'no questions' => [new class extends TypedOutcome {
        public string $x;
    }, 'declares no #[Choice], #[Score] or #[Noul] properties'],
]);
