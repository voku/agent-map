<?php

declare(strict_types=1);

namespace voku\AgentMap\Move;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/**
 * The move-specific node evidence agent-map does not already publish.
 *
 * Removal already maps a declaration range and rename already maps a name token,
 * but a relocation additionally needs a destination insertion anchor, the owner
 * observations that make copied text unsafe, and the class token of a static
 * call so the call can be re-pointed without touching the method name.
 */
final readonly class MethodMoveNodeLocator
{
    public function __construct(private string $root)
    {
    }

    /**
     * Owner observations inside the moved body. Copied text that parses is not
     * owner-independent, so these are reported rather than silently relocated.
     *
     * @return list<string>
     */
    public function ownerDependencies(string $path, int $lineStart, int $lineEnd, string $name): array
    {
        $method = $this->method($path, $lineStart, $lineEnd, $name);
        $found = [];
        $this->walk($method, static function (Node $node) use (&$found): void {
            if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
                $found['$this'] = true;
            }
            if ($node instanceof Node\Name) {
                $lowered = strtolower($node->toString());
                if (in_array($lowered, ['self', 'static', 'parent'], true)) {
                    $found[$lowered . '::'] = true;
                }
            }
            if ($node instanceof Node\Scalar\MagicConst\Class_) {
                $found['__CLASS__'] = true;
            }
            if ($node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && in_array(strtolower($node->name->toString()), ['get_called_class', 'get_class'], true)) {
                $found[strtolower($node->name->toString()) . '()'] = true;
            }
        });
        ksort($found);

        return array_keys($found);
    }

    /**
     * Deterministic insertion anchor inside the destination class body.
     *
     * The anchor is the destination's closing brace: inserting before it needs
     * no reprinting of the surrounding class and stays stable under formatting
     * that does not touch that line.
     *
     * @return array{position: int, expected_anchor: string, line: int}
     */
    public function insertionAnchor(string $path, int $lineStart, int $lineEnd, string $fqn): array
    {
        $source = $this->source($path);
        $matches = [];
        foreach (PhpCodeParser::getAstFromString($source) as $node) {
            $this->walk($node, static function (Node $candidate) use (&$matches, $lineStart, $lineEnd): void {
                if ($candidate instanceof Class_
                    && $candidate->getStartLine() === $lineStart
                    && $candidate->getEndLine() === $lineEnd) {
                    $matches[] = $candidate;
                }
            });
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map destination %s to exactly one class declaration at %s:%d-%d; found %d candidate(s).',
                $fqn,
                $path,
                $lineStart,
                $lineEnd,
                count($matches),
            ));
        }

        $closingBrace = $matches[0]->getEndFilePos();
        if ($closingBrace < 0 || $closingBrace >= strlen($source) || $source[$closingBrace] !== '}') {
            throw new RuntimeException('Parser did not expose the destination class closing brace for ' . $path . '.');
        }
        $lineStartPos = strrpos(substr($source, 0, $closingBrace), "\n");
        $lineStartPos = $lineStartPos === false ? 0 : $lineStartPos + 1;
        if (trim(substr($source, $lineStartPos, $closingBrace - $lineStartPos)) !== '') {
            throw new RuntimeException('Destination insertion requires the class closing brace to start its own line: ' . $path . '.');
        }

        return [
            'position' => $lineStartPos,
            'expected_anchor' => substr($source, $lineStartPos, $closingBrace - $lineStartPos + 1),
            'line' => $matches[0]->getEndLine(),
        ];
    }

    /**
     * The class token of a static call, so a relocation re-points the owner
     * without rewriting the method name a rename would touch.
     *
     * @return array{start: int, end: int, expected: string}
     */
    public function staticCallOwner(string $path, int $lineStart, int $lineEnd, string $methodName): array
    {
        $source = $this->source($path);
        $matches = [];
        foreach (PhpCodeParser::getAstFromString($source) as $node) {
            $this->walk($node, static function (Node $candidate) use (&$matches, $lineStart, $lineEnd, $methodName): void {
                if ($candidate instanceof StaticCall
                    && $candidate->name instanceof Node\Identifier
                    && strcasecmp($candidate->name->toString(), $methodName) === 0
                    && $candidate->getStartLine() >= $lineStart
                    && $candidate->getEndLine() <= $lineEnd
                    && $candidate->class instanceof Node\Name) {
                    $matches[] = $candidate;
                }
            });
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map exactly one static call to %s at %s:%d-%d; found %d candidate(s).',
                $methodName,
                $path,
                $lineStart,
                $lineEnd,
                count($matches),
            ));
        }

        $class = $matches[0]->class;

        return [
            'start' => $class->getStartFilePos(),
            'end' => $class->getEndFilePos(),
            'expected' => substr($source, $class->getStartFilePos(), $class->getEndFilePos() - $class->getStartFilePos() + 1),
        ];
    }

    private function method(string $path, int $lineStart, int $lineEnd, string $name): ClassMethod
    {
        $matches = [];
        foreach (PhpCodeParser::getAstFromString($this->source($path)) as $node) {
            $this->walk($node, static function (Node $candidate) use (&$matches, $lineStart, $lineEnd, $name): void {
                if ($candidate instanceof ClassMethod
                    && strcasecmp($candidate->name->toString(), $name) === 0
                    && $candidate->getStartLine() === $lineStart
                    && $candidate->getEndLine() === $lineEnd) {
                    $matches[] = $candidate;
                }
            });
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map method move to exactly one declaration at %s:%d-%d; found %d candidate(s).',
                $path,
                $lineStart,
                $lineEnd,
                count($matches),
            ));
        }

        return $matches[0];
    }

    /** @param callable(Node): void $visitor */
    private function walk(Node $node, callable $visitor): void
    {
        $visitor($node);
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $child = $node->{$subNodeName};
            foreach ($child instanceof Node ? [$child] : (is_array($child) ? $child : []) as $item) {
                if ($item instanceof Node) {
                    $this->walk($item, $visitor);
                }
            }
        }
    }

    private function source(string $path): string
    {
        $absolute = rtrim($this->root, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/');
        $source = file_get_contents($absolute);
        if (!is_string($source)) {
            throw new RuntimeException('Cannot read method-move source file: ' . $path);
        }

        return $source;
    }
}
