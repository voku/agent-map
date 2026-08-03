<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan;

use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

final readonly class TypeProjector
{
    public static function describe(Type $type): string
    {
        return $type->describe(VerbosityLevel::precise());
    }

    /** @return list<string> */
    public static function referencedClasses(Type $type): array
    {
        $classes = $type->getReferencedClasses();
        sort($classes, SORT_STRING);

        return array_values(array_unique($classes));
    }
}
