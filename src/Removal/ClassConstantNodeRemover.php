<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use RuntimeException;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Maps one class-constant declaration to an exact whole-line deletion range. */
final readonly class ClassConstantNodeRemover
{
    public function __construct(private string $root)
    {
    }

    /** @return array{start: int, end: int, expected: string, line_start: int, line_end: int, private: bool, single_constant: bool, has_attributes: bool, has_docblock: bool} */
    public function locate(string $path, string $ownerFqn, string $constantName): array
    {
        $source = $this->source($path);
        $matches = [];
        foreach (PhpCodeParser::getAstFromString($source) as $node) {
            $this->collect($node, null, $ownerFqn, $constantName, $matches);
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf('Cannot map class constant removal to exactly one declaration for %s::%s in %s; found %d candidate(s).', $ownerFqn, $constantName, $path, count($matches)));
        }

        $constant = $matches[0];
        $nodeStart = $constant->getStartFilePos();
        $lineStart = $constant->getStartLine();
        $doc = $constant->getDocComment();
        if ($doc !== null) {
            $nodeStart = min($nodeStart, $doc->getStartFilePos());
            $lineStart = min($lineStart, $doc->getStartLine());
        }
        foreach ($constant->attrGroups as $attributeGroup) {
            $nodeStart = min($nodeStart, $attributeGroup->getStartFilePos());
            $lineStart = min($lineStart, $attributeGroup->getStartLine());
        }

        $previousNewline = strrpos(substr($source, 0, $nodeStart), "\n");
        $start = $previousNewline === false ? 0 : $previousNewline + 1;
        if (trim(substr($source, $start, $nodeStart - $start)) !== '') {
            throw new RuntimeException('Class constant removal requires the declaration and its metadata to start on their own line: ' . $path . '.');
        }
        $nodeEnd = $constant->getEndFilePos();
        $nextNewline = strpos($source, "\n", $nodeEnd + 1);
        $lineEndExclusive = $nextNewline === false ? strlen($source) : $nextNewline;
        if (trim(substr($source, $nodeEnd + 1, $lineEndExclusive - $nodeEnd - 1)) !== '') {
            throw new RuntimeException('Class constant removal requires the declaration to end on its own line without trailing source: ' . $path . '.');
        }
        $end = $nextNewline === false ? strlen($source) - 1 : $nextNewline;

        return [
            'start' => $start,
            'end' => $end,
            'expected' => substr($source, $start, $end - $start + 1),
            'line_start' => $lineStart,
            'line_end' => $constant->getEndLine(),
            'private' => $constant->isPrivate(),
            'single_constant' => count($constant->consts) === 1,
            'has_attributes' => $constant->attrGroups !== [],
            'has_docblock' => $doc !== null,
        ];
    }

    /** @param list<ClassConst> $matches */
    private function collect(Node $node, ?string $classFqn, string $ownerFqn, string $constantName, array &$matches): void
    {
        if ($node instanceof ClassLike) {
            $name = $node->getAttribute('namespacedName');
            if (!$name instanceof Name) {
                $name = $node->namespacedName;
            }
            $classFqn = $name instanceof Name ? ltrim($name->toString(), '\\') : null;
        }
        if ($node instanceof ClassConst && strcasecmp($classFqn ?? '', $ownerFqn) === 0) {
            foreach ($node->consts as $constant) {
                if ($constant->name->toString() === $constantName) {
                    $matches[] = $node;
                }
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            $child = $node->{$key};
            foreach ($child instanceof Node ? [$child] : (is_array($child) ? $child : []) as $item) {
                if ($item instanceof Node) {
                    $this->collect($item, $classFqn, $ownerFqn, $constantName, $matches);
                }
            }
        }
    }

    private function source(string $path): string
    {
        $source = file_get_contents(rtrim($this->root, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/'));
        if (!is_string($source)) {
            throw new RuntimeException('Cannot read class-constant removal source file: ' . $path);
        }

        return $source;
    }
}
