<?php

declare(strict_types=1);

/*
 * Typed outcomes: the questions live as attributes on a class, the answers come back as typed
 * properties (an enum, a string, a full answer or a float). The same class runs on any engine.
 */

require __DIR__ . '/bootstrap.php';

use Swis\DecisionEngine\Answers\ScoreAnswer;
use Swis\DecisionEngine\Attributes\Choice;
use Swis\DecisionEngine\Attributes\Noul;
use Swis\DecisionEngine\Attributes\Option;
use Swis\DecisionEngine\Attributes\Score;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Typed\TypedOutcome;

enum Department: string
{
    #[Option('Exchanges, wrong or damaged items', notFor: 'Refund requests', examples: ['wrong size', 'arrived broken'])]
    case Returns = 'returns';

    #[Option('Delivery status, delays, lost packages')]
    case Shipping = 'shipping';

    #[Option('Charges, invoices, refunds, payment problems')]
    case Billing = 'billing';

    case Other = 'other';
}

final class TicketTriage extends TypedOutcome
{
    #[Choice('Which team should handle this?')]
    public readonly Department $department;

    #[Choice('Which language is the message written in?', options: ['nl' => 'Dutch', 'en' => 'English', 'other' => null])]
    public readonly string $language;

    #[Score('How severe is the reported issue?', levels: ['Cosmetic, no impact', 'Degraded, a workaround exists', 'Blocking, no workaround'])]
    public readonly ScoreAnswer $severity;

    #[Noul('Is the customer asking for a human agent or a phone call?')]
    public readonly float $wantsHuman;
}

$live = bootstrap();
banner('Typed outcomes', $live);

$tickets = [
    't1' => 'Mijn pakket is al een week onderweg en de track & trace doet niks.',
    't2' => 'I was charged twice for order 1182. Please call me, nobody answers my emails!',
    't3' => 'The lamp arrived with a cracked shade, can I exchange it?',
];

foreach (Decision::forEach($tickets, fn(string $body): array => ['message' => $body])->decideAs(TicketTriage::class) as $id => $triage) {
    $queue = match (true) {
        $triage->wantsHuman >= 0.8 => 'call back',
        $triage->severity->atLeast(1.5) => 'priority: ' . $triage->department->value,
        default => $triage->department->value,
    };

    printf("%s  %-9s %-3s severity %.2f  human %.2f  → %s\n", $id, $triage->department->name, $triage->language, $triage->severity->score, $triage->wantsHuman, $queue);
}
