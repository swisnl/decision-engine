<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines\Jev;

use Swis\DecisionEngine\Support\Arr;

/**
 * One entry from `GET /v1/models`.
 */
final class ModelCard
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?string $releaseDate,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::string($data, 'name') ?? throw new \InvalidArgumentException('Model card is missing [name].'),
            Arr::string($data, 'description') ?? '',
            Arr::string($data, 'release_date') ?? Arr::string($data, 'releaseDate'),
        );
    }

    /**
     * @return array{name: string, description: string, release_date: string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'release_date' => $this->releaseDate];
    }
}
