<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Exceptions;

/**
 * Thrown when the engine / transport configuration is incomplete or inconsistent.
 *
 * ```php
 * throw ConfigurationException::missing('engines.jev.api_key');
 * ```
 */
final class ConfigurationException extends \RuntimeException implements DecisionEngineException
{
    public function __construct(string $message, public readonly ?string $key = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function missing(string $key): self
    {
        return new self("Missing required configuration value [{$key}].", $key);
    }

    public static function invalid(string $key, string $reason): self
    {
        return new self("Invalid configuration value [{$key}]: {$reason}", $key);
    }

    public static function unknownEngine(string $name): self
    {
        return new self("Engine [{$name}] is not configured.", "engines.{$name}");
    }

    public static function unknownDriver(string $driver, string $engine): self
    {
        return new self("Driver [{$driver}] used by engine [{$engine}] is not registered.", "engines.{$engine}.driver");
    }
}
