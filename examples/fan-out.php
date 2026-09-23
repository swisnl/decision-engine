<?php

declare(strict_types=1);

/*
 * Speculative fan-out: ask everything you might need in ONE request (≈10× faster and cheaper than
 * one call per question), then let code decide what is relevant.
 */

require __DIR__ . '/bootstrap.php';

use Swis\DecisionEngine\Decision;

$live = bootstrap();
banner('Speculative fan-out', $live);

$ticket = "Hi, I've been trying to connect my Stripe account for 3 days and the integration keeps failing. I'm losing sales. Please help ASAP.";

$outcome = Decision::for($ticket)
    ->choice('department', 'Which team should handle this?', [
        'billing' => 'Payment or subscription issues',
        'technical' => 'Bugs or integration problems',
        'sales' => 'Pricing or account questions',
        'other' => null,
    ])
    ->score('frustration', 'How frustrated does the customer appear?', ['Calm, just stating facts', 'Frustrated but civil', 'Very angry, strong language'])
    ->noul('is_urgent', 'Does the message convey urgency or time-sensitivity?')
    ->noul('mentions_revenue_loss', 'Does the customer say they are losing money or sales?')
    ->noul('wants_human', 'Is the customer explicitly asking for a human agent?')
    ->noul('is_spam', 'Is this message spam or unrelated to the business?')     // speculative: cheap to ask, used only if true
    ->decide();

printf("department: %s (confidence %.2f)\n", $outcome->department->choice, $outcome->department->confidence);
printf("frustration: %.2f / 2 → level %d\n", $outcome->frustration->score, $outcome->frustration->level());

foreach (['is_urgent', 'mentions_revenue_loss', 'wants_human', 'is_spam'] as $flag) {
    printf("%-22s %.2f %s\n", $flag, $outcome->noul($flag)->noul, $outcome->noul($flag)->isTrue(0.8) ? '✔' : '');
}

// Code decides what matters; the model only answered narrow questions.
if ($outcome->is_spam->isTrue(0.9)) {
    echo "→ discard\n";
} elseif ($outcome->is_urgent->isTrue(0.8) && $outcome->mentions_revenue_loss->isTrue(0.8)) {
    echo "→ priority queue for {$outcome->department->choice}\n";
} else {
    echo "→ normal queue for {$outcome->department->choice}\n";
}

printf("tokens: %d in / %d out, engine=%s calibrated=%s\n", $outcome->usage->inputTokens, $outcome->usage->outputTokens, $outcome->engine, $outcome->meta->calibrated ? 'yes' : 'no');
