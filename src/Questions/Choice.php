<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Support\Arr;

/**
 * Pick one option from a fixed set. The answer carries the chosen option, a probability per
 * option and a confidence.
 *
 * Immutable: `option()` and `instructions()` return new instances.
 *
 * ```php
 * $q = Choice::make('department', 'Which team should handle this?')
 *     ->option('returns', what: 'Exchanges, wrong or damaged items', notFor: 'Refund requests', examples: ['wrong size'])
 *     ->option('billing', 'Charges, invoices, payment problems')
 *     ->option('other');
 *
 * $q->toArray();
 * // ['type' => 'choice', 'instructions' => 'Which team should handle this?', 'criteria' => ['returns' => [...], 'billing' => '...', 'other' => null]]
 * ```
 */
final class Choice implements Question
{
    /**
     * @param  array<array-key, mixed>|string|null  $instructions
     * @param  array<string, array<array-key, mixed>|string|null>  $options  option name → description
     * @param  array<string, mixed>  $extra  unknown wire fields preserved for forward compatibility
     */
    private function __construct(
        private readonly string $id,
        private readonly array|string|null $instructions,
        private readonly array $options,
        private readonly array $extra = [],
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $options  option name → description, e.g. `['billing' => 'Charges', 'other' => null]`
     */
    public static function make(string $id, mixed $instructions = null, ?array $options = null): self
    {
        $choice = new self($id, Description::normalize($instructions), []);

        return $options === null ? $choice : $choice->options($options);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $id, array $data): static
    {
        $type = Arr::string($data, 'type', QuestionType::Choice->value);

        if ($type !== QuestionType::Choice->value) {
            throw new \InvalidArgumentException("Cannot build a Choice from a question of type [{$type}].");
        }

        $criteria = $data['criteria'] ?? [];

        if (! is_array($criteria)) {
            throw new \InvalidArgumentException("Choice [{$id}] criteria must be an object of option → description.");
        }

        return new self(
            $id,
            Description::normalize($data['instructions'] ?? null),
            self::normalizeOptions($criteria),
            Arr::except($data, ['type', 'instructions', 'criteria']),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): QuestionType
    {
        return QuestionType::Choice;
    }

    /**
     * @return array<array-key, mixed>|string|null
     */
    public function instructions(): array|string|null
    {
        return $this->instructions;
    }

    /**
     * Replace the instructions (string, array/object, or null).
     */
    public function withInstructions(mixed $instructions): self
    {
        return new self($this->id, Description::normalize($instructions), $this->options, $this->extra);
    }

    /**
     * Add (or replace) one option. Only `what` → plain description; with `notFor`/`examples` → structured object.
     *
     * @param  list<mixed>|null  $examples
     */
    public function option(string $name, mixed $what = null, mixed $notFor = null, ?array $examples = null): self
    {
        $options = $this->options;
        $options[$name] = Description::make($what, $notFor, $examples);

        return new self($this->id, $this->instructions, $options, $this->extra);
    }

    /**
     * Replace all options at once.
     *
     * @param  array<array-key, mixed>  $options  option name → description
     */
    public function options(array $options): self
    {
        return new self($this->id, $this->instructions, self::normalizeOptions($options), $this->extra);
    }

    /**
     * Remove an option.
     */
    public function withoutOption(string $name): self
    {
        return new self($this->id, $this->instructions, Arr::except($this->options, [$name]), $this->extra);
    }

    /**
     * @return array<string, array<array-key, mixed>|string|null>
     */
    public function criteria(): array
    {
        return $this->options;
    }

    /**
     * @return list<string>
     */
    public function optionNames(): array
    {
        return array_map(strval(...), array_keys($this->options));
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function count(): int
    {
        return count($this->options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = ['type' => QuestionType::Choice->value];

        if ($this->instructions !== null) {
            $array['instructions'] = $this->instructions;
        }

        $array['criteria'] = $this->options;

        return $array + $this->extra;
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<string, array<array-key, mixed>|string|null>
     */
    private static function normalizeOptions(array $options): array
    {
        /** @var array<string, array<array-key, mixed>|string|null> $normalized */
        $normalized = [];

        foreach ($options as $name => $description) {
            $normalized[(string) $name] = Description::normalize($description);
        }

        return $normalized;
    }
}
