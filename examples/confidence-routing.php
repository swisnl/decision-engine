<?php

declare(strict_types=1);

/*
 * Confidence-gated routing: the answer says WHAT, confidence says WHETHER to act.
 * Thresholds scale with stakes and live in your code, never in the library.
 */

require __DIR__ . '/bootstrap.php';

use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Outcome\Certainty;
use Swis\DecisionEngine\Outcome\Thresholds;

$live = bootstrap();
banner('Confidence-gated routing', $live);

$messages = [
    'Show me my current balance please',
    'Go ahead and approve the pending transfer of €4,000',
    'Something is wrong with my account, I think',
];

$thresholds = new Thresholds(high: 0.9, medium: 0.5);

foreach ($messages as $message) {
    $action = Decision::for($message)
        ->choice('action', 'What is the user trying to do?', [
            'check_balance' => 'View account balance',
            'approve_transfer' => 'Approve a pending withdrawal or transfer',
            'support' => 'Get help with an issue',
        ])
        ->decide()
        ->choice('action');

    if (! $action->band($thresholds)->atLeast(Certainty::Medium)) {
        printf("%-55s → human (confidence %.2f)\n", $message, $action->confidence);

        continue;
    }

    $route = match ($action->choice) {
        'check_balance' => 'show balance (low stakes, act)',                                   // wrong screen is recoverable
        'approve_transfer' => $action->isConfident(0.9) ? 'confirm then execute' : 'ask user to confirm first', // high stakes
        default => 'open support flow',
    };

    printf("%-55s → %s [%s %.2f]\n", $message, $route, $action->choice, $action->confidence);
}
