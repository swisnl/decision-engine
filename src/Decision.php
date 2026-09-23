<?php

declare(strict_types=1);

namespace Swis\DecisionEngine;

use Swis\DecisionEngine\Concurrency\BatchDecision;
use Swis\DecisionEngine\Concurrency\ParallelRunner;
use Swis\DecisionEngine\Contracts\Arrayable;
use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\Lint;
use Swis\DecisionEngine\Questions\Noul;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Questions\RawQuestion;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Questions\Warning;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\State\State;
use Swis\DecisionEngine\Support\Json;
use Swis\DecisionEngine\Testing\DecisionFake;
use Swis\DecisionEngine\Testing\FakeAnswer;
use Swis\DecisionEngine\Typed\TypedOutcome;

/**
 * The fluent, Eloquent-style builder: `state + questions → answers`. Mutable for ergonomics
 * (every method returns `$this`); use `clone()` to branch.
 *
 * ```php
 * $outcome = Decision::for(['message' => $ticket->body])
 *     ->choice('department', 'Which team should handle this?', ['returns' => '…', 'billing' => '…', 'other' => null])
 *     ->score('severity', 'How severe is the reported issue?', ['Cosmetic', 'Degraded', 'Blocking'])
 *     ->noul('wants_human', 'Is the customer asking for a human agent?')
 *     ->decide();
 *
 * $outcome->department->choice;   // 'billing'
 * $outcome->severity->normalized(); // 0.715
 * $outcome->wants_human->isTrue(0.9);
 * ```
 *
 * The static entry points resolve a Client lazily from `Config::fromEnv()` unless
 * `Decision::resolveClientUsing()` was called (the Laravel bridge does this) or `->withClient()` is used.
 */
final class Decision implements Arrayable, \JsonSerializable
{
    /**
     * @var (\Closure(): Client)|null
     */
    private static ?\Closure $clientResolver = null;

    private static ?Client $resolvedClient = null;

    private State $state;

    private QuestionSet $questions;

    private ?string $engineName = null;

    private ?Engine $engineInstance = null;

    private ?string $model = null;

    private RequestOptions $options;

    /**
     * @var array<string, mixed>
     */
    private array $engineOptions = [];

    /**
     * @var array<string, mixed>
     */
    private array $merge = [];

    /**
     * @var list<\Closure(array<string, mixed>): array<string, mixed>>
     */
    private array $mutators = [];

    private ?Client $client = null;

    private function __construct(State $state)
    {
        $this->state = $state;
        $this->questions = QuestionSet::empty();
        $this->options = new RequestOptions();
    }

    // ── Entry points ─────────────────────────────────────────────────────────────

    /**
     * Start a decision about the given state (string, array, null, JsonSerializable, Stringable…).
     */
    public static function for(mixed $state): self
    {
        return new self(State::from($state));
    }

    /**
     * Rebuild from the canonical array form (or the bare TypeSafe wire form).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromRequest(DecisionRequest::fromArray($data));
    }

    public static function fromJson(string $json): self
    {
        return self::fromRequest(DecisionRequest::fromJson($json));
    }

    public static function fromRequest(DecisionRequest $request): self
    {
        $decision = new self($request->state);
        $decision->questions = $request->questions;
        $decision->engineName = $request->engine;
        $decision->model = $request->model;
        $decision->options = $request->options;
        $decision->engineOptions = $request->engineOptions;
        $decision->merge = $request->merge;

        return $decision;
    }

    /**
     * Same questions, many states, decided concurrently.
     *
     * @param  iterable<array-key, mixed>  $states
     * @param  (\Closure(mixed, array-key): mixed)|null  $toState  derive the state from each item
     */
    public static function forEach(iterable $states, ?\Closure $toState = null): BatchDecision
    {
        return new BatchDecision($states, $toState);
    }

