<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Load a JSON fixture from tests/Fixtures and decode it to an array.
     *
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        $path = __DIR__ . '/Fixtures/' . $name . '.json';
        $json = file_get_contents($path);

        if ($json === false) {
            throw new \RuntimeException("Fixture {$name} not found at {$path}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
