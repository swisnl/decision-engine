<?php

declare(strict_types=1);

use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Engines\Jev\JevEngine;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\RawQuestion;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Support\Json;
use Swis\DecisionEngine\Testing\FakeTransport;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Transport\RetryPolicy;

function builderClient(FakeTransport $transport): Client
{
    $engines = EngineManager::fromArray([
        'default' => 'jev',
        'engines' => [
            'jev' => ['driver' => 'jev', 'api_key' => 'sk-test'],
            'luna' => ['driver' => 'jev', 'api_key' => 'sk-luna', 'base_url' => 'https://luna.test', 'model' => 'gpt-5.6-luna'],
        ],
    ]);

    return new Client($engines, $transport, new RequestOptions(timeout: 10.0), RetryPolicy::none());
}

afterEach(function (): void {
    Decision::resolveClientUsing(null);
    Decision::restore();
});

it('runs the 30-second example', function (): void {
    $transport = new FakeTransport([HttpResponse::jsonResponse(200, $this->fixture('triage-response'), ['x-typesafe-request-id' => 'req_1'])]);

    $outcome = Decision::for(['message' => 'I was charged twice', 'order' => ['id' => 1234]])
        ->withClient(builderClient($transport))
        ->choice('department', 'Which team should handle this?', [
            'returns' => 'Exchanges, wrong or damaged items',
            'shipping' => 'Delivery status, delays, lost packages',
            'billing' => 'Charges, invoices, payment problems',
            'other' => null,
        ])
        ->score('bug_severity', 'How severe is the reported issue?', [
            'Cosmetic; no impact to functionality',
            'Broken or degraded feature, but workaround exists',
            'Blocking issue; no workaround exists',
        ])
        ->noul('is_human_escalation', 'Is the customer asking for a human agent?')
        ->noul('same_as_record_18', ['potential_duplicate' => ['id' => 18], 'question' => 'Same customer?'])
        ->decide();

    expect($outcome->department->choice)->toBe('billing')
        ->and($outcome->department->confidence)->toBe(0.82)
        ->and($outcome->bug_severity->score)->toBe(1.43)
        ->and(round($outcome->bug_severity->normalized(), 3))->toBe(0.715)
        ->and($outcome->is_human_escalation->isTrue(0.9))->toBeTrue()
        ->and($outcome->same_as_record_18->isTrue(0.9))->toBeFalse()
        ->and($outcome->usage->inputTokens)->toBe(360)
        ->and($outcome->model)->toBe('jev-1.13.0')
        ->and($outcome->meta->requestId)->toBe('req_1');

    $sent = $transport->lastSent()?->json();
    expect($sent['state'])->toBe(['message' => 'I was charged twice', 'order' => ['id' => 1234]])
        ->and($sent['model'])->toBe('jev-latest')
        ->and(array_keys($sent['questions']))->toBe(['department', 'bug_severity', 'is_human_escalation', 'same_as_record_18'])
        ->and($sent['questions']['department']['criteria']['other'])->toBeNull()
        ->and($sent['questions']['same_as_record_18']['instructions']['question'])->toBe('Same customer?');
});

it('chooses engines and models', function (): void {
    $transport = new FakeTransport([HttpResponse::jsonResponse(200, $this->fixture('quickstart-response'))]);
    $client = builderClient($transport);

    $prepared = Decision::for('x')->withClient($client)->using('luna')->noul('n', 'q')->toRequest();
    expect($prepared->url)->toBe('https://luna.test/v1/systemone')
        ->and($prepared->header('Authorization'))->toBe('Bearer sk-luna')
        ->and($prepared->json()['model'])->toBe('gpt-5.6-luna');

    $prepared = Decision::for('x')->withClient($client)->using('luna')->model('jev-1.13.0')->noul('n', 'q')->toRequest();
    expect($prepared->json()['model'])->toBe('jev-1.13.0');

    // Switching engines drops an inherited model; the new engine's default applies.
    expect(Decision::for('x')->withClient($client)->model('jev-1.13.0')->using('luna')->noul('n', 'q')->toRequest()->json()['model'])->toBe('gpt-5.6-luna')
        ->and(Decision::for('x')->withClient($client)->using('jev')->model('jev-1.13.0')->using('jev')->getModel())->toBe('jev-1.13.0');

    $instance = new JevEngine('sk-instance', 'https://instance.test');
    $decision = Decision::for('x')->withClient($client)->using($instance)->noul('n', 'q');
    expect($decision->toRequest()->url)->toBe('https://instance.test/v1/systemone')
        ->and($decision->engineName())->toBe('jev')
        ->and($decision->engineInstance())->toBe($instance)
        ->and($decision->toArray()['engine'])->toBe('jev');

    expect(fn() => Decision::for('x')->withClient($client)->using('haiku')->noul('n', 'q')->toRequest())->toThrow(ConfigurationException::class);

    // Non-static / DI-friendly entry point
    expect($client->for('x')->noul('is_urgent', 'q')->decide()->model)->toBe('jev-1.13.0');
    expect($transport->count())->toBe(1);
});

