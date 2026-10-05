<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Use_;
use RuntimeException;
use voku\AgentMap\Rename\SourceClassNameLocator;

/** Proves that deleting one class also owns the complete PHP file in contract 1.0. */
final readonly class ClassFileRemovalInspector
{
    public function __construct(private SourceClassNameLocator $locator)
    {
    }

    /** Returns whether the owned class declaration carries a PHPDoc block. */
    public function inspectOwnedFile(
        string $path,
        string $expectedNamespace,
        string $expectedShortName,
        int $lineStart,
        int $lineEnd,
    ): bool {
        if ($expectedNamespace === '') {
            throw new RuntimeException('Class removal contract 1.0 requires a namespaced class in its own file: ' . $path);
        }

        $namespaces = [];
        foreach ($this->locator->astFor($path) as $statement) {
            if ($statement instanceof Declare_ || $statement instanceof Nop) {
                continue;
            }
            if ($statement instanceof Namespace_) {
                $namespaces[] = $statement;
                continue;
            }

            throw new RuntimeException('Class removal cannot own the whole file because it contains top-level PHP outside its namespace: ' . $path);
        }

        if (count($namespaces) !== 1) {
            throw new RuntimeException(sprintf(
                'Class removal contract 1.0 requires exactly one namespace statement in %s; found %d.',
                $path,
                count($namespaces),
            ));
        }

        $namespace = $namespaces[0];
        if ($namespace->getAttribute('kind') === Namespace_::KIND_BRACED) {
            throw new RuntimeException('Class removal contract 1.0 does not delete braced namespace files: ' . $path);
        }
        if ($namespace->name === null || strcasecmp($namespace->name->toString(), $expectedNamespace) !== 0) {
            throw new RuntimeException('Class removal source namespace does not match the indexed class identity: ' . $path);
        }

        $matches = 0;
        $hasDocblock = false;
        foreach ($namespace->stmts as $statement) {
            if ($statement instanceof Use_ || $statement instanceof GroupUse || $statement instanceof Nop) {
                continue;
            }
            if ($statement instanceof Class_
                && $statement->name instanceof Identifier
                && strcasecmp($statement->name->toString(), $expectedShortName) === 0
                && $statement->getStartLine() === $lineStart
                && $statement->getEndLine() === $lineEnd
            ) {
                ++$matches;
                $hasDocblock = $statement->getDocComment() !== null;
                continue;
            }

            throw new RuntimeException(
                'Class removal cannot own the whole file because its namespace contains another declaration or executable statement: ' . $path,
            );
        }

        if ($matches !== 1) {
            throw new RuntimeException(sprintf(
                'Class removal could not bind %s to exactly one declaration in %s; found %d.',
                $expectedShortName,
                $path,
                $matches,
            ));
        }

        return $hasDocblock;
    }
}
