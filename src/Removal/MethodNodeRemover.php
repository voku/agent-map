<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Maps a method declaration to a whole-line byte deletion, including its PHPDoc and attributes. */
final readonly class MethodNodeRemover
{
    public function __construct(private string $root)
    {
    }

    /** @return array{start: int, end: int, expected: string, has_attributes: bool} */
    public function locate(string $path, int $lineStart, int $lineEnd, string $name): array
    {
        $source = $this->source($path);
        $matches = [];
        foreach (PhpCodeParser::getAstFromString($source) as $node) {
            $this->collect($node, $matches, $lineStart, $lineEnd, $name);
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf('Cannot map method removal to exactly one declaration at %s:%d-%d; found %d candidate(s).', $path, $lineStart, $lineEnd, count($matches)));
        }

        $method = $matches[0];
        $nodeStart = $method->getStartFilePos();
        $doc = $method->getDocComment();
        if ($doc !== null) {
            $nodeStart = min($nodeStart, $doc->getStartFilePos());
        }
        foreach ($method->attrGroups as $attributeGroup) {
            $nodeStart = min($nodeStart, $attributeGroup->getStartFilePos());
        }

        $previousNewline = strrpos(substr($source, 0, $nodeStart), "\n");
        $start = $previousNewline === false ? 0 : $previousNewline + 1;
        $prefix = substr($source, $start, $nodeStart - $start);
        if (trim($prefix) !== '') {
            throw new RuntimeException('Method removal requires the declaration and its metadata to start on their own line: ' . $path . '.');
        }

        $nodeEnd = $method->getEndFilePos();
        $nextNewline = strpos($source, "\n", $nodeEnd + 1);
        $lineEndExclusive = $nextNewline === false ? strlen($source) : $nextNewline;
        $suffix = substr($source, $nodeEnd + 1, $lineEndExclusive - ($nodeEnd + 1));
        if (trim($suffix) !== '') {
            throw new RuntimeException('Method removal requires the declaration to end on its own line without trailing source: ' . $path . '.');
        }

        $end = $nextNewline === false ? strlen($source) - 1 : $nextNewline;
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose a valid method-removal byte range for ' . $path . '.');
        }

        return [
            'start' => $start,
            'end' => $end,
            'expected' => substr($source, $start, $end - $start + 1),
            'has_attributes' => $method->attrGroups !== [],
        ];
    }

    /** Detect static calls such as self::class::method() that the semantic collector cannot resolve. */
    public function hasClassStringStaticCall(string $path, string $name): bool
    {
        foreach (PhpCodeParser::getAstFromString($this->source($path)) as $node) {
            if ($this->containsClassStringStaticCall($node, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ClassMethod> $matches */
    private function collect(Node $node, array &$matches, int $lineStart, int $lineEnd, string $name): void
    {
        if ($node instanceof ClassMethod && strcasecmp($node->name->toString(), $name) === 0
            && $node->getStartLine() === $lineStart && $node->getEndLine() === $lineEnd) {
            $matches[] = $node;
        }
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $child = $node->{$subNodeName};
            foreach ($child instanceof Node ? [$child] : (is_array($child) ? $child : []) as $item) {
                if ($item instanceof Node) {
                    $this->collect($item, $matches, $lineStart, $lineEnd, $name);
                }
            }
        }
    }

    /**
     * PHP reaches a method through more than a call expression.
     *
     * `[$this, 'compare']`, `[self::class, 'compare']` and `'Foo::compare'` are
     * ordinary callables that usort/array_map/event wiring invoke at runtime,
     * and PHPStan records no call relation for building one. Without this check
     * a method held only by a callable looks unreferenced, and removal published
     * a SAFE plan whose edit deletes a method the same file still hands to
     * uasort - source that still parses and then fatals.
     */
    public function hasCallableReference(string $path, string $name): bool
    {
        foreach (PhpCodeParser::getAstFromString($this->source($path)) as $node) {
            if ($this->containsCallableReference($node, $name)) {
                return true;
            }
        }

        return false;
    }

    private function containsCallableReference(Node $node, string $name): bool
    {
        if ($node instanceof Node\Scalar\String_ && $this->stringNamesMethod($node->value, $name)) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $child = $node->{$subNodeName};
            foreach ($child instanceof Node ? [$child] : (is_array($child) ? $child : []) as $item) {
                if ($item instanceof Node && $this->containsCallableReference($item, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Matches both the array-callable element and the "Class::method" string form. */
    private function stringNamesMethod(string $value, string $name): bool
    {
        if (strcasecmp($value, $name) === 0) {
            return true;
        }
        $separator = strrpos($value, '::');

        return $separator !== false && strcasecmp(substr($value, $separator + 2), $name) === 0;
    }

    private function containsClassStringStaticCall(Node $node, string $name): bool
    {
        if ($node instanceof StaticCall
            && $node->class instanceof ClassConstFetch
            && $node->class->name instanceof Identifier
            && strcasecmp($node->class->name->toString(), 'class') === 0
            && $node->name instanceof Identifier
            && strcasecmp($node->name->toString(), $name) === 0) {
            return true;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $child = $node->{$subNodeName};
            foreach ($child instanceof Node ? [$child] : (is_array($child) ? $child : []) as $item) {
                if ($item instanceof Node && $this->containsClassStringStaticCall($item, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function source(string $path): string
    {
        $absolute = rtrim($this->root, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/');
        $source = file_get_contents($absolute);
        if (!is_string($source)) {
            throw new RuntimeException('Cannot read method-removal source file: ' . $path);
        }

        return $source;
    }
}