it('accepts richer question objects', function (): void {
    $decision = Decision::for('s')
        ->ask(
            Choice::make('department', 'Which team should handle this?')
                ->option('returns', what: 'Exchanges, wrong or damaged items', notFor: 'Refund requests', examples: ['wrong size', 'arrived broken'])
                ->option('billing', 'Charges, invoices, payment problems')
                ->option('other'),
        )
        ->ask(
            Score::make('severity', 'How severe is the reported issue?')
                ->level('Cosmetic; no impact to functionality')
                ->level(what: 'Blocking issue; no workaround exists', examples: ['cannot log in', 'data loss']),
        )
        ->ask(
            Noul::make('same_as_record_18')
                ->withInstructions(['potential_duplicate' => ['id' => 18], 'question' => 'Is the resume for the same person as `potential_duplicate`?'])
                ->criteria(true: 'Mentions a prior attempt or ticket', false: 'No sign of previous contact'),
        );

    $questions = $decision->toArray()['questions'];

    expect($questions['department']['criteria']['returns'])->toBe(['what' => 'Exchanges, wrong or damaged items', 'not_for' => 'Refund requests', 'examples' => ['wrong size', 'arrived broken']])
        ->and($questions['department']['criteria']['other'])->toBeNull()
        ->and($questions['severity']['criteria'][1])->toBe(['what' => 'Blocking issue; no workaround exists', 'examples' => ['cannot log in', 'data loss']])
        ->and($questions['same_as_record_18']['criteria'])->toBe(['true' => 'Mentions a prior attempt or ticket', 'false' => 'No sign of previous contact'])
        ->and($decision->noul('n2', 'q', true: 'yes when', false: 'no when')->toArray()['questions']['n2']['criteria'])->toBe(['true' => 'yes when', 'false' => 'no when'])
        ->and($decision->yesNo('n3', 'q')->questions()->get('n3'))->toBeInstanceOf(Noul::class)
        ->and($decision->without('n2', 'n3')->questions()->ids())->toBe(['department', 'severity', 'same_as_record_18']);
});

it('offers every escape hatch', function (): void {
    $body = $this->fixture('quickstart-response');
    $body['answers']['anything'] = ['type' => 'choice', 'choice' => 'a', 'probabilities' => ['a' => 1.0], 'confidence' => 1.0];
    $transport = new FakeTransport([HttpResponse::jsonResponse(200, $body)]);
    $client = builderClient($transport);

    $decision = Decision::for('s')->withClient($client)
        ->rawQuestion('anything', ['type' => 'choice', 'instructions' => 'q', 'criteria' => ['a' => null], 'future_field' => 1])
        ->withPayload(fn(array $payload): array => $payload + ['experimental' => true])
        ->mergePayload(['model' => 'jev-preview'])
        ->withHeaders(['X-Trace-Id' => 'trace-1'])
        ->withOptions(['timeout' => 3.0, 'retry' => ['maxRetries' => 0]])
        ->engineOptions(['reasoning' => ['effort' => 'low']])
        ->engineOptions(['merge_body' => ['store' => false]]);

    $prepared = $decision->toRequest();

    expect($transport->count())->toBe(0)
        ->and($prepared->header('X-Trace-Id'))->toBe('trace-1')
        ->and($prepared->json()['questions']['anything'])->toBe(['type' => 'choice', 'instructions' => 'q', 'criteria' => ['a' => null], 'future_field' => 1])
        ->and($prepared->json()['experimental'])->toBeTrue()
        ->and($prepared->json()['model'])->toBe('jev-preview')
        ->and($prepared->json()['store'])->toBeFalse()
        ->and($prepared->toCurlCommand())->toContain('[redacted]')
        ->and($decision->request()->engineOptions)->toBe(['reasoning' => ['effort' => 'low'], 'merge_body' => ['store' => false]]);

    $outcome = $decision->decide();

    expect($transport->sentOptions()[0]->timeout)->toBe(3.0)
        ->and($transport->sentOptions()[0]->retry?->maxRetries)->toBe(0)
        ->and($outcome->raw())->toBe($body)
        ->and($outcome->response()?->status)->toBe(200)
        ->and($outcome->request()?->url)->toBe('https://api.typesafe.ai/v1/systemone')
        ->and($outcome->answer('department')->raw())->toBe($this->fixture('quickstart-response')['answers']['department'])
        ->and($outcome->answer('department')->get('choice'))->toBe('technical')
        ->and($outcome->answer('department')->get('some_new_field', 'dflt'))->toBe('dflt');
});

