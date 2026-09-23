<?php

declare(strict_types=1);

namespace Swis\DecisionEngine;

final class Version
{
    public const VERSION = '0.1.0';

    public static function userAgent(): string
    {
        return 'swis-decision-engine/' . self::VERSION . ' php/' . PHP_VERSION;
    }
}
