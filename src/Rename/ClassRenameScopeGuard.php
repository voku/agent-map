<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Guards class-rename edits against per-file class-name alias collisions. */
final readonly class ClassRenameScopeGuard
{
    public function __construct(private string $root)
    {
    }

    public function assertReplacementAvailable(
        string $path,
        string $targetFqn,
        string $targetShort,
        string $replacementShort,
    ): void {
        $source = $this->source($path);
        $ast = PhpCodeParser::getAstFromString($source);
        if (!$this->hasAffectedUnqualifiedReference($ast, $source, $targetFqn, $targetShort)) {
            return;
        }

        foreach ($this->occupiedClassNames($ast, $targetFqn) as $occupied) {
            if (strcasecmp($occupied, $replacementShort) === 0) {
                throw new RuntimeException(sprintf(
                    'Replacement short name "%s" collides with an existing class import or declaration in %s.',
                    $replacementShort,
                    $path,
                ));
            }
        }
    }

    /** @param array<int, Node> $ast */
    private function hasAffectedUnqualifiedReference(array $ast, string $source, string $targetFqn, string $targetShort): bool
    {
        foreach ($this->nodes($ast) as $node) {
            if (!$node instanceof Name || $this->insideUse($node)) {
                continue;
            }
            if (strcasecmp($this->resolvedFqn($node) ?? '', $targetFqn) !== 0) {
                continue;
            }
            $token = $this->sourceToken($source, $node);
            if (!str_contains($token, '\\') && strcasecmp($token, $targetShort) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, Node> $ast
     * @return list<string>
     */
    private function occupiedClassNames(array $ast, string $targetFqn): array
    {
        $occupied = [];
        foreach ($ast as $node) {
            $this->collectOccupied($node, $targetFqn, $occupied);
        }

        return array_values(array_unique($occupied));
    }

    /** @param list<string> $occupied */
    private function collectOccupied(Node $node, string $targetFqn, array &$occupied): void
    {
        if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                if ($use instanceof UseItem && $this->isClassUse($use, $node->type)) {
                    $this->recordImport($use, $use->name->toString(), $targetFqn, $occupied);
                }
            }
            return;
        }

        if ($node instanceof GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                if ($use instanceof UseItem && $this->isClassUse($use, $node->type)) {
                    $this->recordImport($use, $prefix . '\\' . $use->name->toString(), $targetFqn, $occupied);
                }
            }
            return;
        }

        if ($node instanceof ClassLike && $node->name !== null) {
            $fqn = $node->namespacedName instanceof Name ? ltrim($node->namespacedName->toString(), '\\') : null;
            if ($fqn === null || strcasecmp($fqn, $targetFqn) !== 0) {
                $occupied[] = $node->name->toString();
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $this->collectOccupied($child, $targetFqn, $occupied);
                continue;
            }
            if (!is_array($child)) {
                continue;
            }
            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->collectOccupied($item, $targetFqn, $occupied);
                }
            }
        }
    }

    /** @param list<string> $occupied */
    private function recordImport(UseItem $use, string $importFqn, string $targetFqn, array &$occupied): void
    {
        if (strcasecmp(ltrim($importFqn, '\\'), $targetFqn) === 0) {
            return;
        }

        $occupied[] = $use->alias?->toString() ?? $use->name->getLast();
    }

    private function isClassUse(UseItem $use, int $containerType): bool
    {
        $effectiveType = $use->type === Use_::TYPE_UNKNOWN ? $containerType : $use->type;

        return $effectiveType === Use_::TYPE_NORMAL;
    }

    private function resolvedFqn(Name $name): ?string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        return null;
    }

    private function insideUse(Name $name): bool
    {
        $parent = $name->getAttribute('parent');

        return $parent instanceof UseItem || $parent instanceof GroupUse || $parent instanceof Use_;
    }

    private function sourceToken(string $source, Name $name): string
    {
        $start = $name->getStartFilePos();
        $end = $name->getEndFilePos();
        if ($start < 0 || $end < $start) {
            return '';
        }

        return substr($source, $start, $end - $start + 1);
    }

    /**
     * @param array<int, Node> $ast
     * @return list<Node>
     */
    private function nodes(array $ast): array
    {
        $nodes = [];
        foreach ($ast as $node) {
            $this->appendNode($nodes, $node);
        }

        return $nodes;
    }

    /** @param list<Node> $nodes */
    private function appendNode(array &$nodes, Node $node): void
    {
        $nodes[] = $node;
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $this->appendNode($nodes, $child);
                continue;
            }
            if (!is_array($child)) {
                continue;
            }
            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->appendNode($nodes, $item);
                }
            }
        }
    }

    private function source(string $path): string
    {
        $absolute = rtrim($this->root, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/');
        $source = file_get_contents($absolute);
        if (!is_string($source)) {
            throw new RuntimeException('Cannot read class rename source file: ' . $path);
        }

        return $source;
    }
}
