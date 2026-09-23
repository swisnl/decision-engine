<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Request;

use Swis\DecisionEngine\Contracts\Arrayable;
use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Serialization\DecisionRequestSerializer;
use Swis\DecisionEngine\State\State;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Support\Json;

/**
 * The immutable description of one decision: state + questions, plus which engine/model should
 * answer and how the request should be sent. Every wither returns a copy.
 *
 * `payload()` is the canonical body `{state, model?, questions} + merge`, with `withPayload()`
 * mutators applied last. Engines turn that payload into their own wire format.
 *
 * ```php
 * $request = new DecisionRequest(State::from('Hello'), QuestionSet::of(Noul::make('greeting', 'Is this a greeting?')));
 * $request->withModel('jev-1.13.0')->payload();
 * // ['state' => 'Hello', 'model' => 'jev-1.13.0', 'questions' => ['greeting' => ['type' => 'noul', 'instructions' => '...']]]
 *
 * DecisionRequest::fromArray($request->toArray())->toArray() === $request->toArray(); // true
 * ```
 */
final class DecisionRequest implements Arrayable
{
    /**
     * @param  array<string, mixed>  $engineOptions  opaque, handed to the Engine
     * @param  array<string, mixed>  $merge  shallow-merged into the canonical payload
     * @param  list<\Closure(array<string, mixed>): array<string, mixed>>  $mutators  applied to the payload, in order, last
     */
    public function __construct(
        public readonly State $state,
        public readonly QuestionSet $questions,
        public readonly ?string $engine = null,
        public readonly ?string $model = null,
        public readonly RequestOptions $options = new RequestOptions(),
        public readonly array $engineOptions = [],
        public readonly array $merge = [],
        private readonly array $mutators = [],
    ) {}

    /**
     * Accepts the canonical form (see DecisionRequestSerializer) or the bare TypeSafe wire form
     * `{state, model?, questions}`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return DecisionRequestSerializer::fromArray($data);
    }

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = Json::decodeArray($json);

        return self::fromArray($data);
    }

    public function withState(State $state): self
    {
        return $this->copy(state: $state);
    }

    public function withQuestions(QuestionSet $questions): self
    {
        return $this->copy(questions: $questions);
    }

    /**
     * Switching to another engine clears the model, like `Decision::using()`: a `jev-latest`
     * copied from the playground makes no sense for OpenAI.
     */
    public function withEngine(?string $engine): self
    {
        if ($engine === $this->engine) {
            return $this;
        }

        return $this->copy(engine: $engine, resetEngine: true, resetModel: true);
    }

    public function withModel(?string $model): self
    {
        return $this->copy(model: $model, resetModel: true);
    }

    public function withOptions(RequestOptions $options): self
    {
        return $this->copy(options: $this->options->merge($options));
    }

    /**
     * @param  array<string, mixed>  $engineOptions
     */
    public function withEngineOptions(array $engineOptions): self
    {
        return $this->copy(engineOptions: Arr::replaceRecursive($this->engineOptions, $engineOptions));
    }

    /**
     * @param  array<string, mixed>  $merge
     */
    public function withMerge(array $merge): self
    {
        /** @var array<string, mixed> $normalized */
        $normalized = Json::normalize($merge);

        return $this->copy(merge: array_replace($this->merge, $normalized));
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutator
     */
    public function withMutator(\Closure $mutator): self
    {
        return $this->copy(mutators: [...$this->mutators, $mutator]);
    }

    public function hasQuestion(string $id): bool
    {
        return $this->questions->has($id);
    }

    public function question(string $id): Question
    {
        return $this->questions->get($id);
    }

    /**
     * @return list<string>
     */
    public function questionIds(): array
    {
        return $this->questions->ids();
    }

    public function hasMutators(): bool
    {
        return $this->mutators !== [];
    }

    /**
     * The canonical body: `{state, model?, questions}` + merge, then mutators.
     * `model` is only present when set explicitly; engines fill in their default.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = ['state' => $this->state->value()];

        if ($this->model !== null) {
            $payload['model'] = $this->model;
        }

        $payload['questions'] = $this->questions->toArray();
        $payload = array_replace($payload, $this->merge);

        foreach ($this->mutators as $mutator) {
            $payload = $mutator($payload);
        }

        return $payload;
    }

    /**
     * Stable identity of this request (engine, model, payload, options). Useful as a cache key.
     */
    public function fingerprint(): string
    {
        return hash('sha256', Json::canonical($this->toArray()));
    }

    /**
     * Canonical array form. Mutators are applied and flattened, so the result never contains closures.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return DecisionRequestSerializer::toArray($this);
    }

    public function toJson(bool $pretty = false): string
    {
        return Json::encode($this->toArray(), $pretty);
    }

    /**
     * @param  array<string, mixed>|null  $engineOptions
     * @param  array<string, mixed>|null  $merge
     * @param  list<\Closure(array<string, mixed>): array<string, mixed>>|null  $mutators
     */
    private function copy(
        ?State $state = null,
        ?QuestionSet $questions = null,
        ?string $engine = null,
        bool $resetEngine = false,
        ?string $model = null,
        bool $resetModel = false,
        ?RequestOptions $options = null,
        ?array $engineOptions = null,
        ?array $merge = null,
        ?array $mutators = null,
    ): self {
        return new self(
            $state ?? $this->state,
            $questions ?? $this->questions,
            $resetEngine ? $engine : $this->engine,
            $resetModel ? $model : $this->model,
            $options ?? $this->options,
            $engineOptions ?? $this->engineOptions,
            $merge ?? $this->merge,
            $mutators ?? $this->mutators,
        );
    }
}
