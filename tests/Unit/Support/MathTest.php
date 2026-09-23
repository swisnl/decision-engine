<?php

declare(strict_types=1);

use Swis\DecisionEngine\Support\Math;

it('normalizes distributions, clamping and handling the all-zero case', function (): void {
    expect(Math::normalize(['a' => 2.0, 'b' => 2.0]))->toBe(['a' => 0.5, 'b' => 0.5])
        ->and(Math::normalize(['a' => 1.5, 'b' => -1.0]))->toBe(['a' => 1.0, 'b' => 0.0])
        ->and(Math::normalize([0 => 0.0, 1 => 0.0]))->toBe([0 => 0.5, 1 => 0.5])
        ->and(Math::normalize([]))->toBe([]);
});

it('takes the first key on an argmax tie', function (): void {
    expect(Math::argmax(['a' => 0.4, 'b' => 0.4, 'c' => 0.2]))->toBe('a')
        ->and(Math::argmax([0 => 0.0, 1 => 0.57, 2 => 0.43]))->toBe(1);
});

it('computes the expected score from the documented example', function (): void {
    expect(round(Math::expectedScore(['0' => 0.0, '1' => 0.57, '2' => 0.43]), 2))->toBe(1.43);
});

it('reproduces the documented confidence formula', function (): void {
    // Quickstart: technical 0.85 over 3 options → 0.775 (docs show 0.78)
    expect(round(Math::confidence(3, 0.85), 3))->toBe(0.775)
        ->and(round(Math::confidenceOf(['returns' => 0.05, 'shipping' => 0.04, 'billing' => 0.91]), 3))->toBe(0.865)
        ->and(Math::confidence(3, 1 / 3))->toBe(0.0)
        ->and(Math::confidence(4, 1.0))->toBe(1.0)
        ->and(Math::confidence(1, 1.0))->toBe(1.0)
        ->and(Math::confidence(2, 0.9))->toBe(Math::certaintyFromNoul(0.9));
});

it('derives noul certainty as |2p − 1|', function (): void {
    expect(Math::certaintyFromNoul(0.5))->toBe(0.0)
        ->and(Math::certaintyFromNoul(1.0))->toBe(1.0)
        ->and(round(Math::certaintyFromNoul(0.84), 2))->toBe(0.68)
        ->and(round(Math::certaintyFromNoul(0.1), 2))->toBe(0.8);
});
