<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Engines\Jev\JevEngine;
use Swis\DecisionEngine\Exceptions\ServerException;
use Swis\DecisionEngine\Outcome\Certainty;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Testing\Fake;
use Swis\DecisionEngine\Testing\FakeEngine;
use Swis\DecisionEngine\Testing\FakeTransport;
use Swis\DecisionEngine\Transport\HttpResponse;

afterEach(fn() => Decision::restore());

it('fakes answers consistently with the requested choice, score and confidence', function (): void {
    Decision::fake([
        'department' => Fake::choice('billing', confidence: 0.95),
        'severity' => Fake::score(1.4, levels: 3),
        'wants_human' => Fake::noul(0.1),
        'future' => ['type' => 'vector', 'values' => [1, 2]],
    ]);

    $outcome = Decision::for('state')
        ->choice('department', 'q', ['returns' => null, 'shipping' => null, 'billing' => null, 'other' => null])
        ->score('severity', 'q', ['Cosmetic', 'Degraded', 'Blocking'])
        ->noul('wants_human', 'q')
        ->rawQuestion('future', ['type' => 'vector'])
        ->decide();

    expect($outcome->engine)->toBe('fake')
        ->and($outcome->department->choice)->toBe('billing')
        ->and(round($outcome->department->confidence, 2))->toBe(0.95)
        ->and(round(array_sum($outcome->department->probabilities), 6))->toBe(1.0)
        ->and($outcome->department->ranked())->toHaveKey('billing')
        ->and(array_key_first($outcome->department->ranked()))->toBe('billing')
        ->and(round($outcome->severity->score, 6))->toBe(1.4)
        ->and($outcome->severity->level())->toBe(1)
        ->and($outcome->severity->levelDescription())->toBe('Degraded')
        ->and(round($outcome->severity->probabilities[2], 6))->toBe(0.4)
        ->and($outcome->wants_human->noul)->toBe(0.1)
        ->and($outcome->wants_human->isFalse(0.9))->toBeTrue()
        ->and($outcome->answer('future')->get('values'))->toBe([1, 2])
        ->and($outcome->meta->calibrated)->toBeTrue()
        ->and($outcome->usage->inputTokens)->toBeGreaterThan(0)
        ->and($outcome->meta->requestId)->toStartWith('fake_');
});

it('answers unspecified questions with neutral defaults', function (): void {
    Decision::fake();

    $outcome = Decision::for('s')->choice('c', 'q', ['a' => null, 'b' => null])->score('s', 'q', ['lo', 'hi'])->noul('n', 'q')->decide();

    expect($outcome->c->choice)->toBe('a')
        ->and($outcome->c->band())->toBe(Certainty::Medium)
        ->and($outcome->s->score)->toBe(0.0)
        ->and($outcome->n->noul)->toBe(0.5)
        ->and($outcome->n->band())->toBe(Certainty::Low);
});

it('supports a per-request resolver returning answers, a response or an exception', function (): void {
    Decision::fake(function (DecisionRequest $request) {
        if ($request->state->value() === 'boom') {
            return HttpResponse::jsonResponse(500, ['message' => 'kaboom']);
        }

        if ($request->state->value() === 'throw') {
            return new RuntimeException('resolver failure');
        }

        return ['urgent' => Fake::noul($request->hasQuestion('vip') ? 0.99 : 0.2)];
    });

    expect(Decision::for('a')->noul('urgent', 'q')->noul('vip', 'q')->decide()->urgent->noul)->toBe(0.99)
        ->and(Decision::for('a')->noul('urgent', 'q')->decide()->urgent->noul)->toBe(0.2)
        ->and(fn() => Decision::for('boom')->noul('urgent', 'q')->decide())->toThrow(ServerException::class, 'kaboom')
        ->and(fn() => Decision::for('throw')->noul('urgent', 'q')->decide())->toThrow(RuntimeException::class, 'resolver failure');
});

