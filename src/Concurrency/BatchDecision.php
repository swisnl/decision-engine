<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Concurrency;

use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Typed\TypedOutcome;

/**
 * The same questions asked about many states, decided concurrently (re-ranking, batch
 * classification). Built by `Decision::forEach()`; question and engine methods mirror `Decision`.
 *
 * ```php
 * $outcomes = Decision::forEach($tickets->keyBy('id'), fn (Ticket $t) => ['message' => $t->body])
 *     ->noul('is_spam', 'Is this message spam?')
 *     ->decide(concurrency: 20);   // array<id, Outcome>
 * ```
 */
final class BatchDecision
{
    private readonly Decision $template;

    /**
     * @param  iterable<array-key, mixed>  $states  key → state (or item, when `$toState` is given)
     * @param  (\Closure(mixed, array-key): mixed)|null  $toState  derive the state from each item
     */
    public function __construct(
        private readonly iterable $states,
        private readonly ?\Closure $toState = null,
    ) {
        $this->template = Decision::for(null);
    }

    /**
     * @param  array<array-key, mixed>|null  $options
     */
    public function choice(string $id, mixed $instructions = null, ?array $options = null): self
    {
        $this->template->choice($id, $instructions, $options);

        return $this;
    }

    /**
     * @param  list<mixed>|null  $levels
     */
    public function score(string $id, mixed $instructions = null, ?array $levels = null): self
    {
        $this->template->score($id, $instructions, $levels);

        return $this;
    }

    public function noul(string $id, mixed $instructions = null, mixed $true = null, mixed $false = null): self
    {
        $this->template->noul($id, $instructions, $true, $false);

        return $this;
    }

    public function yesNo(string $id, mixed $instructions = null, mixed $true = null, mixed $false = null): self
    {
        return $this->noul($id, $instructions, $true, $false);
    }

    public function ask(Question ...$questions): self
    {
        $this->template->ask(...$questions);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $question
     */
    public function rawQuestion(string $id, array $question): self
    {
        $this->template->rawQuestion($id, $question);

        return $this;
    }

    public function using(string|Engine $engine): self
    {
        $this->template->using($engine);

        return $this;
    }

    public function model(?string $model): self
    {
        $this->template->model($model);

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->template->withHeaders($headers);

        return $this;
    }

    /**
     * @param  array<string, mixed>|RequestOptions  $options
     */
    public function withOptions(array|RequestOptions $options): self
    {
        $this->template->withOptions($options);

        return $this;
    }

    public function timeout(float $seconds): self
    {
        $this->template->timeout($seconds);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function engineOptions(array $options): self
    {
        $this->template->engineOptions($options);

        return $this;
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutator
     */
    public function withPayload(\Closure $mutator): self
    {
        $this->template->withPayload($mutator);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mergePayload(array $payload): self
    {
        $this->template->mergePayload($payload);

        return $this;
    }

    public function withClient(Client $client): self
    {
        $this->template->withClient($client);

        return $this;
    }

    /**
     * The shared question/engine configuration (state is replaced per item).
     */
    public function template(): Decision
    {
        return $this->template;
    }

    /**
     * One Decision per state, produced lazily so large batches do not allocate up front.
     *
     * @return \Generator<array-key, Decision>
     */
    public function decisions(): \Generator
    {
        foreach ($this->states as $key => $item) {
            $state = $this->toState === null ? $item : ($this->toState)($item, $key);

            yield $key => $this->template->clone()->state($state);
        }
    }

    /**
     * Decide every state concurrently; throws ParallelFailedException if any failed.
     *
     * @return array<array-key, Outcome>
     */
    public function decide(?int $concurrency = null): array
    {
        /** @var array<array-key, Outcome> $outcomes */
        $outcomes = $this->run(static fn(Decision $decision): Outcome => $decision->decide(), $concurrency, throw: true);

        return $outcomes;
    }

    /**
     * Decide every state concurrently; failed keys hold the Throwable, including a `$toState`
     * closure that threw for that item.
     *
     * @return array<array-key, Outcome|\Throwable>
     */
    public function settle(?int $concurrency = null): array
    {
        /** @var array<array-key, Outcome|\Throwable> $results */
        $results = $this->run(static fn(Decision $decision): Outcome => $decision->decide(), $concurrency, throw: false);

        return $results;
    }

    /**
     * Like decide(), mapped onto a TypedOutcome class per state.
     *
     * @template T of TypedOutcome
     *
     * @param  class-string<T>  $class
     * @return array<array-key, T>
     */
    public function decideAs(string $class, ?int $concurrency = null): array
    {
        /** @var array<array-key, T> $outcomes */
        $outcomes = $this->run(static fn(Decision $decision): TypedOutcome => $decision->decideAs($class), $concurrency, throw: true);

        return $outcomes;
    }

    /**
     * Like settle(), mapped onto a TypedOutcome class per state.
     *
     * @template T of TypedOutcome
     *
     * @param  class-string<T>  $class
     * @return array<array-key, T|\Throwable>
     */
    public function settleAs(string $class, ?int $concurrency = null): array
    {
        /** @var array<array-key, T|\Throwable> $results */
        $results = $this->run(static fn(Decision $decision): TypedOutcome => $decision->decideAs($class), $concurrency, throw: false);

        return $results;
    }

    /**
     * One task per state; `$toState` runs inside the task so its failure belongs to that key.
     *
     * @param  \Closure(Decision): mixed  $decide
     * @return array<array-key, mixed>
     */
    private function run(\Closure $decide, ?int $concurrency, bool $throw): array
    {
        $tasks = (function () use ($decide): \Generator {
            foreach ($this->states as $key => $item) {
                yield $key => fn(): mixed => $decide($this->template->clone()->state($this->toState === null ? $item : ($this->toState)($item, $key)));
            }
        })();

        return ParallelRunner::for($this->template->client())->run($tasks, $concurrency, $throw);
    }
}
