<?php

declare(strict_types=1);

use Swis\DecisionEngine\State\State;

it('normalizes strings, arrays and null', function (): void {
    expect(State::from('hello')->value())->toBe('hello')
        ->and(State::from(['a' => 1])->value())->toBe(['a' => 1])
        ->and(State::from(null)->isNull())->toBeTrue()
        ->and(State::from(State::from('x'))->value())->toBe('x');
});

it('casts scalars to strings because state is text', function (): void {
    expect(State::from(42)->value())->toBe('42')->and(State::from(true)->value())->toBe('true');
});

it('unwraps JsonSerializable and Stringable, recursively', function (): void {
    $order = new class implements JsonSerializable {
        public function jsonSerialize(): array
        {
            return ['id' => 1, 'lines' => [['sku' => 'A']]];
        }
    };

    expect(State::from(['order' => $order, 'note' => new class implements Stringable {
        public function __toString(): string
        {
            return 'n';
        }
    }])->value())->toBe(['order' => ['id' => 1, 'lines' => [['sku' => 'A']]], 'note' => 'n']);
});

it('converts plain objects to arrays', function (): void {
    $o = new stdClass();
    $o->a = 1;

    expect(State::from($o)->value())->toBe(['a' => 1]);
});

it('supports only / except / with on array state', function (): void {
    $state = State::from(['a' => 1, 'b' => 2, 'c' => 3]);

    expect($state->only(['a', 'c'])->value())->toBe(['a' => 1, 'c' => 3])
        ->and($state->except(['a'])->value())->toBe(['b' => 2, 'c' => 3])
        ->and($state->with(['d' => 4])->value())->toBe(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4])
        ->and(State::null()->with(['x' => 1])->value())->toBe(['x' => 1]);
});

it('refuses only() on string state', function (): void {
    State::from('text')->only(['a']);
})->throws(LogicException::class);

it('reports byte length', function (): void {
    expect(State::from('abc')->byteLength())->toBe(5); // "abc" including quotes
});
