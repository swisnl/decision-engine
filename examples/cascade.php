<?php

declare(strict_types=1);

/*
 * Two-stage cascade: a cheap batch pass over many items (one question, many states, concurrent),
 * then a second, richer request only for the top-k candidates.
 */

require __DIR__ . '/bootstrap.php';

use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Outcome\Outcome;

$live = bootstrap();
banner('Two-stage cascade (re-ranking)', $live);

$vacancy = 'Senior PHP developer, Laravel, 5+ years, leads a small team, Dutch-speaking, Leiden.';

$candidates = [
    'c1' => 'Backend developer, 7 years PHP/Laravel, mentored juniors, Amsterdam, Dutch native.',
    'c2' => 'Frontend engineer, React and TypeScript, 4 years, remote, English only.',
    'c3' => 'Full-stack developer, PHP and Vue, 6 years, tech lead for 3 people, Utrecht.',
    'c4' => 'Junior developer, bootcamp graduate, Python and Django.',
    'c5' => 'Symfony specialist, 10 years, architect role, The Hague, Dutch.',
    'c6' => 'Data engineer, Spark, Airflow, 5 years.',
];

// Stage 1: cheap screen, all candidates concurrently, one atomic question each.
$start = microtime(true);
$screen = Decision::forEach($candidates, fn(string $cv): array => ['vacancy' => $vacancy, 'candidate' => $cv])
    ->score('relevance', 'How relevant is `candidate` for `vacancy`?', ['Not relevant', 'Partially relevant', 'Strong match'])
    ->decide(concurrency: 10);
printf("stage 1: %d candidates screened in %d ms\n", count($screen), (microtime(true) - $start) * 1000);

uasort($screen, fn(Outcome $a, Outcome $b): int => $b->relevance->score <=> $a->relevance->score);
$top = array_slice($screen, 0, 3, true);

foreach ($screen as $id => $outcome) {
    printf("  %s relevance %.2f%s\n", $id, $outcome->relevance->score, isset($top[$id]) ? '  ← top-3' : '');
}

// Stage 2: richer questions for the shortlist only.
$start = microtime(true);
$verified = Decision::forEach(array_intersect_key($candidates, $top), fn(string $cv): array => ['vacancy' => $vacancy, 'candidate' => $cv])
    ->noul('meets_experience', 'Does `candidate` have at least the years of experience `vacancy` asks for?', true: 'Years stated and sufficient', false: 'Fewer years or not stated')
    ->noul('has_leadership', 'Does `candidate` show team-lead or mentoring experience?')
    ->noul('speaks_dutch', 'Does `candidate` indicate speaking Dutch?')
    ->score('overall', 'Overall fit of `candidate` for `vacancy`', ['Reject', 'Maybe', 'Interview'])
    ->decide();
printf("stage 2: %d candidates verified in %d ms\n", count($verified), (microtime(true) - $start) * 1000);

foreach ($verified as $id => $outcome) {
    printf(
        "  %s overall %.2f  experience %.2f  leadership %.2f  dutch %.2f → %s\n",
        $id,
        $outcome->overall->score,
        $outcome->meets_experience->noul,
        $outcome->has_leadership->noul,
        $outcome->speaks_dutch->noul,
        $outcome->overall->level() === 2 && $outcome->speaks_dutch->isTrue(0.7) ? 'invite' : 'hold',
    );
}
