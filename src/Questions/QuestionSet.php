<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Serialization\QuestionFactory;

/**
 * An ordered, id-unique collection of questions. Immutable: `with()` / `without()` return copies.
 * Adding a question whose id already exists replaces it in place (Eloquent-style "last write wins").
 *
 * ```php
 * $set = QuestionSet::of(Noul::make('urgent', 'Is this urgent?'), Choice::make('lang', 'Language?', ['nl' => null, 'en' => null]));
 * $set->ids();            // ['urgent', 'lang']
 * $set->toArray();        // ['urgent' => ['type' => 'noul', ...], 'lang' => ['type' => 'choice', ...]]
 * QuestionSet::fromArray($set->toArray()); // equal set
 * ```
 *
 * @implements \IteratorAggregate<string, Question>
 */
final class QuestionSet implements \Countable, \IteratorAggregate
{
    /**
     * @param  array<string, Question>  $questions
     */
    private function __construct(
        private readonly array $questions = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    public static function of(Question ...$questions): self
    {
        return self::empty()->with(...$questions);
    }

    /**
     * Build from the wire form `{ id: {type, instructions, criteria} }`. Unknown types become RawQuestion.
     *
     * @param  array<array-key, mixed>  $questions
     */
    public static function fromArray(array $questions): self
    {
        $set = self::empty();

        foreach ($questions as $id => $data) {
            if (! is_array($data)) {
                throw new \InvalidArgumentException("Question [{$id}] must be an array, got " . get_debug_type($data) . '.');
            }

            /** @var array<string, mixed> $data */
            $set = $set->with(QuestionFactory::fromArray((string) $id, $data));
        }

        return $set;
    }

    public function with(Question ...$questions): self
    {
        $merged = $this->questions;

        foreach ($questions as $question) {
            $merged[$question->id()] = $question;
        }

        return new self($merged);
    }

    public function without(string ...$ids): self
    {
        return new self(array_diff_key($this->questions, array_fill_keys($ids, true)));
    }

    /**
     * @param  list<string>  $ids
     */
    public function only(array $ids): self
    {
        return new self(array_intersect_key($this->questions, array_fill_keys($ids, true)));
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->questions);
    }

    public function get(string $id): Question
    {
        return $this->questions[$id] ?? throw new \OutOfBoundsException("No question with id [{$id}].");
    }

    public function find(string $id): ?Question
    {
        return $this->questions[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->questions);
    }

    /**
     * @return array<string, Question>
     */
    public function all(): array
    {
        return $this->questions;
    }

    public function isEmpty(): bool
    {
        return $this->questions === [];
    }

    public function count(): int
    {
        return count($this->questions);
    }

    /**
     * @return \ArrayIterator<string, Question>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->questions);
    }

    /**
     * Wire form: `{ id: {type, instructions, criteria} }`, in insertion order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn(Question $q): array => $q->toArray(), $this->questions);
    }
}
