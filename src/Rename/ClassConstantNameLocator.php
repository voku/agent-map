<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use RuntimeException;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/** Locates class-constant declarations and statically named fetches in current source. */
final class ClassConstantNameLocator
{
    /** @var array<string, array<int, Node>> */
    private array $ast = [];

    /** @var array<string, string> */
    private array $sources = [];

    /** Uses the indexed project root as the only source-reading boundary. */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Locates exact declaration/fetch tokens and emits review evidence whenever owner identity is not proven.
     *
     * @return array{edits: list<array{start_file_pos: int, end_file_pos: int, line_start: int, line_end: int, role: string}>, blind_spots: list<PlanBlindSpot>, collision: bool}
     */
    public function locate(string $path, string $ownerFqn, string $original, string $replacement): array
    {
        $edits = [];
        $blindSpots = [];
        $collision = false;

        foreach ($this->nodes($path) as [$node, $classFqn]) {
            if ($node instanceof ClassConst && strcasecmp($classFqn ?? '', $ownerFqn) === 0) {
                foreach ($node->consts as $constant) {
                    $name = $constant->name->toString();
                    if ($name === $replacement && $name !== $original) {
                        $collision = true;
                    }
                    if ($name === $original) {
                        $edits[] = $this->position($path, $constant->name, 'declaration');
                    }
                }

                continue;
            }

            if (!$node instanceof ClassConstFetch) {
                continue;
            }

            if (!$node->name instanceof Identifier) {
                $blindSpots[] = new PlanBlindSpot(
                    'dynamic_class_constant_name',
                    'A dynamic class-constant name may resolve to the renamed constant at runtime.',
                    $path,
                    $node->getStartLine(),
                    $node->getEndLine(),
                );
                continue;
            }

            if ($node->name->toString() !== $original) {
                continue;
            }

            if (!$node->class instanceof Name) {
                $blindSpots[] = new PlanBlindSpot(
                    'dynamic_class_constant_fetch',
                    'A dynamic class-constant owner may resolve to the renamed constant at runtime.',
                    $path,
                    $node->getStartLine(),
                    $node->getEndLine(),
                );
                continue;
            }

            $spelling = strtolower($node->class->toString());
            if ($spelling === 'static') {
                $blindSpots[] = new PlanBlindSpot(
                    'late_static_class_constant_fetch',
                    'static:: class-constant lookup is late-bound and cannot be assigned to one declaring class without inheritance evidence.',
                    $path,
                    $node->getStartLine(),
                    $node->getEndLine(),
                );
                continue;
            }

            $fetchOwner = $this->fetchOwner($node->class, $classFqn);
            if ($fetchOwner !== null && strcasecmp($fetchOwner, $ownerFqn) === 0) {
                $edits[] = $this->position($path, $node->name, 'fetch');
                continue;
            }

            $blindSpots[] = new PlanBlindSpot(
                'unproven_class_constant_owner',
                'A same-name class-constant fetch has no proven declaring-owner identity; inherited and parent lookups require review.',
                $path,
                $node->getStartLine(),
                $node->getEndLine(),
            );
        }

        return ['edits' => $edits, 'blind_spots' => $blindSpots, 'collision' => $collision];
    }

    /** Resolves only owners whose identity is parser-proven; late-static and parent lookups stay unresolved. */
    private function fetchOwner(Name $name, ?string $classFqn): ?string
    {
        $spelling = strtolower($name->toString());
        if ($spelling === 'self' && $classFqn !== null) {
            return $classFqn;
        }

        if ($spelling === 'static' || $spelling === 'parent') {
            return null;
        }

        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Name) {
            return ltrim($resolved->toString(), '\\');
        }

        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        return null;
    }

    /** @return array{start_file_pos: int, end_file_pos: int, line_start: int, line_end: int, role: string} Exact parser byte evidence. */
    private function position(string $path, Identifier $name, string $role): array
    {
        $start = $name->getStartFilePos();
        $end = $name->getEndFilePos();
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Parser did not expose class-constant byte positions in ' . $path . '.');
        }

        return [
            'start_file_pos' => $start,
            'end_file_pos' => $end,
            'line_start' => $name->getStartLine(),
            'line_end' => $name->getEndLine(),
            'role' => $role,
        ];
    }

    /** @return list<array{0: Node, 1: ?string}> Flattened AST nodes paired with their lexical class-like FQN. */
    private function nodes(string $path): array
    {
        if (!isset($this->ast[$path])) {
            $this->ast[$path] = PhpCodeParser::getAstFromString($this->source($path));
        }

        $result = [];
        foreach ($this->ast[$path] as $node) {
            $this->append($result, $node, null);
        }

        return $result;
    }

    /** @param list<array{0: Node, 1: ?string}> $result Flattened node accumulator. */
    private function append(array &$result, Node $node, ?string $classFqn): void
    {
        if ($node instanceof ClassLike) {
            $name = $node->getAttribute('namespacedName');
            if ($name instanceof Name) {
                $classFqn = ltrim($name->toString(), '\\');
            } elseif ($node->namespacedName instanceof Name) {
                $classFqn = ltrim($node->namespacedName->toString(), '\\');
            }
        }

        $result[] = [$node, $classFqn];
        foreach ($node->getSubNodeNames() as $key) {
            $child = $node->{$key};
            if ($child instanceof Node) {
                $this->append($result, $child, $classFqn);
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $this->append($result, $item, $classFqn);
                    }
                }
            }
        }
    }

    /** Reads and caches one indexed source file, failing instead of substituting missing content. */
    private function source(string $path): string
    {
        if (!isset($this->sources[$path])) {
            $source = file_get_contents(rtrim($this->root, '/\\') . '/' . ltrim(str_replace('\\', '/', $path), '/'));
            if (!is_string($source)) {
                throw new RuntimeException('Cannot read class-constant rename source file: ' . $path);
            }
            $this->sources[$path] = $source;
        }

        return $this->sources[$path];
    }
}
