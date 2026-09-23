<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Contracts;

/**
 * Anything that can be turned into a plain, JSON-safe PHP array.
 *
 * Implementations must return arrays that contain no objects or closures, so that the
 * result can be persisted, queued, hashed and fed back into the matching `fromArray()`.
 */
interface Arrayable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
