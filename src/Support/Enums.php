<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Support;

use Swis\DecisionEngine\Attributes\Option;
use Swis\DecisionEngine\Questions\Description;

/**
 * Backed enums as choice options: option names are the case values as strings.
 *
 * ```php
 * Enums::options(Department::class);        // ['returns' => 'Exchanges, …', 'other' => null]
 * Enums::tryFrom(Department::class, 'other'); // Department::Other
 * ```
 */
final class Enums
{
    /**
     * @param  class-string<\BackedEnum>  $enum
     * @return array<string, array<array-key, mixed>|string|null>  option name → description (from #[Option])
     */
    public static function options(string $enum): array
    {
        $options = [];

        foreach ((new \ReflectionEnum($enum))->getCases() as $case) {
            $attribute = $case->getAttributes(Option::class)[0] ?? null;
            $option = $attribute?->newInstance();
            $value = $case->getValue();

            if ($value instanceof \BackedEnum) {
                $options[(string) $value->value] = $option === null ? null : Description::make($option->what, $option->notFor, $option->examples);
            }
        }

        return $options;
    }

    /**
     * @template E of \BackedEnum
     *
     * @param  class-string<E>  $enum
     * @return E|null
     */
    public static function tryFrom(string $enum, string $value): ?\BackedEnum
    {
        if ((string) (new \ReflectionEnum($enum))->getBackingType() === 'int') {
            return preg_match('/^-?\d+$/', $value) === 1 ? $enum::tryFrom((int) $value) : null;
        }

        return $enum::tryFrom($value);
    }

    /**
     * @phpstan-assert-if-true class-string<\BackedEnum> $class
     */
    public static function isBacked(string $class): bool
    {
        return enum_exists($class) && (new \ReflectionEnum($class))->isBacked();
    }
}
