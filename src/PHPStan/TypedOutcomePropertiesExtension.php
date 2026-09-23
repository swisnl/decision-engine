<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\PHPStan;

use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;
use Swis\DecisionEngine\Attributes;
use Swis\DecisionEngine\Typed\TypedOutcome;

/**
 * Tells PHPStan that question properties of a TypedOutcome are written by the library, so
 * `public readonly Department $department;` is not reported as an uninitialized readonly property.
 * Registered by `extension.neon` (auto-loaded with phpstan/extension-installer).
 */
final class TypedOutcomePropertiesExtension implements ReadWritePropertiesExtension
{
    private const ATTRIBUTES = [Attributes\Choice::class, Attributes\Score::class, Attributes\Noul::class];

    public function isAlwaysRead(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    public function isAlwaysWritten(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isQuestion($property, $propertyName);
    }

    public function isInitialized(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return $this->isQuestion($property, $propertyName);
    }

    private function isQuestion(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        $class = $property->getDeclaringClass();

        if (! $class->is(TypedOutcome::class) || ! $class->getNativeReflection()->hasProperty($propertyName)) {
            return false;
        }

        $native = $class->getNativeReflection()->getProperty($propertyName);

        foreach (self::ATTRIBUTES as $attribute) {
            if ($native->getAttributes($attribute) !== []) {
                return true;
            }
        }

        return false;
    }
}
