<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\State;

use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Support\Json;

/**
 * The content a decision is evaluated against.
 *
 * State is text-only (a string, a JSON object/array, or null). Any PHP value is normalized to a
 * JSON-safe representation: `JsonSerializable` is unwrapped, `Stringable` becomes a string,
 * plain objects become arrays.
 *
 * ```php
 * $state = State::from(['message' => $ticket->body, 'order' => $order]); // $order may be JsonSerializable
 * $state->only(['message'])->value(); // ['message' => '...']
 * $state->isNull();                    // false
 * ```
 */
final class State implements \JsonSerializable
{
    /**
     * @param  array<array-key, mixed>|string|null  $value
     */
    private function __construct(
        private readonly array|string|null $value,
    ) {}

    /**
     * Normalize any value into a State. Scalars other than strings are cast to string,
     * because Jev evaluates text.
     */
    public static function from(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = Json::normalize($value);

        if (is_int($normalized) || is_float($normalized)) {
            $normalized = (string) $normalized;
        } elseif (is_bool($normalized)) {
            $normalized = $normalized ? 'true' : 'false';
        }

        return new self($normalized);
    }

    public static function null(): self
    {
        return new self(null);
    }

    /**
     * @return array<array-key, mixed>|string|null
     */
    public function value(): array|string|null
    {
        return $this->value;
    }

    public function isNull(): bool
    {
        return $this->value === null;
    }

    public function isString(): bool
    {
        return is_string($this->value);
    }

    public function isArray(): bool
    {
        return is_array($this->value);
    }

    /**
     * Keep only the given top-level keys. Sending only the relevant fields keeps the model
     * focused (irrelevant state distracts it) and reduces token cost.
     *
     * @param  list<array-key>  $keys
     */
    public function only(array $keys): self
    {
        if (! is_array($this->value)) {
            throw new \LogicException('State::only() requires an array state.');
        }

        return new self(Arr::only($this->value, $keys));
    }

    /**
     * Drop the given top-level keys.
     *
     * @param  list<array-key>  $keys
     */
    public function except(array $keys): self
    {
        if (! is_array($this->value)) {
            throw new \LogicException('State::except() requires an array state.');
        }

        return new self(Arr::except($this->value, $keys));
    }

    /**
     * Merge additional fields into an array state.
     *
     * @param  array<array-key, mixed>  $fields
     */
    public function with(array $fields): self
    {
        if ($this->value !== null && ! is_array($this->value)) {
            throw new \LogicException('State::with() requires an array or null state.');
        }

        /** @var array<array-key, mixed> $normalized */
        $normalized = Json::normalize($fields);

        return new self(array_replace($this->value ?? [], $normalized));
    }

    /**
     * Approximate byte size of the JSON-encoded state; useful for lint warnings and cost estimates.
     */
    public function byteLength(): int
    {
        return strlen(Json::encode($this->value));
    }

    /**
     * @return array<array-key, mixed>|string|null
     */
    public function jsonSerialize(): array|string|null
    {
        return $this->value;
    }
}
