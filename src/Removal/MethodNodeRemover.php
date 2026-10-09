<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use RuntimeException;
use voku\SimplePhpParser\Parsers\Helper\AstDeclarationFinder;
use voku\SimplePhpParser\Parsers\Helper\AstNodeInspector;
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
        $matches = AstDeclarationFinder::methods(PhpCodeParser::getAstFromString($source), $name, $lineStart, $lineEnd);
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf('Cannot map method removal to exactly one declaration at %s:%d-%d; found %d candidate(s).', $path, $lineStart, $lineEnd, count($matches)));
        }

        $method = $matches[0];
        $owned = AstNodeInspector::ownedRange($method);
        if ($owned === null) {
            throw new RuntimeException('Parser did not expose source positions for the declaration in ' . $path . '.');
        }
        $nodeStart = $owned['startFilePos'];

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
        ['start' => $start, 'end' => $end] = BlankLineSeparation::absorb($source, $start, $end);
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
        return (new NodeFinder())->findFirst(
            PhpCodeParser::getAstFromString($this->source($path)),
            fn (Node $node): bool => $this->isClassStringStaticCall($node, $name),
        ) !== null;
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
        return (new NodeFinder())->findFirst(
            PhpCodeParser::getAstFromString($this->source($path)),
            fn (Node $node): bool => $this->isCallableReference($node, $name),
        ) !== null;
    }

    private function isCallableReference(Node $node, string $name): bool
    {
        // Only genuine callable shapes, not any string that happens to share the
        // name. `$name = 'oldName'; $obj->{$name}()` is dynamic dispatch the
        // planners already handle as reviewable evidence with deterministic
        // edits, and treating it as a callable would block work that is provable.
        if ($node instanceof Node\Expr\Array_ && count($node->items) === 2) {
            $second = $node->items[1];
            if ($second->value instanceof Node\Scalar\String_
                && strcasecmp($second->value->value, $name) === 0) {
                return true;
            }
        }
        if ($node instanceof Node\Scalar\String_ && $this->qualifiedStringNamesMethod($node->value, $name)) {
            return true;
        }
        // First-class callable syntax: `self::handler(...)` builds a Closure, it
        // does not call the method, so PHPStan publishes no call relation for it.
        if ($node instanceof Node\Expr\CallLike
            && $node->isFirstClassCallable()
            && $this->callLikeNamesMethod($node, $name)) {
            return true;
        }


        return false;
    }

    private function callLikeNamesMethod(Node\Expr\CallLike $node, string $name): bool
    {
        $called = match (true) {
            $node instanceof StaticCall,
            $node instanceof Node\Expr\MethodCall,
            $node instanceof Node\Expr\NullsafeMethodCall => $node->name,
            default => null,
        };

        return $called instanceof Identifier && strcasecmp($called->toString(), $name) === 0;
    }

    /** Only the "Class::method" callable string, never a bare name. */
    private function qualifiedStringNamesMethod(string $value, string $name): bool
    {
        $separator = strrpos($value, '::');

        return $separator !== false && strcasecmp(substr($value, $separator + 2), $name) === 0;
    }

    private function isClassStringStaticCall(Node $node, string $name): bool
    {
        if ($node instanceof StaticCall
            && $node->class instanceof ClassConstFetch
            && $node->class->name instanceof Identifier
            && strcasecmp($node->class->name->toString(), 'class') === 0
            && $node->name instanceof Identifier
            && strcasecmp($node->name->toString(), $name) === 0) {
            return true;
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
