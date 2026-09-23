<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel\Console;

use Illuminate\Console\Command;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Engines\Jev\ModelCard;
use Swis\DecisionEngine\Exceptions\DecisionEngineException;

/**
 * `php artisan decision-engine:models [--engine=jev] [--json]` — lists the Jev models available to the account.
 */
final class ModelsCommand extends Command
{
    protected $signature = 'decision-engine:models {--engine= : Named engine to query (must use the jev driver)} {--json : Output JSON}';

    protected $description = 'List the TypeSafe models available to the configured API key';

    public function handle(): int
    {
        $client = Decision::resolveClient();
        $engine = $this->option('engine');

        try {
            $cards = $client->models(is_string($engine) && $engine !== '' ? $engine : null);
        } catch (DecisionEngineException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line((string) json_encode(array_map(static fn(ModelCard $c): array => $c->toArray(), $cards), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Name', 'Release date', 'Description'], array_map(static fn(ModelCard $c): array => [$c->name, $c->releaseDate ?? '-', $c->description], $cards));

        return self::SUCCESS;
    }
}