    /**
     * Run many decisions (or closures containing decisions) concurrently, keys preserved.
     * Throws ParallelFailedException after everything settled if any task failed.
     *
     * @template K of array-key
     *
     * @param  iterable<K, Decision|\Closure(): mixed>  $tasks
     * @return array<K, mixed>
     */
    public static function parallel(iterable $tasks, ?int $concurrency = null, ?Client $client = null): array
    {
        return ParallelRunner::for($client ?? self::resolveClient())->run($tasks, $concurrency, throw: true);
    }

    /**
     * Like `parallel()` but never throws: failed keys hold the Throwable (Promise.allSettled).
     *
     * @template K of array-key
     *
     * @param  iterable<K, Decision|\Closure(): mixed>  $tasks
     * @return array<K, mixed>
     */
    public static function settle(iterable $tasks, ?int $concurrency = null, ?Client $client = null): array
    {
        return ParallelRunner::for($client ?? self::resolveClient())->run($tasks, $concurrency, throw: false);
    }

    /**
     * Tell the static entry points how to obtain a Client. Pass null to restore the default
     * (`Client::fromConfig(Config::fromEnv())`).
     *
     * @param  (\Closure(): Client)|null  $resolver
     */
    public static function resolveClientUsing(?\Closure $resolver): void
    {
        self::$clientResolver = $resolver;
        self::$resolvedClient = null;
    }

    public static function resolveClient(): Client
    {
        $resolver = self::$clientResolver ?? static fn(): Client => Client::fromConfig(Config::fromEnv());

        return self::$resolvedClient ??= $resolver();
    }

    /**
     * Answer every decision (any client, any engine) from a fake until `restore()`. See DecisionFake for the assertions.
     *
     * @param  array<string, FakeAnswer|array<string, mixed>>|\Closure|null  $answers  `Fake::choice()` etc. keyed by question id, or a resolver
     */
    public static function fake(array|\Closure|null $answers = null): DecisionFake
    {
        return DecisionFake::install($answers);
    }

    public static function restore(): void
    {
        DecisionFake::uninstall();
    }

    // ── Questions ────────────────────────────────────────────────────────────────

    /**
     * @param  array<array-key, mixed>|null  $options  option → description (`null` allowed)
     */
    public function choice(string $id, mixed $instructions = null, ?array $options = null): self
    {
        return $this->ask(Choice::make($id, $instructions, $options));
    }

    /**
     * @param  list<mixed>|null  $levels  ordered level descriptions, lowest first
     */
    public function score(string $id, mixed $instructions = null, ?array $levels = null): self
    {
        return $this->ask(Score::make($id, $instructions, $levels));
    }

    public function noul(string $id, mixed $instructions = null, mixed $true = null, mixed $false = null): self
    {
        $noul = Noul::make($id, $instructions);

        if ($true !== null || $false !== null) {
            $noul = $noul->criteria($true, $false);
        }

        return $this->ask($noul);
    }

    /**
     * Alias for `noul()`.
     */
    public function yesNo(string $id, mixed $instructions = null, mixed $true = null, mixed $false = null): self
    {
        return $this->noul($id, $instructions, $true, $false);
    }

    public function ask(Question ...$questions): self
    {
        $this->questions = $this->questions->with(...$questions);

        return $this;
    }

    /**
     * Send a question array exactly as given (forward compatibility escape hatch).
     *
     * @param  array<string, mixed>  $question
     */
    public function rawQuestion(string $id, array $question): self
    {
        return $this->ask(RawQuestion::make($id, $question));
    }

    public function without(string ...$ids): self
    {
        $this->questions = $this->questions->without(...$ids);

        return $this;
    }

    /**
     * Replace the state.
     */
    public function state(mixed $state): self
    {
        $this->state = State::from($state);

        return $this;
    }

    // ── Engine & transport ───────────────────────────────────────────────────────

