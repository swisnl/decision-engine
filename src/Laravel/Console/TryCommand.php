<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel\Console;

use Illuminate\Console\Command;
use Swis\DecisionEngine\Answers\ChoiceAnswer;
use Swis\DecisionEngine\Answers\NoulAnswer;
use Swis\DecisionEngine\Answers\ScoreAnswer;
use Swis\DecisionEngine\Contracts\Answer;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Exceptions\DecisionEngineException;
use Swis\DecisionEngine\Support\Json;

/**
 * `php artisan decision-engine:try path/to/decision.json [--engine=] [--model=] [--json] [--dry-run]`
 *
 * Runs a decision stored as JSON (canonical form or a bare payload copied from the TypeSafe
 * playground) and pretty-prints the outcome. `--dry-run` prints the HTTP request instead of sending it.
 */
final class TryCommand extends Command
{
    protected $signature = 'decision-engine:try
        {file : Path to a JSON file with state and questions}
        {--engine= : Named engine to use instead of the one in the file / the default}
        {--model= : Model override}
        {--json : Print the outcome as JSON}
        {--dry-run : Print the prepared HTTP request (redacted) without sending it}';

    protected $description = 'Run a JSON decision file and print the outcome';

    public function handle(): int
    {
        // Resolve through Decision so Decision::fake() applies in tests; in production this is the container's Client.
        $client = Decision::resolveClient();
        $file = $this->argument('file');

        if (! is_string($file) || ! is_file($file)) {
            $this->error('File not found: ' . (is_string($file) ? $file : ''));

            return self::FAILURE;
        }

        try {
            $decision = Decision::fromJson((string) file_get_contents($file))->withClient($client);

            $engine = $this->option('engine');
            $model = $this->option('model');

            if (is_string($engine) && $engine !== '') {
                $decision->using($engine);
            }

            if (is_string($model) && $model !== '') {
                $decision->model($model);
            }

            if ($this->option('dry-run') === true) {
                $prepared = $decision->toRequest();
                $this->line("{$prepared->method} {$prepared->url}");

                foreach ($prepared->redactedHeaders() as $name => $value) {
                    $this->line("{$name}: {$value}");
                }

                $this->line('');
                $this->line(Json::encode($prepared->json(), pretty: true));

                return self::SUCCESS;
            }

            $outcome = $decision->decide();
        } catch (DecisionEngineException|\JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line($outcome->toJson(pretty: true));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($outcome->answers as $id => $answer) {
            $rows[] = [$id, $answer->type()->value ?? 'unknown', self::describe($answer), number_format($answer->certainty(), 2), $answer->band()->value];
        }

        $this->table(['Question', 'Type', 'Answer', 'Certainty', 'Band'], $rows);
        $this->line(sprintf(
            'engine=%s model=%s calibrated=%s tokens=%d/%d latency=%sms request_id=%s',
            $outcome->engine,
            $outcome->model,
            $outcome->meta->calibrated ? 'yes' : 'no',
            $outcome->usage->inputTokens,
            $outcome->usage->outputTokens,
            $outcome->meta->latencyMs === null ? '-' : number_format($outcome->meta->latencyMs, 0),
            $outcome->meta->requestId ?? '-',
        ));

        return self::SUCCESS;
    }

    private static function describe(Answer $answer): string
    {
        return match (true) {
            $answer instanceof ChoiceAnswer => $answer->choice . ' (' . implode(', ', array_map(static fn(string $k, float $p): string => "{$k}=" . number_format($p, 2), array_keys($answer->probabilities), $answer->probabilities)) . ')',
            $answer instanceof ScoreAnswer => number_format($answer->score, 2) . ' / ' . ($answer->levels() - 1) . ' → level ' . $answer->level(),
            $answer instanceof NoulAnswer => number_format($answer->noul, 2) . ($answer->isTrue() ? ' (yes)' : ' (no)'),
            default => Json::encode($answer->raw()),
        };
    }
}
