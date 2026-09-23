<?php

declare(strict_types=1);

/*
 * Intent routing: classify incoming requests and route each to the optimal handler —
 * deterministic code, a specialist LLM, or a human. Jev decides the route in ~100 ms; expensive
 * models only run when they add value.
 */

require __DIR__ . '/bootstrap.php';

use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Questions\Choice;

$live = bootstrap();
banner('Intent routing', $live);

$intent = Choice::make('intent', 'What does the user want?')
    ->option('order_status', what: 'Where is my order / delivery status', examples: ['where is my package', 'has it shipped'])
    ->option('cancel_order', what: 'Cancel or change an order that has not shipped', notFor: 'Returns of delivered items')
    ->option('return_item', what: 'Return or exchange a delivered item', examples: ['wrong size', 'arrived damaged'])
    ->option('complaint', what: 'Expresses dissatisfaction that needs a human touch')
    ->option('chit_chat', what: 'Greetings, thanks, small talk')
    ->option('other');

$requests = [
    'Where is order 8841?',
    'I want to send back the shoes, they are too small',
    'This is the third time you people mess up my delivery. Unacceptable.',
    'thanks, bye!',
];

foreach ($requests as $text) {
    $outcome = Decision::for($text)->ask($intent)->noul('needs_account_lookup', 'Does answering require looking up the customer account or order?')->decide();
    $choice = $outcome->choice('intent');

    $handler = match (true) {
        $choice->confidence < 0.5 => 'human agent (unclear intent)',
        $choice->is('order_status') => 'deterministic: order-tracking API',
        $choice->is('cancel_order'), $choice->is('return_item') => 'workflow: self-service form',
        $choice->is('complaint') => 'human agent',
        $choice->is('chit_chat') => 'canned reply',
        default => 'specialist LLM assistant',
    };

    printf("%-70s %-14s %.2f → %s%s\n", $text, $choice->choice, $choice->confidence, $handler, $outcome->needs_account_lookup->isTrue(0.7) ? ' (+account lookup)' : '');
}
