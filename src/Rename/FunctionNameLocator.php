<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Function_;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Maps proven function identities to exact declaration or call tokens in current source. */
final class FunctionNameLocator
{
    /** @var array<string, array<int, Node>> */
    private array $astByPath = [];

    /** @var array<string, string> */
    private array $sourceByPath = [];

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{start_file_pos: int, end_file_pos: int, actual: string} */
    public function declaration(string $path, int $lineStart, int $lineEnd, string $expected): array
    {
        $matches = [];
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof Function_ || strcasecmp($node->name->toString(), $expected) !== 0) {
                continue;
            }
            if ($node->getStartLine() !== $lineStart || $node->getEndLine() !== $lineEnd) {
                continue;
            }
            $matches[] = $this->position($path, $node->name, $expected, 'function declaration');
        }

        return $this->one($matches, $path, $lineStart, $lineEnd, 'function declaration', $expected);
    }

    /** @return array{start_file_pos: int, end_file_pos: int, actual: string} */
    public function call(string $path, int $lineStart, int $lineEnd, string $expected): array
    {
        $matches = [];
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof FuncCall || !$node->name instanceof Name) {
                continue;
            }
            // The first function-rename contract rewrites only the exact unqualified source token.
            // Aliases and qualified/FQN spellings require separate namespace/import evidence.
            if (strcasecmp($node->name->toString(), $expected) !== 0) {
                continue;
            }
            if ($node->getStartLine() !== $lineStart || $node->getEndLine() !== $lineEnd) {
                continue;
            }
            $matches[] = $this->position($path, $node->name, $expected, 'function call');
        }

        return $this->one($matches, $path, $lineStart, $lineEnd, 'function call', $expected);
    }

    /** Confirms that unresolved call evidence at this range is specifically a dynamic FuncCall. */
    public function isDynamicFunctionCall(string $path, int $lineStart, int $lineEnd): bool
    {
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof FuncCall || $node->name instanceof Name) {
                continue;
            }
            if ($node->getStartLine() === $lineStart && $node->getEndLine() === $lineEnd) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{start_file_pos: int, end_file_pos: int, actual: string}> $matches
     * @return array{start_file_pos: int, end_file_pos: int, actual: string}
     */
    private function one(array $matches, string $path, int $lineStart, int $lineEnd, string $kind, string $expected): array
    {
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map %s evidence to exactly one unqualified "%s" token at %s:%d-%d; found %d candidate(s).',
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

    /** @return array{start_file_pos: int, end_file_pos: int, actual: string} */
    private function position(string $path, Identifier|Name $name, string $expected, string $kind): array
    {
        $start = $name->getStartFilePos();
        $end = $name->getEndFilePos();
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose byte positions for ' . $kind . ' in ' . $path . '.');
        }

        $actual = substr($this->source($path), $start, $end - $start + 1);
        if (strcasecmp($actual, $expected) !== 0) {
            throw new RuntimeException(sprintf(
                'Source token mismatch for %s at %s:%d-%d: expected "%s", found "%s".',
                $kind,
                $path,
                $name->getStartLine(),
                $name->getEndLine(),
                $expected,
                $actual,
            ));
        }

        return ['start_file_pos' => $start, 'end_file_pos' => $end, 'actual' => $actual];
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
                throw new RuntimeException('Cannot read function rename source file: ' . $path);
            }
            $this->sourceByPath[$path] = $source;
        }

        return $this->sourceByPath[$path];
    }
}
