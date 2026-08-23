<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Maps a method declaration to a whole-line byte deletion, including its PHPDoc. */
final readonly class MethodNodeRemover
{
    public function __construct(private string $root)
    {
    }

    /** @return array{start: int, end: int, expected: string} */
    public function locate(string $path, int $lineStart, int $lineEnd, string $name): array
    {
        $absolute = rtrim($this->root, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/');
        $source = file_get_contents($absolute);
        if (!is_string($source)) {
            throw new RuntimeException('Cannot read method-removal source file: ' . $path);
        }

        $matches = [];
        foreach (PhpCodeParser::getAstFromString($source) as $node) {
            $this->collect($node, $matches, $lineStart, $lineEnd, $name);
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf('Cannot map method removal to exactly one declaration at %s:%d-%d; found %d candidate(s).', $path, $lineStart, $lineEnd, count($matches)));
        }

        $method = $matches[0];
        $start = $method->getStartFilePos();
        $doc = $method->getDocComment();
        if ($doc !== null) {
            $start = min($start, $doc->getStartFilePos());
        }
        $lineStartPos = strrpos(substr($source, 0, $start), "\n");
        $start = $lineStartPos === false ? 0 : $lineStartPos + 1;
        $end = $method->getEndFilePos();
        $newline = strpos($source, "\n", $end + 1);
        $end = $newline === false ? $end : $newline;
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose a valid method-removal byte range for ' . $path . '.');
        }

        return ['start' => $start, 'end' => $end, 'expected' => substr($source, $start, $end - $start + 1)];
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
}
