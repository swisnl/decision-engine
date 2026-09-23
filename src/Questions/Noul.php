<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Support\Arr;

/**
 * A yes/no question. The answer is `noul`: the probability (0–1) that the answer is yes.
 * Noul answers carry no server-side confidence.
 *
 * ```php
 * Noul::make('wants_human', 'Is the customer asking for a human agent?');
 *
 * Noul::make('same_person')
 *     ->withInstructions(['potential_duplicate' => $record, 'question' => 'Is the resume for the same person?'])
 *     ->criteria(true: 'Mentions a prior ticket', false: 'No sign of previous contact');
 * ```
 */
final class Noul implements Question
{
    /**
     * @param  array<array-key, mixed>|string|null  $instructions
     * @param  array{true?: array<array-key, mixed>|string|null, false?: array<array-key, mixed>|string|null}|null  $criteria
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        private readonly string $id,
        private readonly array|string|null $instructions,
        private readonly ?array $criteria = null,
        private readonly array $extra = [],
    ) {}

    public static function make(string $id, mixed $instructions = null): self
    {
        return new self($id, Description::normalize($instructions));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $id, array $data): static
    {
        $type = Arr::string($data, 'type', QuestionType::Noul->value);

        if ($type !== QuestionType::Noul->value) {
            throw new \InvalidArgumentException("Cannot build a Noul from a question of type [{$type}].");
        }

        $criteria = $data['criteria'] ?? null;

        if ($criteria !== null && ! is_array($criteria)) {
            throw new \InvalidArgumentException("Noul [{$id}] criteria must be an object with `true` and/or `false` keys.");
        }

        return new self(
            $id,
            Description::normalize($data['instructions'] ?? null),
            $criteria === null ? null : self::normalizeCriteria($criteria),
            Arr::except($data, ['type', 'instructions', 'criteria']),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): QuestionType
    {
        return QuestionType::Noul;
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
        return new self($this->id, Description::normalize($instructions), $this->criteria, $this->extra);
    }

    /**
     * Describe what counts as yes and/or no. Pass `null` for a side to leave it out.
     */
    public function criteria(mixed $true = null, mixed $false = null): self
    {
        /** @var array{true?: array<array-key, mixed>|string|null, false?: array<array-key, mixed>|string|null} $criteria */
        $criteria = Arr::withoutNulls([
            'true' => Description::normalize($true),
            'false' => Description::normalize($false),
        ]);

        return new self($this->id, $this->instructions, $criteria === [] ? null : $criteria, $this->extra);
    }

    /**
     * @return array{true?: array<array-key, mixed>|string|null, false?: array<array-key, mixed>|string|null}|null
     */
    public function getCriteria(): ?array
    {
        return $this->criteria;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = ['type' => QuestionType::Noul->value];

        if ($this->instructions !== null) {
            $array['instructions'] = $this->instructions;
        }

        if ($this->criteria !== null) {
            $array['criteria'] = $this->criteria;
        }

        return $array + $this->extra;
    }

    /**
     * @param  array<array-key, mixed>  $criteria
     * @return array{true?: array<array-key, mixed>|string|null, false?: array<array-key, mixed>|string|null}
     */
    private static function normalizeCriteria(array $criteria): array
    {
        $normalized = [];

        foreach ($criteria as $key => $value) {
            // Keys arrive as the strings "true"/"false"; PHP never casts those to bool/int.
            $normalized[(string) $key] = Description::normalize($value);
        }

        /** @var array{true?: array<array-key, mixed>|string|null, false?: array<array-key, mixed>|string|null} $normalized */
        return $normalized;
    }
}
