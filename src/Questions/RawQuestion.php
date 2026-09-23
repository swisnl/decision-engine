<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Questions;

use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Support\Json;

/**
 * Passthrough question: whatever array you give is sent as-is. Use it for API fields this
 * package does not model yet. Only `type` is validated (it must be a non-empty string).
 *
 * ```php
 * Decision::for($state)->rawQuestion('x', ['type' => 'choice', 'instructions' => '...', 'criteria' => [...], 'future_field' => 1]);
 * ```
 */
final class RawQuestion implements Question
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly string $id,
        private readonly array $data,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(string $id, array $data): self
    {
        /** @var array<string, mixed> $normalized */
        $normalized = Json::normalize($data);

        return new self($id, $normalized);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $id, array $data): static
    {
        return self::make($id, $data);
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * The known QuestionType if `type` matches one; throws for unknown types.
     * Use `rawType()` to read the string without the enum.
     */
    public function type(): QuestionType
    {
        $type = QuestionType::tryFrom($this->rawType() ?? '');

        if ($type === null) {
            throw new \LogicException("RawQuestion [{$this->id}] has an unknown type [{$this->rawType()}].");
        }

        return $type;
    }

    public function rawType(): ?string
    {
        return Arr::string($this->data, 'type');
    }

    public function isKnownType(): bool
    {
        return QuestionType::tryFrom($this->rawType() ?? '') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
