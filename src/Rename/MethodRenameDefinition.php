<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use InvalidArgumentException;

/**
 * Portions adapted from rectorphp/rector
 * rules/Renaming/ValueObject/MethodCallRename.php@29ac8eb5d206c9d62486c9e8ff018b27f94f34ce.
 *
 * The upstream value object carries class/old/new method configuration. This version keeps only
 * that immutable configuration boundary and agent-map's existing PHP identifier validation; it
 * does not import Rector runtime types or mutation behavior.
 *
 * Copyright (c) 2017-present Tomáš Votruba.
 * Licensed under the MIT License; see docs/reference/third-party-notices.md.
 */
final readonly class MethodRenameDefinition
{
    public string $className;

    public string $oldMethod;

    public string $newMethod;

    public function __construct(string $className, string $oldMethod, string $newMethod)
    {
        $className = ltrim(trim($className), '\\');
        $oldMethod = trim($oldMethod);
        $newMethod = trim($newMethod);

        if ($className === '' || !$this->validClassName($className)) {
            throw new InvalidArgumentException('Invalid PHP class name for method rename: ' . $className);
        }
        if (!$this->validMethodName($oldMethod)) {
            throw new InvalidArgumentException('Invalid current PHP method name: ' . $oldMethod);
        }
        if (!$this->validMethodName($newMethod)) {
            throw new InvalidArgumentException('Invalid replacement PHP method name: ' . $newMethod);
        }
        if (strcasecmp($oldMethod, $newMethod) === 0) {
            throw new InvalidArgumentException('Replacement method name is semantically identical to the current name: ' . $oldMethod);
        }

        $this->className = $className;
        $this->oldMethod = $oldMethod;
        $this->newMethod = $newMethod;
    }

    public static function fromTarget(string $target, string $newMethod): self
    {
        $target = trim($target);
        $separator = strrpos($target, '::');
        if ($separator === false || $separator === 0 || $separator === strlen($target) - 2) {
            throw new InvalidArgumentException('Method rename target must use Class::method syntax: ' . $target);
        }

        return new self(
            substr($target, 0, $separator),
            substr($target, $separator + 2),
            $newMethod,
        );
    }

    public function target(): string
    {
        return $this->className . '::' . $this->oldMethod;
    }

    private function validClassName(string $className): bool
    {
        foreach (explode('\\', $className) as $part) {
            if (!$this->validIdentifier($part)) {
                return false;
            }
        }

        return true;
    }

    private function validMethodName(string $methodName): bool
    {
        return $this->validIdentifier($methodName);
    }

    private function validIdentifier(string $identifier): bool
    {
        return $identifier !== ''
            && preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $identifier) === 1;
    }
}
