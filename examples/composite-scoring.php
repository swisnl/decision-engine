<?php

declare(strict_types=1);

/*
 * Composite scoring: break a fuzzy judgment ("is this a good lead?") into atomic Score questions,
 * then combine them with weights you control in code. The model never does the arithmetic.
 */

require __DIR__ . '/bootstrap.php';

use Swis\DecisionEngine\Decision;

$live = bootstrap();
banner('Composite scoring', $live);

$lead = [
    'company' => 'Acme Logistics BV',
    'message' => 'We run 40 warehouses and our current WMS vendor is shutting down in Q1. Looking for a partner who can migrate us and build custom integrations. Budget approved.',
    'source' => 'inbound form',
];

$outcome = Decision::for($lead)
    ->score('fit', 'How well does this lead match a custom software agency?', [
        'No fit; asks for something we do not do',
        'Partial fit; some custom work involved',
        'Strong fit; custom software with integrations',
    ])
    ->score('urgency', 'How urgent is the need?', ['No timeline mentioned', 'Timeline within a year', 'Deadline within months'])
    ->score('budget_signal', 'How clear is the budget signal?', ['No mention of budget', 'Budget implied or being discussed', 'Budget explicitly approved'])
    ->score('size', 'How large is the organisation described?', ['Individual or very small team', 'Small or medium business', 'Large organisation with many sites'])
    ->decide();

$weights = ['fit' => 0.4, 'urgency' => 0.2, 'budget_signal' => 0.25, 'size' => 0.15];
$total = 0.0;

foreach ($weights as $id => $weight) {
    $score = $outcome->score($id);
    $total += $weight * $score->normalized();
    printf("%-14s %.2f/2  normalized %.2f  confidence %.2f  weight %.2f\n", $id, $score->score, $score->normalized(), $score->confidence, $weight);
}

printf("lead score: %.2f → %s\n", $total, match (true) {
    $total >= 0.7 => 'hot: call today',
    $total >= 0.4 => 'warm: nurture sequence',
    default => 'cold: newsletter',
});
