<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

use PhpParser\NodeTraverser;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

/**
 * Reads and parses the target's owning file once, then walks only the AST
 * nodes inside its [line_start, line_end] range — the whole point being to
 * give an agent enough to navigate without opening the full file.
 */
final readonly class ScopeInspector
{
    public const DEFAULT_TEMPLATE_NAMES = ['render', 'display', 'fetch', 'load', 'view'];

    /**
     * @param list<string> $templateNames
     */
    public function __construct(
        private array $templateNames = self::DEFAULT_TEMPLATE_NAMES,
    ) {
    }

    public function inspect(AgentMapIndex $index, ScopeTarget $target, int $limit = 10): ScopeInspection
    {
        $absolute = rtrim($index->root, '/') . '/' . $target->file;
        $code = file_get_contents($absolute);
        if (!is_string($code)) {
            throw new RuntimeException('Unable to read source file: ' . $absolute);
        }

        $ast = PhpCodeParser::getAstFromString($code);

        $visitor = new ScopeAstVisitor($target->lineStart, $target->lineEnd, $this->templateNames);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        usort($visitor->calls, static fn (CallHint $left, CallHint $right): int => $left->line <=> $right->line);
        usort($visitor->tables, static fn (TableHint $left, TableHint $right): int => $left->line <=> $right->line);
        usort($visitor->templates, static fn (TemplateHint $left, TemplateHint $right): int => $left->line <=> $right->line);

        $calls = array_slice($this->enrichCalls($index, $target, $visitor->calls), 0, $limit);
        $tables = array_slice($visitor->tables, 0, $limit);
        $templates = array_slice($visitor->templates, 0, $limit);

        return new ScopeInspection(
            $target,
            $calls,
            $tables,
            $templates,
            count($visitor->calls) - count($calls),
            count($visitor->tables) - count($tables),
            count($visitor->templates) - count($templates),
        );
    }

    /**
     * @param list<CallHint> $calls
     * @return list<CallHint>
     */
    private function enrichCalls(AgentMapIndex $index, ScopeTarget $target, array $calls): array
    {
        $relations = array_values(array_filter(
            $index->relations,
            static fn (RelationEntry $relation): bool => $relation->file === $target->file
                && $relation->lineStart >= $target->lineStart
                && $relation->lineEnd <= $target->lineEnd
                && in_array($relation->kind, ['calls', 'instantiates'], true)
                && ($target->sourceId === null || $relation->sourceId === $target->sourceId),
        ));

        return array_map(
            fn (CallHint $call): CallHint => $this->enrichCall($call, $relations),
            $calls,
        );
    }

    /**
     * @param list<RelationEntry> $relations
     */
    private function enrichCall(CallHint $call, array $relations): CallHint
    {
        $expectedKind = $call->kind === 'constructor' ? 'instantiates' : 'calls';
        $candidates = array_values(array_filter(
            $relations,
            fn (RelationEntry $relation): bool => $relation->kind === $expectedKind
                && $relation->lineStart === $call->line
                && $this->relationMatchesCall($relation, $call),
        ));
        usort($candidates, static fn (RelationEntry $left, RelationEntry $right): int => $left->id <=> $right->id);
        $relation = $candidates[0] ?? null;
        if ($relation === null) {
            return $call;
        }

        return new CallHint(
            kind: $call->kind,
            label: $call->label,
            line: $call->line,
            targetIds: $relation->targetIds,
            resolution: $relation->resolution,
            receiverType: $relation->receiverType,
            resultType: $relation->resultType,
        );
    }

    private function relationMatchesCall(RelationEntry $relation, CallHint $call): bool
    {
        if (in_array($call->kind, ['dynamic_function', 'dynamic_method', 'dynamic_constructor'], true)) {
            return $relation->resolution === 'dynamic';
        }

        $name = match ($call->kind) {
            'method' => substr($call->label, (int) strrpos($call->label, '->') + 2),
            'static' => substr($call->label, (int) strrpos($call->label, '::') + 2),
            default => $call->label,
        };

        foreach ($relation->targetIds as $targetId) {
            if ($call->kind === 'constructor' && (str_ends_with($targetId, '\\' . $name) || $targetId === 'class:' . $name)) {
                return true;
            }
            if (in_array($call->kind, ['method', 'static'], true) && str_ends_with($targetId, '::' . $name)) {
                return true;
            }
            if ($call->kind === 'function' && (str_ends_with($targetId, '\\' . $name) || $targetId === 'function:' . $name)) {
                return true;
            }
        }

        return count($relation->targetIds) === 1 && str_starts_with($relation->targetIds[0], 'unresolved:');
    }
}
