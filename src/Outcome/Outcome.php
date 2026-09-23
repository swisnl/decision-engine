<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Outcome;

use Swis\DecisionEngine\Answers\AnswerFactory;
use Swis\DecisionEngine\Answers\ChoiceAnswer;
use Swis\DecisionEngine\Answers\NoulAnswer;
use Swis\DecisionEngine\Answers\ScoreAnswer;
use Swis\DecisionEngine\Contracts\Answer;
use Swis\DecisionEngine\Contracts\Arrayable;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Support\Json;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * The result of one decision: the answers plus model, engine, usage and metadata.
 *
 * Answers are reachable as properties, array offsets, or via `answer()`; the typed accessors
 * `choice()` / `score()` / `noul()` add a type check.
 *
 * ```php
 * $outcome->department->choice;        // 'billing'
 * $outcome['severity']->score;         // 1.43
 * $outcome->noul('wants_human')->isTrue(0.9);
 * $outcome->usage->inputTokens;        // 360
 * $outcome->model;                     // 'jev-1.13.0'
 * $outcome->meta->calibrated;          // true
 * $outcome->raw();                     // full decoded body
 * Outcome::fromArray($outcome->toArray());
 * ```
 *
 * @implements \ArrayAccess<string, Answer>
 * @implements \IteratorAggregate<string, Answer>
 */
final class Outcome implements \ArrayAccess, \Countable, \IteratorAggregate, Arrayable
{
    /**
     * @param  array<string, Answer>  $answers
     * @param  array<string, mixed>|null  $raw
     */
    public function __construct(
        public readonly array $answers,
        public readonly string $model,
        public readonly string $engine,
        public readonly Usage $usage,
        public readonly Meta $meta,
        private readonly ?array $raw = null,
        private readonly ?HttpResponse $response = null,
        private readonly ?PreparedRequest $request = null,
    ) {}

    /**
     * Rebuild from `toArray()` output (caches, fixtures, audit logs).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?Thresholds $thresholds = null): self
    {
        $answers = Arr::array($data, 'answers') ?? throw new \InvalidArgumentException('Outcome array is missing [answers].');
        $raw = Arr::array($data, 'raw');

        return new self(
            answers: AnswerFactory::fromAnswers($answers, $thresholds),
            model: Arr::string($data, 'model') ?? '',
            engine: Arr::string($data, 'engine') ?? '',
            usage: Usage::fromArray(Arr::stringKeys(Arr::array($data, 'usage') ?? [])),
            meta: Meta::fromArray(Arr::stringKeys(Arr::array($data, 'meta') ?? [])),
            raw: $raw === null ? null : Arr::stringKeys($raw),
        );
    }

    public static function fromJson(string $json, ?Thresholds $thresholds = null): self
    {
        /** @var array<string, mixed> $data */
        $data = Json::decodeArray($json);

        return self::fromArray($data, $thresholds);
    }

    public function answer(string $id): Answer
    {
        return $this->answers[$id] ?? throw new \OutOfBoundsException("The outcome has no answer for question [{$id}]. Available: " . implode(', ', $this->ids()) . '.');
    }

    public function choice(string $id): ChoiceAnswer
    {
        $answer = $this->answer($id);

        return $answer instanceof ChoiceAnswer ? $answer : throw new \UnexpectedValueException("Answer [{$id}] is not a choice answer.");
    }

    public function score(string $id): ScoreAnswer
    {
        $answer = $this->answer($id);

        return $answer instanceof ScoreAnswer ? $answer : throw new \UnexpectedValueException("Answer [{$id}] is not a score answer.");
    }

    public function noul(string $id): NoulAnswer
    {
        $answer = $this->answer($id);

        return $answer instanceof NoulAnswer ? $answer : throw new \UnexpectedValueException("Answer [{$id}] is not a noul answer.");
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->answers);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->answers);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, Answer>
     */
    public function only(array $ids): array
    {
        return Arr::only($this->answers, $ids);
    }

    /**
     * The full decoded response body, or the answers map when the outcome was rebuilt without it.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw ?? ['model' => $this->model, 'answers' => array_map(static fn(Answer $a): array => $a->raw(), $this->answers), 'usage' => $this->usage->toArray()];
    }

    public function response(): ?HttpResponse
    {
        return $this->response;
    }

    public function request(): ?PreparedRequest
    {
        return $this->request;
    }

    public function withThresholds(Thresholds $thresholds): self
    {
        return new self(
            array_map(static fn(Answer $a): Answer => $a->withThresholds($thresholds), $this->answers),
            $this->model,
            $this->engine,
            $this->usage,
            $this->meta,
            $this->raw,
            $this->response,
            $this->request,
        );
    }

    public function withMeta(Meta $meta): self
    {
        return new self($this->answers, $this->model, $this->engine, $this->usage, $meta, $this->raw, $this->response, $this->request);
    }

    public function withRequest(?PreparedRequest $request): self
    {
        return new self($this->answers, $this->model, $this->engine, $this->usage, $this->meta, $this->raw, $this->response, $request);
    }

    public function __get(string $id): Answer
    {
        return $this->answer($id);
    }

    public function __isset(string $id): bool
    {
        return $this->has($id);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && $this->has($offset);
    }

    public function offsetGet(mixed $offset): Answer
    {
        if (! is_string($offset)) {
            throw new \InvalidArgumentException('Outcome offsets must be question ids (strings).');
        }

        return $this->answer($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Outcome is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Outcome is immutable.');
    }

    public function count(): int
    {
        return count($this->answers);
    }

    /**
     * @return \ArrayIterator<string, Answer>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->answers);
    }

    /**
     * `{engine, model, answers, usage, meta, raw?}`. `raw` is off by default to keep caches small.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includeRaw = false): array
    {
        $array = [
            'engine' => $this->engine,
            'model' => $this->model,
            'answers' => array_map(static fn(Answer $a): array => $a->toArray(), $this->answers),
            'usage' => $this->usage->toArray(),
            'meta' => $this->meta->toArray(),
        ];

        if ($includeRaw && $this->raw !== null) {
            $array['raw'] = $this->raw;
        }

        return $array;
    }

    public function toJson(bool $pretty = false, bool $includeRaw = false): string
    {
        return Json::encode($this->toArray($includeRaw), $pretty);
    }
}
