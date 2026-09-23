<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Support\Arr;

/**
 * Rate the state against ordered, descriptive levels (index 0 = lowest). The answer carries the
 * expected score (Σ level × probability, may be fractional), a legend, a probability per level
 * and a confidence.
 *
 * Immutable: `level()` and `levels()` return new instances.
 *
 * ```php
 * $q = Score::make('severity', 'How severe is the reported issue?')
 *     ->level('Cosmetic; no impact to functionality')
 *     ->level('Broken or degraded feature, but workaround exists')
 *     ->level(what: 'Blocking issue; no workaround exists', examples: ['cannot log in', 'data loss']);
 * ```
 */
final class Score implements Question
{
    /**
     * @param  array<array-key, mixed>|string|null  $instructions
     * @param  list<array<array-key, mixed>|string|null>  $levels
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        private readonly string $id,
        private readonly array|string|null $instructions,
        private readonly array $levels,
        private readonly array $extra = [],
    ) {}

    /**
     * @param  list<mixed>|null  $levels  ordered level descriptions, lowest first
     */
    public static function make(string $id, mixed $instructions = null, ?array $levels = null): self
    {
        $score = new self($id, Description::normalize($instructions), []);

        return $levels === null ? $score : $score->levels($levels);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $id, array $data): static
    {
        $type = Arr::string($data, 'type', QuestionType::Score->value);

        if ($type !== QuestionType::Score->value) {
            throw new \InvalidArgumentException("Cannot build a Score from a question of type [{$type}].");
        }

        $criteria = $data['criteria'] ?? [];

        if (! is_array($criteria)) {
            throw new \InvalidArgumentException("Score [{$id}] criteria must be an array of level descriptions.");
        }

        return new self(
            $id,
            Description::normalize($data['instructions'] ?? null),
            self::normalizeLevels($criteria),
            Arr::except($data, ['type', 'instructions', 'criteria']),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): QuestionType
    {
        return QuestionType::Score;
    }

    /**
     * @return array<array-key, mixed>|string|null
     */
    public function instructions(): array|string|null
    {
        return $this->instructions;
    }

    public function withInstructions(mixed $instructions): self
    {
        return new self($this->id, Description::normalize($instructions), $this->levels, $this->extra);
    }

    /**
     * Append a level. Only `what` → plain description; with `examples`/`signals` → structured object.
     *
     * @param  list<mixed>|null  $examples
     * @param  list<mixed>|null  $signals
     */
    public function level(mixed $what = null, ?array $examples = null, ?array $signals = null): self
    {
        $levels = $this->levels;
        $levels[] = Description::make($what, null, $examples, $signals);

        return new self($this->id, $this->instructions, $levels, $this->extra);
    }

    /**
     * Replace all levels.
     *
     * @param  list<mixed>  $levels
     */
    public function levels(array $levels): self
    {
        return new self($this->id, $this->instructions, self::normalizeLevels($levels), $this->extra);
    }

    /**
     * @return list<array<array-key, mixed>|string|null>
     */
    public function criteria(): array
    {
        return $this->levels;
    }

    public function count(): int
    {
        return count($this->levels);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = ['type' => QuestionType::Score->value];

        if ($this->instructions !== null) {
            $array['instructions'] = $this->instructions;
        }

        $array['criteria'] = $this->levels;

        return $array + $this->extra;
    }

    /**
     * @param  array<array-key, mixed>  $levels
     * @return list<array<array-key, mixed>|string|null>
     */
    private static function normalizeLevels(array $levels): array
    {
        return array_values(array_map(Description::normalize(...), $levels));
    }
}
