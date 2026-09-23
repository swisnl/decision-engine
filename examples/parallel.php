<?php

declare(strict_types=1);

/*
 * Parallel decisions: many questions in one request (A), many requests concurrently (B–D).
 *
 *   php examples/parallel.php            → runs against an in-process fake with 200 ms simulated latency
 *   TYPESAFE_API_KEY=… php examples/parallel.php → runs against the real API
 *
 * The key may also come from a local .env in the package root.
 */

require __DIR__ . '/bootstrap.php';

use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Config;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Testing\FakeConcurrentTransport;
use Swis\DecisionEngine\Transport\HttpResponse;

loadEnv();

$live = envValue('TYPESAFE_API_KEY') !== '';

if ($live) {
    Decision::resolveClientUsing(fn(): Client => Client::fromConfig(Config::fromEnv()));
} else {
    $transport = new FakeConcurrentTransport(resolver: fn(): HttpResponse => HttpResponse::jsonResponse(200, [
        'model' => 'jev-fake',
        'answers' => ['is_spam' => ['type' => 'noul', 'noul' => round(mt_rand(0, 100) / 100, 2)]],
        'usage' => ['input_tokens' => 50, 'output_tokens' => 2],
    ])->withLatency(200));
    Decision::resolveClientUsing(fn(): Client => new Client(EngineManager::fromArray(['engines' => ['jev' => ['driver' => 'jev', 'api_key' => 'fake']]]), $transport));
}

$messages = [];
for ($i = 1; $i <= 20; $i++) {
    $messages["msg-{$i}"] = $i % 3 === 0 ? "Congratulations! You won a prize, click here #{$i}" : "Hi, quick question about my order #{$i}";
}

// C. Same question, many states — concurrency 10.
$start = microtime(true);
$outcomes = Decision::forEach($messages)->noul('is_spam', 'Is this message spam?')->decide(concurrency: 10);
$elapsed = round((microtime(true) - $start) * 1000);

printf("forEach: %d decisions at concurrency 10 in %d ms (%s)\n", count($outcomes), $elapsed, $live ? 'live' : 'fake, 200 ms latency each → ~400 ms expected');

foreach (array_slice($outcomes, 0, 5, true) as $id => $outcome) {
    printf("  %-7s spam=%.2f %s\n", $id, $outcome->is_spam->noul, $outcome->is_spam->isTrue(0.8) ? 'SPAM' : '');
}

// Same batch, sequential, for comparison.
$start = microtime(true);
foreach (array_slice($messages, 0, 5, true) as $message) {
    Decision::for($message)->noul('is_spam', 'Is this message spam?')->decide();
}
printf("sequential: 5 decisions in %d ms\n", round((microtime(true) - $start) * 1000));

// D. Sequential code per item, items concurrent (fetch-then-ask style cascade).
$start = microtime(true);
$results = Decision::parallel([
    'a' => fn(): string => Decision::for($messages['msg-3'])->noul('is_spam', 'Spam?')->decide()->is_spam->isTrue() ? 'spam → verify' : 'ok',
    'b' => fn(): string => Decision::for($messages['msg-4'])->noul('is_spam', 'Spam?')->decide()->is_spam->isTrue() ? 'spam → verify' : 'ok',
]);
printf("parallel closures: %s in %d ms\n", json_encode($results), round((microtime(true) - $start) * 1000));