    /**
     * Pick a named engine from configuration or pass an Engine instance.
     *
     * Switching to a different engine clears a previously set model (a `jev-latest` copied from the
     * playground makes no sense for OpenAI); call `model()` after `using()` to override the new
     * engine's default.
     */
    public function using(string|Engine $engine): self
    {
        $previous = $this->engineName;

        if ($engine instanceof Engine) {
            $this->engineInstance = $engine;
            $this->engineName = $engine->name();
        } else {
            $this->engineInstance = null;
            $this->engineName = $engine;
        }

        if ($previous !== $this->engineName) {
            $this->model = null;
        }

        return $this;
    }

    public function model(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        $this->options = $this->options->withHeaders($headers);

        return $this;
    }

    /**
     * Transport options: `timeout`, `connect_timeout`, `retry` (array or RetryPolicy), `headers`.
     *
     * @param  array<string, mixed>|RequestOptions  $options
     */
    public function withOptions(array|RequestOptions $options): self
    {
        $this->options = $this->options->merge($options instanceof RequestOptions ? $options : RequestOptions::fromArray($options));

        return $this;
    }

    public function timeout(float $seconds): self
    {
        $this->options = $this->options->withTimeout($seconds);

        return $this;
    }

    /**
     * Opaque options handed to the engine (e.g. `['reasoning' => ['effort' => 'low']]`,
     * `['merge_body' => [...]]`). Deep-merged with earlier calls.
     *
     * @param  array<string, mixed>  $options
     */
    public function engineOptions(array $options): self
    {
        $this->engineOptions = Support\Arr::replaceRecursive($this->engineOptions, $options);

        return $this;
    }

    /**
     * Mutate the canonical payload `{state, model?, questions}` right before the engine sees it.
     *
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutator
     */
    public function withPayload(\Closure $mutator): self
    {
        $this->mutators[] = $mutator;

        return $this;
    }

    /**
     * Shallow-merge keys into the canonical payload (the SDKs' `extra_body`).
     *
     * @param  array<string, mixed>  $payload
     */
    public function mergePayload(array $payload): self
    {
        /** @var array<string, mixed> $normalized */
        $normalized = Json::normalize($payload);
        $this->merge = array_replace($this->merge, $normalized);

        return $this;
    }

    public function withClient(Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    // ── Execution ────────────────────────────────────────────────────────────────

    /**
     * The immutable request this builder currently describes.
     */
    public function request(): DecisionRequest
    {
        return new DecisionRequest(
            $this->state,
            $this->questions,
            $this->engineName,
            $this->model,
            $this->options,
            $this->engineOptions,
            $this->merge,
            $this->mutators,
        );
    }

    /**
     * Validate and render the HTTP request without sending it.
     */
    public function toRequest(): PreparedRequest
    {
        return $this->client()->prepare($this->request(), $this->engineInstance);
    }

    public function decide(): Outcome
    {
        return $this->client()->execute($this->request(), $this->engineInstance);
    }

    /**
     * Ask the questions `$class` declares (alongside any asked here) and map the answers onto it.
     * The builder itself is left unchanged.
     *
     * @template T of TypedOutcome
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function decideAs(string $class): TypedOutcome
    {
        return $class::fromOutcome($this->clone()->ask(...$class::questions())->decide());
    }

    /**
     * Advisory checks (see Lint). Never runs automatically.
     *
     * @return list<Warning>
     */
    public function lint(): array
    {
        return Lint::run($this->questions, $this->state);
    }

    // ── Introspection & serialization ────────────────────────────────────────────

    public function getState(): State
    {
        return $this->state;
    }

    public function questions(): QuestionSet
    {
        return $this->questions;
    }

    public function engineName(): ?string
    {
        return $this->engineName;
    }

    public function engineInstance(): ?Engine
    {
        return $this->engineInstance;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function client(): Client
    {
        return $this->client ?? self::resolveClient();
    }

    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Canonical array form — the wire payload plus optional engine/model/options. Never contains closures.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->request()->toArray();
    }

    public function toJson(bool $pretty = false): string
    {
        return $this->request()->toJson($pretty);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
