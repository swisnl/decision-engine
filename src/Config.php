<?php

declare(strict_types=1);

namespace Swis\DecisionEngine;

use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Transport\RetryPolicy;

/**
 * Typed view of `config/decision-engine.php`. Framework-agnostic; the Laravel bridge feeds it the
 * merged config array.
 *
 * ```php
 * $config = Config::fromEnv();                       // defaults + TYPESAFE_API_KEY etc. from the environment
 * $config = Config::fromArray(require 'config/decision-engine.php');
 * $config->engines()->engine('haiku');
 * $config->retry->maxRetries;                        // 2
 * ```
 */
final class Config
{
    /**
     * @param  array<string, array<string, mixed>>  $engines
     * @param  array<string, mixed>  $transport
     * @param  array<string, mixed>  $logging
     */
    public function __construct(
        public readonly string $defaultEngine = 'jev',
        public readonly array $engines = [],
        public readonly array $transport = ['driver' => 'curl', 'timeout' => 10.0, 'connect_timeout' => 5.0],
        public readonly RetryPolicy $retry = new RetryPolicy(),
        public readonly int $concurrency = 10,
        public readonly Thresholds $thresholds = new Thresholds(),
        public readonly array $logging = ['channel' => null, 'level' => 'info'],
    ) {
        if ($concurrency < 1) {
            throw ConfigurationException::invalid('concurrency.default', 'must be at least 1');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $engines = [];

        foreach (Arr::array($config, 'engines') ?? [] as $name => $engineConfig) {
            if (! is_array($engineConfig)) {
                throw ConfigurationException::invalid("engines.{$name}", 'expected an array');
            }

            $engines[(string) $name] = Arr::stringKeys($engineConfig);
        }

        $concurrency = Arr::array($config, 'concurrency') ?? [];
        $defaults = new self();

        return new self(
            defaultEngine: Arr::string($config, 'default') ?? $defaults->defaultEngine,
            engines: $engines,
            transport: array_replace($defaults->transport, Arr::stringKeys(Arr::array($config, 'transport') ?? [])),
            retry: RetryPolicy::fromArray(Arr::stringKeys(Arr::array($config, 'retry') ?? [])),
            concurrency: Arr::int(Arr::stringKeys($concurrency), 'default') ?? $defaults->concurrency,
            thresholds: Thresholds::fromArray(Arr::stringKeys(Arr::array($config, 'thresholds') ?? [])),
            logging: array_replace($defaults->logging, Arr::stringKeys(Arr::array($config, 'logging') ?? [])),
        );
    }

    /**
     * The shipped config file evaluated against the current environment variables.
     */
    public static function fromEnv(): self
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/decision-engine.php';

        return self::fromArray($config);
    }

    public function engines(): Engines\EngineManager
    {
        return new Engines\EngineManager($this->engines, $this->defaultEngine, $this->thresholds);
    }

    /**
     * @return array<string, mixed>
     */
    public function engineConfig(string $name): array
    {
        return $this->engines[$name] ?? throw ConfigurationException::unknownEngine($name);
    }

    public function transportDriver(): string
    {
        $driver = $this->transport['driver'] ?? 'curl';

        return is_string($driver) ? $driver : 'curl';
    }

    public function timeout(): float
    {
        return Arr::float($this->transport, 'timeout') ?? 10.0;
    }

    public function connectTimeout(): float
    {
        return Arr::float($this->transport, 'connect_timeout') ?? 5.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'default' => $this->defaultEngine,
            'engines' => $this->engines,
            'transport' => $this->transport,
            'retry' => $this->retry->toArray(),
            'concurrency' => ['default' => $this->concurrency],
            'thresholds' => $this->thresholds->toArray(),
            'logging' => $this->logging,
        ];
    }
}