it('round-trips through toArray()/toJson() including engine, model, options and merge', function (): void {
    $decision = Decision::for(['a' => 1])
        ->using('luna')->model('gpt-5.6-luna')
        ->choice('c', 'q', ['x' => null, 'y' => 'desc'])
        ->score('s', 'q', ['lo', 'hi'])
        ->noul('n', 'q', true: 'yes')
        ->rawQuestion('r', ['type' => 'future', 'k' => [1, 2]])
        ->withHeaders(['X-A' => '1'])
        ->timeout(2.0)
        ->engineOptions(['reasoning' => ['effort' => 'low']])
        ->mergePayload(['flag' => true])
        ->withPayload(fn(array $p): array => $p + ['extra' => 'v']);

    $array = $decision->toArray();

    expect(array_keys($array))->toBe(['state', 'questions', 'engine', 'model', 'options', 'engine_options', 'payload'])
        ->and($array['payload']['merge'])->toBe(['flag' => true, 'extra' => 'v'])
        ->and(Decision::fromArray($array)->toArray())->toBe($array)
        ->and(Decision::fromJson($decision->toJson())->toArray())->toBe($array)
        ->and(Decision::fromRequest(DecisionRequest::fromArray($array))->toArray())->toBe($array)
        ->and(json_decode(json_encode($decision), true))->toEqual($array) // plain json_encode drops the .0 of 2.0
        ->and(Json::canonical($array))->toBe(Json::canonical(Decision::fromArray($array)->toArray()))
        ->and(Decision::fromArray($array)->getModel())->toBe('gpt-5.6-luna')
        ->and(Decision::fromArray($array)->questions()->get('r'))->toBeInstanceOf(RawQuestion::class);

    // Bare wire payload copied from the playground
    $bare = Decision::fromArray($this->fixture('quickstart-request'));
    expect($bare->getModel())->toBe('jev-latest')->and($bare->questions()->ids())->toBe(['department', 'frustration', 'is_urgent']);
});

it('clones for branching and keeps the original intact', function (): void {
    $base = Decision::for('s')->noul('a', 'q');
    $branch = $base->clone()->noul('b', 'q')->model('jev-preview');

    expect($base->questions()->ids())->toBe(['a'])
        ->and($base->getModel())->toBeNull()
        ->and($branch->questions()->ids())->toBe(['a', 'b'])
        ->and($branch->getModel())->toBe('jev-preview')
        ->and($base->state('t')->getState()->value())->toBe('t');
});

it('validates before sending and never touches the transport', function (): void {
    $transport = new FakeTransport();
    expect(fn() => Decision::for('s')->withClient(builderClient($transport))->score('s', 'q', ['only'])->decide())->toThrow(InvalidDecisionException::class)
        ->and(fn() => Decision::for('s')->withClient(builderClient($transport))->decide())->toThrow(InvalidDecisionException::class, 'At least one question')
        ->and($transport->count())->toBe(0);
});

it('lints for known model jaggedness without throwing', function (): void {
    $warnings = Decision::for(str_repeat('x', 70 * 1024))
        ->score('age', 'How old?', ['under 18 years', '18 to 65', 'over 65'])
        ->choice('bucket', 'Which bucket?', ['small' => 'Less than 10 items', 'medium' => 'Between 10 and 100', 'large' => 'Between 10 and 100'])
        ->choice('lang', null, ['nl' => null, 'en' => null])
        ->rawQuestion('raw', ['type' => 'future'])
        ->lint();

    $codes = array_map(fn($w) => $w->code, $warnings);

    expect($codes)->toContain('large_state', 'numbers_in_criteria', 'no_catch_all_option', 'duplicate_option_description', 'missing_instructions')
        ->and(array_filter($warnings, fn($w) => $w->questionId === 'age'))->toHaveCount(1)
        ->and((string) $warnings[0])->toContain('bytes')
        ->and(Decision::for('s')->noul('n', 'q')->lint())->toBe([]);

    $many = Decision::for('s');
    for ($i = 0; $i < 21; $i++) {
        $many->noul("n{$i}", 'q');
    }
    expect(array_map(fn($w) => $w->code, $many->lint()))->toContain('too_many_questions');
});

it('resolves a client statically via resolveClientUsing()', function (): void {
    $transport = new FakeTransport([HttpResponse::jsonResponse(200, $this->fixture('quickstart-response'))]);
    $calls = 0;
    Decision::resolveClientUsing(function () use ($transport, &$calls): Client {
        $calls++;

        return builderClient($transport);
    });

    expect(Decision::for('x')->noul('is_urgent', 'q')->decide()->model)->toBe('jev-1.13.0')
        ->and(Decision::resolveClient())->toBe(Decision::resolveClient())
        ->and($calls)->toBe(1);
});
