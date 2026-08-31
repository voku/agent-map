<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use RuntimeException;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Maps one class FQN to exact declaration/import/reference tokens in current PHP source. */
final class SourceClassNameLocator
{
    /** @var array<string, array<int, Node>> */
    private array $astByPath = [];

    /** @var array<string, string> */
    private array $sourceByPath = [];

    public function __construct(private readonly string $root)
    {
    }

    /** @return array{start_file_pos: int, end_file_pos: int, expected: string} */
    public function declaration(string $path, int $lineStart, int $lineEnd, string $expectedShort): array
    {
        $matches = [];
        foreach ($this->nodes($path) as $node) {
            if (!$node instanceof Class_ || !$node->name instanceof Identifier) {
                continue;
            }
            if (strcasecmp($node->name->toString(), $expectedShort) !== 0) {
                continue;
            }
            if ($node->getStartLine() !== $lineStart || $node->getEndLine() !== $lineEnd) {
                continue;
            }
            $matches[] = $this->token($path, $node->name);
        }

        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf(
                'Cannot map class declaration "%s" to exactly one token at %s:%d-%d; found %d candidate(s).',
                $expectedShort,
                $path,
                $lineStart,
                $lineEnd,
                count($matches),
            ));
        }

        return $matches[0];
    }

    /**
     * @return array{edits: list<PlanEdit>, blind_spots: list<PlanBlindSpot>}
     */
    public function references(
        string $path,
        string $sourceSha256,
        string $targetFqn,
        string $expectedShort,
        string $replacementShort,
        string $symbolId,
    ): array {
        $edits = [];
        $blindSpots = [];
        foreach ($this->ast($path) as $node) {
            $this->collectNode(
                $node,
                $path,
                $sourceSha256,
                $targetFqn,
                $expectedShort,
                $replacementShort,
                $symbolId,
                $edits,
                $blindSpots,
            );
        }

        foreach ($this->docCommentBlindSpots($path, $targetFqn, $expectedShort) as $blindSpot) {
            $blindSpots[] = $blindSpot;
        }

        return [
            'edits' => $this->uniqueEdits($edits),
            'blind_spots' => $this->uniqueBlindSpots($blindSpots),
        ];
    }

    /** @internal Shared with the owner-local class rename scope guard to avoid reparsing the same file. */
    public function sourceFor(string $path): string
    {
        return $this->source($path);
    }

    /**
     * @internal Shared with the owner-local class rename scope guard to avoid reparsing the same file.
     * @return array<int, Node>
     */
    public function astFor(string $path): array
    {
        return $this->ast($path);
    }

    /**
     * @param list<PlanEdit> $edits
     * @param list<PlanBlindSpot> $blindSpots
     */
    private function collectNode(
        Node $node,
        string $path,
        string $sourceSha256,
        string $targetFqn,
        string $expectedShort,
        string $replacementShort,
        string $symbolId,
        array &$edits,
        array &$blindSpots,
    ): void {
        if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                if (!$this->isClassUse($use, $node->type)) {
                    continue;
                }
                $this->collectImport(
                    $use->name,
                    $use->name->toString(),
                    $path,
                    $sourceSha256,
                    $targetFqn,
                    $replacementShort,
                    $symbolId,
                    $edits,
                );
            }
            return;
        }

        if ($node instanceof GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                if (!$this->isClassUse($use, $node->type)) {
                    continue;
                }
                $this->collectImport(
                    $use->name,
                    $prefix . '\\' . $use->name->toString(),
                    $path,
                    $sourceSha256,
                    $targetFqn,
                    $replacementShort,
                    $symbolId,
                    $edits,
                );
            }
            return;
        }

        if ($node instanceof String_) {
            $value = ltrim($node->value, '\\');
            if (strcasecmp($value, $targetFqn) === 0 || strcasecmp($node->value, $expectedShort) === 0) {
                $blindSpots[] = new PlanBlindSpot(
                    kind: 'class_string_literal',
                    message: 'String literal may encode the renamed class and cannot be rewritten as a proven PHP class-name token.',
                    path: $path,
                    lineStart: $node->getStartLine(),
                    lineEnd: $node->getEndLine(),
                );
            }
        }

        if ($this->isDynamicClassOperation($node)) {
            $blindSpots[] = new PlanBlindSpot(
                kind: 'dynamic_class_name',
                message: 'Dynamic class-name operation may resolve to the renamed class at runtime; exact target identity cannot be proven.',
                path: $path,
                lineStart: $node->getStartLine(),
                lineEnd: $node->getEndLine(),
            );
        }

        if ($node instanceof Name) {
            $resolvedFqn = $this->resolvedFqn($node);
            if ($resolvedFqn !== null && strcasecmp($resolvedFqn, $targetFqn) === 0) {
                $token = $this->token($path, $node);
                if (strcasecmp($this->lastSegment($token['expected']), $expectedShort) === 0) {
                    $edits[] = new PlanEdit(
                        path: $path,
                        sourceSha256: $sourceSha256,
                        startFilePos: $token['start_file_pos'],
                        endFilePos: $token['end_file_pos'],
                        lineStart: $node->getStartLine(),
                        lineEnd: $node->getEndLine(),
                        expected: $token['expected'],
                        replacement: $this->replaceLastSegment($token['expected'], $replacementShort),
                        role: 'class_reference',
                        symbolId: $symbolId,
                        resolution: 'parser_resolved',
                    );
                }
            }
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $this->collectNode($child, $path, $sourceSha256, $targetFqn, $expectedShort, $replacementShort, $symbolId, $edits, $blindSpots);
                continue;
            }
            if (!is_array($child)) {
                continue;
            }
            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->collectNode($item, $path, $sourceSha256, $targetFqn, $expectedShort, $replacementShort, $symbolId, $edits, $blindSpots);
                }
            }
        }
    }

    private function isDynamicClassOperation(Node $node): bool
    {
        if ($node instanceof New_) {
            return !($node->class instanceof Name) && !($node->class instanceof Class_);
        }

        if ($node instanceof Instanceof_
            || $node instanceof StaticCall
            || $node instanceof StaticPropertyFetch
            || $node instanceof ClassConstFetch) {
            return !($node->class instanceof Name);
        }

        return false;
    }

    private function isClassUse(UseItem $use, int $containerType): bool
    {
        $effectiveType = $use->type === Use_::TYPE_UNKNOWN ? $containerType : $use->type;

        return $effectiveType === Use_::TYPE_NORMAL;
    }

    /** @param list<PlanEdit> $edits */
    private function collectImport(
        Name $name,
        string $resolvedImport,
        string $path,
        string $sourceSha256,
        string $targetFqn,
        string $replacementShort,
        string $symbolId,
        array &$edits,
    ): void {
        if (strcasecmp(ltrim($resolvedImport, '\\'), $targetFqn) !== 0) {
            return;
        }

        $token = $this->token($path, $name);
        $edits[] = new PlanEdit(
            path: $path,
            sourceSha256: $sourceSha256,
            startFilePos: $token['start_file_pos'],
            endFilePos: $token['end_file_pos'],
            lineStart: $name->getStartLine(),
            lineEnd: $name->getEndLine(),
            expected: $token['expected'],
            replacement: $this->replaceLastSegment($token['expected'], $replacementShort),
            role: 'class_import',
            symbolId: $symbolId,
            resolution: 'parser_resolved',
        );
    }

    private function resolvedFqn(Name $name): ?string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }
        if ($name instanceof FullyQualified) {
            return ltrim($name->toString(), '\\');
        }

        return null;
    }

    /** @return array{start_file_pos: int, end_file_pos: int, expected: string} */
    private function token(string $path, Node $node): array
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose byte positions for class rename token in ' . $path . '.');
        }

        $actual = substr($this->source($path), $start, $end - $start + 1);
        if ($actual === '') {
            throw new RuntimeException('Parser exposed an empty class rename token in ' . $path . '.');
        }

        return ['start_file_pos' => $start, 'end_file_pos' => $end, 'expected' => $actual];
    }

    /** @return list<PlanBlindSpot> */
    private function docCommentBlindSpots(string $path, string $targetFqn, string $expectedShort): array
    {
        $blindSpots = [];
        foreach ($this->nodes($path) as $node) {
            $docComment = $node->getDocComment();
            if ($docComment === null) {
                continue;
            }
            $comment = $docComment->getText();
            if (!$this->commentMentionsClass($comment, $targetFqn, $expectedShort)) {
                continue;
            }
            $blindSpots[] = new PlanBlindSpot(
                kind: 'phpdoc_type_reference',
                message: 'PHPDoc may reference the renamed class; docblock rewriting is not yet part of the exact-token contract.',
                path: $path,
                lineStart: $docComment->getStartLine(),
                lineEnd: $docComment->getEndLine(),
            );
        }

        return $this->uniqueBlindSpots($blindSpots);
    }

    private function commentMentionsClass(string $comment, string $targetFqn, string $expectedShort): bool
    {
        if (stripos($comment, $targetFqn) !== false) {
            return true;
        }

        $matches = preg_match('/(?<![A-Za-z0-9_\\\\])' . preg_quote($expectedShort, '/') . '(?![A-Za-z0-9_])/i', $comment);
        if ($matches === false) {
            throw new RuntimeException('Cannot evaluate PHPDoc class-name evidence for class rename.');
        }

        return $matches === 1;
    }

    private function replaceLastSegment(string $sourceToken, string $replacementShort): string
    {
        $separator = strrpos($sourceToken, '\\');
        if ($separator === false) {
            return $replacementShort;
        }

        return substr($sourceToken, 0, $separator + 1) . $replacementShort;
    }

    private function lastSegment(string $name): string
    {
        $separator = strrpos($name, '\\');
        return $separator === false ? $name : substr($name, $separator + 1);
    }

    /** @return list<Node> */
    private function nodes(string $path): array
    {
        $flat = [];
        foreach ($this->ast($path) as $node) {
            $this->appendNode($flat, $node);
        }

        return $flat;
    }

    /** @return array<int, Node> */
    private function ast(string $path): array
    {
        if (!isset($this->astByPath[$path])) {
            $this->astByPath[$path] = PhpCodeParser::getAstFromString($this->source($path));
        }

        return $this->astByPath[$path];
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
                throw new RuntimeException('Cannot read class rename source file: ' . $path);
            }
            $this->sourceByPath[$path] = $source;
        }

        return $this->sourceByPath[$path];
    }

    /**
     * @param list<PlanEdit> $edits
     * @return list<PlanEdit>
     */
    private function uniqueEdits(array $edits): array
    {
        $unique = [];
        foreach ($edits as $edit) {
            $unique[$edit->path . ':' . $edit->startFilePos . ':' . $edit->endFilePos] = $edit;
        }
        $edits = array_values($unique);
        usort($edits, static fn (PlanEdit $left, PlanEdit $right): int => $left->path <=> $right->path ?: $left->startFilePos <=> $right->startFilePos);

        return $edits;
    }

    /**
     * @param list<PlanBlindSpot> $blindSpots
     * @return list<PlanBlindSpot>
     */
    private function uniqueBlindSpots(array $blindSpots): array
    {
        $unique = [];
        foreach ($blindSpots as $blindSpot) {
            $unique[implode(':', [$blindSpot->kind, $blindSpot->path ?? '', (string) ($blindSpot->lineStart ?? 0), (string) ($blindSpot->lineEnd ?? 0)])] = $blindSpot;
        }

        return array_values($unique);
    }
}
