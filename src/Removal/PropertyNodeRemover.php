<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Maps one private property declaration to an exact whole-line deletion range. */
final readonly class PropertyNodeRemover
{
    public function __construct(private string $root)
    {
    }

    /**
     * @return array{
     *   start: int,
     *   end: int,
     *   expected: string,
     *   line_start: int,
     *   line_end: int,
     *   private: bool,
     *   static: bool,
     *   single_property: bool,
     *   hooks: bool,
     *   has_attributes: bool,
     *   has_docblock: bool,
     *   owner_uses_trait: bool,
     *   owner_has_load_metadata: bool
     * }
     */
    public function locate(string $path, string $ownerFqn, string $propertyName): array
    {
        $source = $this->source($path);
        $class = $this->classLike($path, $ownerFqn, $source);
        $matches = [];

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() === $propertyName) {
                    $matches[] = $property;
                }
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map property removal to exactly one declaration for %s::$%s in %s; found %d candidate(s).',
                $ownerFqn,
                $propertyName,
                $path,
                count($matches),
            ));
        }

        $property = $matches[0];
        $nodeStart = $property->getStartFilePos();
        $doc = $property->getDocComment();
        if ($doc !== null) {
            $nodeStart = min($nodeStart, $doc->getStartFilePos());
        }
        foreach ($property->attrGroups as $attributeGroup) {
            $nodeStart = min($nodeStart, $attributeGroup->getStartFilePos());
        }

        $previousNewline = strrpos(substr($source, 0, $nodeStart), "\n");
        $start = $previousNewline === false ? 0 : $previousNewline + 1;
        $prefix = substr($source, $start, $nodeStart - $start);
        if (trim($prefix) !== '') {
            throw new RuntimeException('Property removal requires declaration metadata to start on its own line: ' . $path . '.');
        }

        $nodeEnd = $property->getEndFilePos();
        $nextNewline = strpos($source, "\n", $nodeEnd + 1);
        $lineEndExclusive = $nextNewline === false ? strlen($source) : $nextNewline;
        $suffix = substr($source, $nodeEnd + 1, $lineEndExclusive - ($nodeEnd + 1));
        if (trim($suffix) !== '') {
            throw new RuntimeException('Property removal requires the declaration to end on its own line without trailing source: ' . $path . '.');
        }

        $end = $nextNewline === false ? strlen($source) - 1 : $nextNewline;
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose a valid property-removal byte range for ' . $path . '.');
        }

        return [
            'start' => $start,
            'end' => $end,
            'expected' => substr($source, $start, $end - $start + 1),
            'line_start' => $property->getStartLine(),
            'line_end' => $property->getEndLine(),
            'private' => $property->isPrivate(),
            'static' => $property->isStatic(),
            'single_property' => count($property->props) === 1,
            'hooks' => $property->hooks !== [],
            'has_attributes' => $property->attrGroups !== [],
            'has_docblock' => $doc !== null,
            'owner_uses_trait' => $this->ownerUsesTrait($class),
            'owner_has_load_metadata' => $this->ownerHasLoadMetadata($class),
        ];
    }

    private function classLike(string $path, string $ownerFqn, string $source): ClassLike
    {
        $matches = [];
        foreach (PhpCodeParser::getAstFromString($source) as $node) {
            $this->collectClassLike($node, $matches, $ownerFqn);
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map property-removal owner %s to exactly one class-like declaration in %s.',
                $ownerFqn,
                $path,
            ));
        }

        return $matches[0];
    }

    /** @param list<ClassLike> $matches */
    private function collectClassLike(Node $node, array &$matches, string $ownerFqn): void
    {
        if ($node instanceof ClassLike && $node->name !== null) {
            $resolved = $node->namespacedName instanceof Name
                ? ltrim($node->namespacedName->toString(), '\\')
                : $node->name->toString();
            if (strcasecmp($resolved, ltrim($ownerFqn, '\\')) === 0) {
                $matches[] = $node;
            }
        }
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $child = $node->{$subNodeName};
            foreach ($child instanceof Node ? [$child] : (is_array($child) ? $child : []) as $item) {
                if ($item instanceof Node) {
                    $this->collectClassLike($item, $matches, $ownerFqn);
                }
            }
        }
    }

    private function ownerUsesTrait(ClassLike $class): bool
    {
        foreach ($class->stmts as $statement) {
            if ($statement instanceof TraitUse) {
                return true;
            }
        }

        return false;
    }

    private function ownerHasLoadMetadata(ClassLike $class): bool
    {
        foreach ($class->getMethods() as $method) {
            if ($method instanceof ClassMethod && strcasecmp($method->name->toString(), 'loadMetadata') === 0) {
                return true;
            }
        }

        return false;
    }

    private function source(string $path): string
    {
        $absolute = rtrim($this->root, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/');
        $source = file_get_contents($absolute);
        if (!is_string($source)) {
            throw new RuntimeException('Cannot read property-removal source file: ' . $path);
        }

        return $source;
    }
}
