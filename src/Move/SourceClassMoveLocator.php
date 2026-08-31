<?php

declare(strict_types=1);

namespace voku\AgentMap\Move;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\MagicConst;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use RuntimeException;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Rename\SourceClassNameLocator;

/**
 * Maps one class namespace relocation to exact tokens in current PHP source.
 *
 * A namespace move is not a token rename: the moved declaration keeps its short name while every
 * reference that depended on the old namespace context changes meaning. This separates the three
 * observable forms - absolute imports, fully qualified names, and namespace-relative names - so
 * only the last one has to be rewritten, and only as an exact byte-range replacement.
 */
final readonly class SourceClassMoveLocator
{
    public function __construct(private SourceClassNameLocator $locator)
    {
    }

    /**
     * The single unbraced namespace declaration a file-level move is allowed to rewrite.
     *
     * @return array{start_file_pos: int, end_file_pos: int, expected: string, line_start: int, line_end: int}
     */
    public function namespaceDeclaration(string $path, string $expectedNamespace): array
    {
        $declarations = [];
        foreach ($this->locator->astFor($path) as $node) {
            if ($node instanceof Namespace_) {
                $declarations[] = $node;
            }
        }

        if ($declarations === []) {
            throw new RuntimeException('Cannot move a class declared in the global namespace of ' . $path . '; contract 1.0 rewrites an existing namespace declaration rather than inserting one.');
        }
        if (count($declarations) > 1) {
            throw new RuntimeException(sprintf(
                'Cannot move a class from %s: the file declares %d namespace statements instead of exactly one.',
                $path,
                count($declarations),
            ));
        }

        $declaration = $declarations[0];
        if ($declaration->getAttribute('kind') === Namespace_::KIND_BRACED) {
            throw new RuntimeException('Cannot move a class out of a braced namespace block in ' . $path . '.');
        }
        if (!$declaration->name instanceof Name) {
            throw new RuntimeException('Cannot move a class declared in the global namespace of ' . $path . '.');
        }
        if (strcasecmp($declaration->name->toString(), $expectedNamespace) !== 0) {
            throw new RuntimeException(sprintf(
                'Namespace declaration in %s is "%s" but the indexed class identity expects "%s".',
                $path,
                $declaration->name->toString(),
                $expectedNamespace,
            ));
        }

        $token = $this->token($path, $declaration->name);

        return [
            'start_file_pos' => $token['start_file_pos'],
            'end_file_pos' => $token['end_file_pos'],
            'expected' => $token['expected'],
            'line_start' => $declaration->name->getStartLine(),
            'line_end' => $declaration->name->getEndLine(),
        ];
    }

    /**
     * Alias (lower-cased) to fully qualified class import, for the file's own `use` statements.
     *
     * @return array<string, string>
     */
    public function classImports(string $path): array
    {
        $imports = [];
        foreach ($this->flatten($path) as $node) {
            if ($node instanceof Use_) {
                foreach ($node->uses as $use) {
                    if ($this->isClassUse($use, $node->type)) {
                        $imports[strtolower($this->alias($use))] = ltrim($use->name->toString(), '\\');
                    }
                }
                continue;
            }
            if ($node instanceof GroupUse) {
                $prefix = $node->prefix->toString();
                foreach ($node->uses as $use) {
                    if ($this->isClassUse($use, $node->type)) {
                        $imports[strtolower($this->alias($use))] = ltrim($prefix . '\\' . $use->name->toString(), '\\');
                    }
                }
            }
        }

        return $imports;
    }

    /**
     * Every observable consequence one namespace move has for a single indexed file.
     *
     * @param array<string, string> $imports
     * @return array{edits: list<PlanEdit>, blind_spots: list<PlanBlindSpot>, blockers: list<string>, fallback_names: list<array{name: string, line: int}>}
     */
    public function collect(
        string $path,
        string $sourceSha256,
        string $sourceFqn,
        string $destinationFqn,
        string $symbolId,
        array $imports,
        bool $isMovedFile,
    ): array {
        $result = ['edits' => [], 'blind_spots' => [], 'blockers' => [], 'fallback_names' => []];
        foreach ($this->locator->astFor($path) as $node) {
            $this->walk($node, $path, $sourceSha256, $sourceFqn, $destinationFqn, $symbolId, $imports, $isMovedFile, $result);
        }

        foreach ($this->docCommentBlindSpots($path, $sourceFqn) as $blindSpot) {
            $result['blind_spots'][] = $blindSpot;
        }

        $result['edits'] = $this->uniqueEdits($result['edits']);
        $result['blind_spots'] = $this->uniqueBlindSpots($result['blind_spots']);
        $result['blockers'] = array_values(array_unique($result['blockers']));

        return $result;
    }

    /**
     * @param array<string, string> $imports
     * @param array{edits: list<PlanEdit>, blind_spots: list<PlanBlindSpot>, blockers: list<string>, fallback_names: list<array{name: string, line: int}>} $result
     */
    private function walk(
        Node $node,
        string $path,
        string $sourceSha256,
        string $sourceFqn,
        string $destinationFqn,
        string $symbolId,
        array $imports,
        bool $isMovedFile,
        array &$result,
    ): void {
        if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                if ($this->isClassUse($use, $node->type) && strcasecmp(ltrim($use->name->toString(), '\\'), $sourceFqn) === 0) {
                    $token = $this->token($path, $use->name);
                    $result['edits'][] = new PlanEdit(
                        path: $path,
                        sourceSha256: $sourceSha256,
                        startFilePos: $token['start_file_pos'],
                        endFilePos: $token['end_file_pos'],
                        lineStart: $use->name->getStartLine(),
                        lineEnd: $use->name->getEndLine(),
                        expected: $token['expected'],
                        replacement: str_starts_with($token['expected'], '\\') ? '\\' . $destinationFqn : $destinationFqn,
                        role: 'class_import',
                        symbolId: $symbolId,
                        resolution: 'parser_resolved',
                    );
                }
            }

            return;
        }

        if ($node instanceof GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                if ($this->isClassUse($use, $node->type) && strcasecmp(ltrim($prefix . '\\' . $use->name->toString(), '\\'), $sourceFqn) === 0) {
                    $result['blockers'][] = sprintf(
                        'Grouped import of %s in %s:%d cannot be rewritten as one exact token replacement when the namespace prefix changes.',
                        $sourceFqn,
                        $path,
                        $node->getStartLine(),
                    );
                }
            }

            return;
        }

        if ($node instanceof Namespace_) {
            foreach ($node->stmts as $statement) {
                $this->walk($statement, $path, $sourceSha256, $sourceFqn, $destinationFqn, $symbolId, $imports, $isMovedFile, $result);
            }

            return;
        }

        if ($node instanceof String_) {
            // Only the qualified literal changes meaning: the short name survives the move untouched.
            if (strcasecmp(ltrim($node->value, '\\'), $sourceFqn) === 0) {
                $result['blind_spots'][] = new PlanBlindSpot(
                    kind: 'class_string_literal',
                    message: 'String literal may encode the moved class and cannot be rewritten as a proven PHP class-name token.',
                    path: $path,
                    lineStart: $node->getStartLine(),
                    lineEnd: $node->getEndLine(),
                );
            }
        }

        if ($isMovedFile && $node instanceof MagicConst\Namespace_) {
            $result['blind_spots'][] = new PlanBlindSpot(
                kind: 'namespace_magic_constant',
                message: '__NAMESPACE__ changes value with the move; its consumers cannot be proven from PHP source alone.',
                path: $path,
                lineStart: $node->getStartLine(),
                lineEnd: $node->getEndLine(),
            );
        }

        if ($this->isDynamicClassOperation($node)) {
            $result['blind_spots'][] = new PlanBlindSpot(
                kind: 'dynamic_class_name',
                message: 'Dynamic class-name operation may resolve to the moved class at runtime; exact target identity cannot be proven.',
                path: $path,
                lineStart: $node->getStartLine(),
                lineEnd: $node->getEndLine(),
            );
        }

        if ($node instanceof Name) {
            $this->collectName($node, $path, $sourceSha256, $sourceFqn, $destinationFqn, $symbolId, $imports, $isMovedFile, $result);
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};
            if ($child instanceof Node) {
                $this->walk($child, $path, $sourceSha256, $sourceFqn, $destinationFqn, $symbolId, $imports, $isMovedFile, $result);
                continue;
            }
            if (!is_array($child)) {
                continue;
            }
            foreach ($child as $item) {
                if ($item instanceof Node) {
                    $this->walk($item, $path, $sourceSha256, $sourceFqn, $destinationFqn, $symbolId, $imports, $isMovedFile, $result);
                }
            }
        }
    }

    /**
     * @param array<string, string> $imports
     * @param array{edits: list<PlanEdit>, blind_spots: list<PlanBlindSpot>, blockers: list<string>, fallback_names: list<array{name: string, line: int}>} $result
     */
    private function collectName(
        Name $node,
        string $path,
        string $sourceSha256,
        string $sourceFqn,
        string $destinationFqn,
        string $symbolId,
        array $imports,
        bool $isMovedFile,
        array &$result,
    ): void {
        $resolved = $this->resolvedFqn($node);
        $token = $this->token($path, $node);
        $written = $token['expected'];

        if ($resolved === null) {
            // Unresolved bare names are function/constant lookups that fall back to the global
            // namespace. Their meaning depends on the enclosing namespace, so the moved file has to
            // account for them; other files are unaffected.
            if ($isMovedFile && !str_contains($written, '\\') && !$this->isReservedConstant($written)) {
                $result['fallback_names'][] = ['name' => $written, 'line' => $node->getStartLine()];
            }

            return;
        }

        $relative = $this->isNamespaceRelative($written, $imports);

        if (strcasecmp($resolved, $sourceFqn) === 0) {
            if ($isMovedFile && $relative && !str_contains($written, '\\')) {
                // The declaration travels with the file, so an unqualified self-reference keeps
                // resolving to the same class after the namespace edit.
                return;
            }
            if (!$relative && !str_contains($written, '\\')) {
                // An alias imported from the moved class; the import edit already carries it.
                return;
            }

            $result['edits'][] = $this->edit($path, $sourceSha256, $node, $token, '\\' . $destinationFqn, 'class_reference', $symbolId);
            if ($relative) {
                $result['blind_spots'][] = new PlanBlindSpot(
                    kind: 'namespace_relative_reference',
                    message: 'Reference resolved through the enclosing namespace instead of an import; contract 1.0 projects it as a fully qualified destination name rather than synthesizing a new import.',
                    path: $path,
                    lineStart: $node->getStartLine(),
                    lineEnd: $node->getEndLine(),
                );
            }

            return;
        }

        if ($isMovedFile && $relative) {
            // Everything the moved file resolved through its own namespace would silently rebind to
            // the destination namespace, so it has to be pinned to the identity it has today.
            $result['edits'][] = $this->edit($path, $sourceSha256, $node, $token, '\\' . $resolved, 'namespace_dependency', $symbolId);
            $result['blind_spots'][] = new PlanBlindSpot(
                kind: 'namespace_relative_dependency',
                message: 'Moved source resolved ' . $resolved . ' through its current namespace; contract 1.0 pins it to the fully qualified identity instead of synthesizing a new import.',
                path: $path,
                lineStart: $node->getStartLine(),
                lineEnd: $node->getEndLine(),
            );
        }
    }

    /** @param array{start_file_pos: int, end_file_pos: int, expected: string} $token */
    private function edit(string $path, string $sourceSha256, Name $node, array $token, string $replacement, string $role, string $symbolId): PlanEdit
    {
        return new PlanEdit(
            path: $path,
            sourceSha256: $sourceSha256,
            startFilePos: $token['start_file_pos'],
            endFilePos: $token['end_file_pos'],
            lineStart: $node->getStartLine(),
            lineEnd: $node->getEndLine(),
            expected: $token['expected'],
            replacement: $replacement,
            role: $role,
            symbolId: $symbolId,
            resolution: 'parser_resolved',
        );
    }

    /**
     * A name whose meaning depends on the enclosing namespace: neither absolute nor rooted in an import.
     *
     * @param array<string, string> $imports
     */
    private function isNamespaceRelative(string $written, array $imports): bool
    {
        if (str_starts_with($written, '\\')) {
            return false;
        }

        $separator = strpos($written, '\\');
        $first = $separator === false ? $written : substr($written, 0, $separator);

        return !isset($imports[strtolower($first)]);
    }

    private function resolvedFqn(Name $node): ?string
    {
        $resolved = $node->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }
        if ($node instanceof FullyQualified) {
            return ltrim($node->toString(), '\\');
        }

        return null;
    }

    private function isReservedConstant(string $written): bool
    {
        return in_array(strtolower($written), ['true', 'false', 'null'], true);
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

    private function alias(UseItem $use): string
    {
        if ($use->alias !== null) {
            return $use->alias->toString();
        }

        return $this->shortName($use->name->toString());
    }

    /** @return list<PlanBlindSpot> */
    private function docCommentBlindSpots(string $path, string $sourceFqn): array
    {
        $short = $this->shortName($sourceFqn);
        $blindSpots = [];
        foreach ($this->flatten($path) as $node) {
            $docComment = $node->getDocComment();
            if ($docComment === null) {
                continue;
            }
            $comment = $docComment->getText();
            $mentionsShort = preg_match('/(?<![A-Za-z0-9_\\\\])' . preg_quote($short, '/') . '(?![A-Za-z0-9_])/i', $comment);
            if ($mentionsShort === false) {
                throw new RuntimeException('Cannot evaluate PHPDoc class-name evidence for class move.');
            }
            if (stripos($comment, $sourceFqn) === false && $mentionsShort !== 1) {
                continue;
            }
            $blindSpots[] = new PlanBlindSpot(
                kind: 'phpdoc_type_reference',
                message: 'PHPDoc may reference the moved class; docblock rewriting is not part of the exact-token contract.',
                path: $path,
                lineStart: $docComment->getStartLine(),
                lineEnd: $docComment->getEndLine(),
            );
        }

        return $blindSpots;
    }

    /** @return array{start_file_pos: int, end_file_pos: int, expected: string} */
    private function token(string $path, Node $node): array
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose byte positions for a class move token in ' . $path . '.');
        }

        $actual = substr($this->locator->sourceFor($path), $start, $end - $start + 1);
        if ($actual === '') {
            throw new RuntimeException('Parser exposed an empty class move token in ' . $path . '.');
        }

        return ['start_file_pos' => $start, 'end_file_pos' => $end, 'expected' => $actual];
    }

    /** @return list<Node> */
    private function flatten(string $path): array
    {
        $flat = [];
        foreach ($this->locator->astFor($path) as $node) {
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

    private function shortName(string $fqn): string
    {
        $separator = strrpos($fqn, '\\');

        return $separator === false ? $fqn : substr($fqn, $separator + 1);
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

        return array_values($unique);
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
