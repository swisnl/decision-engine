<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Tests\PHPStan;

use Swis\DecisionEngine\Answers\ChoiceAnswer;
use Swis\DecisionEngine\Answers\ScoreAnswer;
use Swis\DecisionEngine\Attributes\Choice;
use Swis\DecisionEngine\Attributes\Noul;
use Swis\DecisionEngine\Attributes\Option;
use Swis\DecisionEngine\Attributes\Score;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Typed\TypedOutcome;

/*
 * Never executed: analysed by PHPStan (phpstan.neon) to prove typed outcomes are clean at level max
 * with extension.neon loaded, including the generic return types of decideAs() / settleAs().
 */

enum Department: string
{
    #[Option('Charges, invoices, payment problems')]
    case Billing = 'billing';

    case Other = 'other';
}

final class Triage extends TypedOutcome
{
    #[Choice('Which team should handle this?')]
    public readonly Department $department;

    #[Choice('Which team, with probabilities?', options: Department::class)]
    public readonly ChoiceAnswer $team;

    #[Score('How severe is the issue?', levels: ['Cosmetic', 'Blocking'])]
    public readonly ScoreAnswer $severity;

    #[Noul('Is the customer asking for a human agent?')]
    public readonly float $wantsHuman;
}

function route(string $message): string
{
    $triage = Decision::for(['message' => $message])->decideAs(Triage::class);

    if ($triage->wantsHuman > 0.8 || $triage->severity->atLeast(0.5)) {
        return 'escalate';
    }

    $team = $triage->team->as(Department::class);

    return $triage->department->value . '/' . $team->value . '/' . $triage->outcome()->model;
}

/**
 * @param  array<string, string>  $messages
 * @return array<array-key, string>
 */
function routeMany(array $messages): array
{
    $routes = [];

    foreach (Decision::forEach($messages)->decideAs(Triage::class) as $key => $triage) {
        $routes[$key] = $triage->department->value;
    }

    foreach (Decision::forEach($messages)->settleAs(Triage::class) as $key => $result) {
        $routes[$key] = $result instanceof Triage ? $result->department->value : $result->getMessage();
    }

    return $routes;
}
