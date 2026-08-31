<?php

declare(strict_types=1);

namespace voku\AgentMap\Plan;

use InvalidArgumentException;

/**
 * The one rule for paths a plan is allowed to name.
 *
 * Plan evidence is applied by a mutation host against a project root, so a path that leaves that
 * root is not a slightly awkward plan - it is an instruction to write somewhere nobody asked about.
 * The dangerous shape is not the obviously absolute path but the one that gets tidied into looking
 * relative, so this refuses to normalize anything: a path either is project-relative or it is not.
 */
final class ProjectRelativePath
{
    public static function isSafe(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, '/')) {
            return false;
        }
        if (preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            return false;
        }

        return !in_array('..', explode('/', $normalized), true);
    }

    public static function assertSafe(string $path, string $context): void
    {
        if (!self::isSafe($path)) {
            throw new InvalidArgumentException(sprintf('%s must stay inside the project root: %s', $context, $path));
        }
    }
}