it('records decisions and offers Http::fake-style assertions', function (): void {
    $fake = Decision::fake();
    $fake->assertNothingDecided();

    Decision::for('one')->noul('department', 'q')->decide();
    Decision::for('two')->noul('other', 'q')->decide();

    $fake->assertDecided();
    $fake->assertDecided(fn(DecisionRequest $r) => $r->hasQuestion('department'));
    $fake->assertDecided(fn(DecisionRequest $r) => $r->hasQuestion('department'), times: 1);
    $fake->assertDecidedCount(2);
    $fake->assertNotDecided(fn(DecisionRequest $r) => $r->hasQuestion('nope'));

    expect($fake->recorded())->toHaveCount(2)
        ->and($fake->outcomes())->toHaveCount(2)
        ->and($fake->recorded()[1]->state->value())->toBe('two')
        ->and(fn() => $fake->assertNothingDecided())->toThrow(AssertionFailedError::class)
        ->and(fn() => $fake->assertDecidedCount(5))->toThrow(AssertionFailedError::class, 'Expected 5 decision(s), 2 made.')
        ->and(fn() => $fake->assertDecided(fn(DecisionRequest $r) => false))->toThrow(AssertionFailedError::class);
});

it('restores the real resolver', function (): void {
    Decision::fake();
    expect(Decision::resolveClient()->engine()->name())->toBe('fake');

    Decision::restore();
    Decision::resolveClientUsing(fn() => throw new LogicException('real resolver used'));
    expect(fn() => Decision::resolveClient())->toThrow(LogicException::class, 'real resolver used');
    Decision::resolveClientUsing(null);
});

it('exposes FakeEngine::respond() for hand-built fixtures', function (): void {
    $engine = new FakeEngine(calibrated: false);
    $request = Decision::for('s')->choice('c', 'q', ['a' => null, 'b' => null])->request();
    $body = $engine->respond($request, ['c' => Fake::choice('b', 1.0), 'extra' => Fake::noul(0.3)]);

    expect($body['answers']['c']['probabilities'])->toBe(['a' => 0.0, 'b' => 1.0])
        ->and($body['answers']['c']['confidence'])->toBe(1.0)
        ->and($body['answers']['extra']['noul'])->toBe(0.3)
        ->and($engine->capabilities()->calibrated)->toBeFalse()
        ->and($engine->prepare($request)->url)->toBe('fake://decision')
        ->and($engine->interpret($request, HttpResponse::jsonResponse(200, $body))->meta->calibrated)->toBeFalse();

    // A choice outside the option set is appended so probabilities still sum to one.
    $body = $engine->respond($request, ['c' => Fake::choice('z', 0.5)]);
    expect(array_keys($body['answers']['c']['probabilities']))->toBe(['a', 'b', 'z'])
        ->and(round(array_sum($body['answers']['c']['probabilities']), 6))->toBe(1.0);
});

it('fakes hand-built clients, named engines and engine instances, and runs their hooks', function (): void {
    $transport = new FakeTransport(); // would throw if anything were sent
    $client = new Client(EngineManager::fromArray(['default' => 'jev', 'engines' => ['jev' => ['driver' => 'jev', 'api_key' => 'k']]]), $transport);
    $seen = [];
    $client->after(function (DecisionRequest $request, Outcome $outcome) use (&$seen): void {
        $seen[] = $outcome->engine;
    });

    $fake = Decision::fake(['urgent' => Fake::noul(0.8)]);

    $outcomes = [
        $client->for('a')->noul('urgent', 'q')->decide(),
        $client->for('b')->using('luna')->noul('urgent', 'q')->decide(),
        $client->for('c')->using(new JevEngine('other-key'))->noul('urgent', 'q')->decide(),
    ];

    expect(array_map(fn(Outcome $o): float => $o->urgent->noul, $outcomes))->toBe([0.8, 0.8, 0.8])
        ->and($seen)->toBe(['fake', 'fake', 'fake'])
        ->and($transport->sent())->toBe([])
        ->and($fake->outcomes())->toHaveCount(3);

    $fake->assertDecided(fn(DecisionRequest $r) => $r->engine === 'luna', times: 1);
});

it('refuses to answer from a fake that was restored', function (): void {
    $fake = Decision::fake();
    Decision::restore();

    expect(fn() => $fake->client()->for('x')->noul('n', 'q')->decide())->toThrow(LogicException::class, 'no longer active');
});
