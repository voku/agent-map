<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Resolves semantic map evidence to one exact identifier token in current source. */
final class SourceNameLocator
{
    /** @var array<string, array<int, Node>> */
    private array $astByPath = [];

    /** @var array<string, string> */
    private array $sourceByPath = [];

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{start_file_pos: int, end_file_pos: int} */
    public function declaration(string $path, int $lineStart, int $lineEnd, string $expected): array
    {
        $matches = [];
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof ClassMethod || $node->name->toString() !== $expected) {
                continue;
            }
            if ($node->getStartLine() !== $lineStart || $node->getEndLine() !== $lineEnd) {
                continue;
            }
            $matches[] = $this->position($path, $node->name, $expected);
        }

        return $this->one($matches, $path, $lineStart, $lineEnd, 'method declaration', $expected);
    }

    /** @return array{start_file_pos: int, end_file_pos: int} */
    public function call(string $path, int $lineStart, int $lineEnd, string $expected): array
    {
        $matches = [];
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof MethodCall && !$node instanceof NullsafeMethodCall && !$node instanceof StaticCall) {
                continue;
            }
            if (!$node->name instanceof Identifier || $node->name->toString() !== $expected) {
                continue;
            }
            if ($node->getStartLine() !== $lineStart || $node->getEndLine() !== $lineEnd) {
                continue;
            }
            $matches[] = $this->position($path, $node->name, $expected);
        }

        return $this->one($matches, $path, $lineStart, $lineEnd, 'method call', $expected);
    }

    /**
     * @param list<array{start_file_pos: int, end_file_pos: int}> $matches
     * @return array{start_file_pos: int, end_file_pos: int}
     */
    private function one(array $matches, string $path, int $lineStart, int $lineEnd, string $kind, string $expected): array
    {
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map %s evidence to exactly one "%s" token at %s:%d-%d; found %d candidate(s).',
                $kind,
                $expected,
                $path,
                $lineStart,
                $lineEnd,
                count($matches),
            ));
        }

        return $matches[0];
    }

    /** @return array{start_file_pos: int, end_file_pos: int} */
    private function position(string $path, Identifier $identifier, string $expected): array
    {
        $start = $identifier->getStartFilePos();
        $end = $identifier->getEndFilePos();
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose byte positions for ' . $path . '.');
        }

        $actual = substr($this->source($path), $start, $end - $start + 1);
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'Source token mismatch at %s:%d-%d: expected "%s", found "%s".',
                $path,
                $identifier->getStartLine(),
                $identifier->getEndLine(),
                $expected,
                $actual,
            ));
        }

        return ['start_file_pos' => $start, 'end_file_pos' => $end];
    }

    /** @return list<Node> */
    private function nodes(string $path): array
    {
        if (!isset($this->astByPath[$path])) {
            $this->astByPath[$path] = PhpCodeParser::getAstFromString($this->source($path));
        }

        $flat = [];
        foreach ($this->astByPath[$path] as $node) {
            $this->appendNode($flat, $node);
        }

        return $flat;
    }

    /** @param list<Node> $result */
    private function appendNode(array &$result, Node $node): void
    {
        $result[] = $node;
        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $this->appendNode($result, $child);
                continue;
            }
            if (!is_array($child)) {
                continue;
            }
            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->appendNode($result, $item);
                }
            }
        }
    }

    private function source(string $path): string
    {
        if (!isset($this->sourceByPath[$path])) {
            $absolute = rtrim($this->root, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/');
            $source = file_get_contents($absolute);
            if (!is_string($source)) {
                throw new RuntimeException('Cannot read rename source file: ' . $path);
            }
            $this->sourceByPath[$path] = $source;
        }

        return $this->sourceByPath[$path];
    }
}
