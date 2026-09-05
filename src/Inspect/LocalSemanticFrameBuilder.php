<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\LocalBindingEntry;
use voku\AgentMap\Index\LocalExitEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

final readonly class LocalSemanticFrameBuilder
{
    public function build(AgentMapIndex $index, ScopeTarget $target): LocalSemanticFrame
    {
        $targetId = $target->sourceId ?? ('file:' . $target->file . '#L' . $target->lineStart);
        $absolute = rtrim($index->root, '/') . '/' . $target->file;
        if (!is_file($absolute)) {
            return new LocalSemanticFrame($targetId, $target->file, $target->lineStart, $target->lineEnd, []);
        }

        $code = file_get_contents($absolute);
        if (!is_string($code)) {
            throw new RuntimeException('Unable to read source file: ' . $absolute);
        }

        $ast = PhpCodeParser::getAstFromString($code);
        $methodNode = $this->findTargetNode(array_values($ast), $target->lineStart, $target->lineEnd);
        if ($methodNode === null) {
            return new LocalSemanticFrame($targetId, $target->file, $target->lineStart, $target->lineEnd, []);
        }

        /** @var list<Stmt>|null $stmts */
        $stmts = match (true) {
            $methodNode instanceof ClassMethod => $methodNode->stmts !== null ? array_values($methodNode->stmts) : null,
            $methodNode instanceof Function_ => array_values($methodNode->stmts),
            default => null,
        };

        if ($stmts === null || $stmts === []) {
            return new LocalSemanticFrame($targetId, $target->file, $target->lineStart, $target->lineEnd, []);
        }

        $bindings = $index->bindingsFor($targetId);
        if ($bindings === []) {
            $bindings = array_values(array_filter(
                $index->localBindings,
                static fn (LocalBindingEntry $b): bool => $b->file === $target->file
                    && $b->lineStart >= $target->lineStart
                    && $b->lineEnd <= $target->lineEnd,
            ));
        }

        $exits = $index->exitsFor($targetId);
        if ($exits === []) {
            $exits = array_values(array_filter(
                $index->localExits,
                static fn (LocalExitEntry $e): bool => $e->file === $target->file
                    && $e->lineStart >= $target->lineStart
                    && $e->lineEnd <= $target->lineEnd,
            ));
        }

        $relations = array_values(array_filter(
            $index->relations,
            static fn (RelationEntry $r): bool => $r->file === $target->file
                && $r->lineStart >= $target->lineStart
                && $r->lineEnd <= $target->lineEnd,
        ));

        $bindingsByPos = [];
        $bindingsByLineVar = [];
        foreach ($bindings as $binding) {
            if ($binding->startFilePos !== null) {
                $bindingsByPos[$binding->startFilePos] = $binding;
            }
            $bindingsByLineVar[$binding->lineStart . ':' . $binding->variable] = $binding;
        }

        $exitsByPos = [];
        $exitsByLine = [];
        foreach ($exits as $exit) {
            if ($exit->startFilePos !== null) {
                $exitsByPos[$exit->startFilePos] = $exit;
            }
            $exitsByLine[$exit->lineStart][] = $exit;
        }

        $relationsByLineKind = [];
        foreach ($relations as $rel) {
            $relationsByLineKind[$rel->lineStart . ':' . $rel->kind][] = $rel;
        }

        $checkpoints = [];
        $this->walkStatements(
            $stmts,
            $code,
            $bindingsByPos,
            $bindingsByLineVar,
            $exitsByPos,
            $exitsByLine,
            $relationsByLineKind,
            $checkpoints,
        );

        usort(
            $checkpoints,
            static fn (LocalSemanticCheckpoint $left, LocalSemanticCheckpoint $right): int =>
                ($left->startFilePos() ?? 0) <=> ($right->startFilePos() ?? 0)
                ?: $left->line() <=> $right->line(),
        );

        return new LocalSemanticFrame(
            targetId: $targetId,
            file: $target->file,
            lineStart: $target->lineStart,
            lineEnd: $target->lineEnd,
            checkpoints: $checkpoints,
        );
    }

    /**
     * @param list<Stmt> $stmts
     * @param array<int, LocalBindingEntry> $bindingsByPos
     * @param array<string, LocalBindingEntry> $bindingsByLineVar
     * @param array<int, LocalExitEntry> $exitsByPos
     * @param array<int, list<LocalExitEntry>> $exitsByLine
     * @param array<string, list<RelationEntry>> $relationsByLineKind
     * @param list<LocalSemanticCheckpoint> $checkpoints
     */
    private function walkStatements(
        array $stmts,
        string $code,
        array $bindingsByPos,
        array $bindingsByLineVar,
        array $exitsByPos,
        array $exitsByLine,
        array $relationsByLineKind,
        array &$checkpoints,
    ): void {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof If_) {
                $condStart = $stmt->cond->getStartFilePos();
                $condEnd = $stmt->cond->getEndFilePos();
                $condText = $condStart >= 0 && $condEnd >= $condStart
                    ? trim(substr($code, $condStart, $condEnd - $condStart + 1))
                    : 'condition';

                $var = $this->extractConditionVariable($stmt->cond);
                $exitInfo = $this->checkBlockExits(array_values($stmt->stmts), $code);

                if ($exitInfo !== null) {
                    $narrowing = $this->detectNarrowing($stmt->cond, $var);
                    $checkpoints[] = new LocalGuardCheckpoint(
                        line: $stmt->getStartLine(),
                        condition: $condText,
                        variable: $var,
                        exits: true,
                        exitKind: $exitInfo['kind'],
                        exitTarget: $exitInfo['target'],
                        narrowing: $narrowing,
                        startFilePos: $stmt->getStartFilePos() >= 0 ? $stmt->getStartFilePos() : null,
                    );

                    if ($stmt->else !== null) {
                        $this->walkStatements(
                            array_values($stmt->else->stmts),
                            $code,
                            $bindingsByPos,
                            $bindingsByLineVar,
                            $exitsByPos,
                            $exitsByLine,
                            $relationsByLineKind,
                            $checkpoints,
                        );
                    }
                } else {
                    $checkpoints[] = new LocalGuardCheckpoint(
                        line: $stmt->getStartLine(),
                        condition: $condText,
                        variable: $var,
                        exits: false,
                        startFilePos: $stmt->getStartFilePos() >= 0 ? $stmt->getStartFilePos() : null,
                    );

                    $this->walkStatements(
                        array_values($stmt->stmts),
                        $code,
                        $bindingsByPos,
                        $bindingsByLineVar,
                        $exitsByPos,
                        $exitsByLine,
                        $relationsByLineKind,
                        $checkpoints,
                    );

                    if ($stmt->else !== null) {
                        $this->walkStatements(
                            array_values($stmt->else->stmts),
                            $code,
                            $bindingsByPos,
                            $bindingsByLineVar,
                            $exitsByPos,
                            $exitsByLine,
                            $relationsByLineKind,
                            $checkpoints,
                        );
                    }
                }
                continue;
            }

            if ($stmt instanceof Expression) {
                $expr = $stmt->expr;
                if ($expr instanceof Assign || $expr instanceof AssignOp || $expr instanceof AssignRef) {
                    $var = $expr->var instanceof Variable && is_string($expr->var->name)
                        ? '$' . $expr->var->name
                        : null;

                    $startPos = $expr->getStartFilePos();
                    $endPos = $expr->getEndFilePos();
                    $snippet = $startPos >= 0 && $endPos >= $startPos
                        ? trim(substr($code, $startPos, $endPos - $startPos + 1))
                        : '$var = ...';

                    $binding = ($startPos >= 0 && isset($bindingsByPos[$startPos]))
                        ? $bindingsByPos[$startPos]
                        : ($var !== null ? ($bindingsByLineVar[$expr->getStartLine() . ':' . $var] ?? null) : null);

                    $resolvedType = $binding !== null ? $binding->resolvedType : 'mixed';
                    $expressionKind = $binding !== null ? $binding->expressionKind : $this->expressionKind($expr->expr);
                    $literalValue = $binding !== null ? $binding->literalValue : $this->extractLiteral($expr->expr);

                    $receiverType = null;
                    $callTarget = null;
                    if ($expr->expr instanceof MethodCall || $expr->expr instanceof NullsafeMethodCall) {
                        $callRels = $relationsByLineKind[$expr->getStartLine() . ':calls'] ?? [];
                        if ($callRels !== []) {
                            $receiverType = $callRels[0]->receiverType;
                            $callTarget = $callRels[0]->targetIds[0];
                        }
                    }

                    if ($var !== null) {
                        $checkpoints[] = new LocalBindingCheckpoint(
                            line: $stmt->getStartLine(),
                            variable: $var,
                            resolvedType: $resolvedType,
                            expressionKind: $expressionKind,
                            codeSnippet: $snippet,
                            literalValue: $literalValue,
                            receiverType: $receiverType,
                            callTarget: $callTarget,
                            startFilePos: $stmt->getStartFilePos() >= 0 ? $stmt->getStartFilePos() : null,
                        );
                    }
                    continue;
                }

                if ($expr instanceof Throw_) {
                    $startPos = $stmt->getStartFilePos();
                    $endPos = $stmt->getEndFilePos();
                    $snippet = $startPos >= 0 && $endPos >= $startPos
                        ? trim(substr($code, $startPos, $endPos - $startPos + 1))
                        : 'throw ...';

                    $exit = ($startPos >= 0 && isset($exitsByPos[$startPos]))
                        ? $exitsByPos[$startPos]
                        : ($exitsByLine[$stmt->getStartLine()][0] ?? null);

                    $checkpoints[] = new LocalExitCheckpoint(
                        line: $stmt->getStartLine(),
                        exitKind: 'throw',
                        expressionType: $exit !== null ? $exit->expressionType : 'Throwable',
                        variable: $exit?->variable,
                        literalValue: $exit?->literalValue,
                        codeSnippet: $snippet,
                        startFilePos: $stmt->getStartFilePos() >= 0 ? $stmt->getStartFilePos() : null,
                    );
                    continue;
                }

                if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
                    if ($expr->var instanceof Variable && is_string($expr->var->name)) {
                        $var = '$' . $expr->var->name;
                        $startPos = $expr->getStartFilePos();
                        $endPos = $expr->getEndFilePos();
                        $snippet = $startPos >= 0 && $endPos >= $startPos
                            ? trim(substr($code, $startPos, $endPos - $startPos + 1))
                            : '$var->call()';

                        $callRels = $relationsByLineKind[$stmt->getStartLine() . ':calls'] ?? [];
                        $rel = $callRels[0] ?? null;

                        $checkpoints[] = new LocalUseCheckpoint(
                            line: $stmt->getStartLine(),
                            variable: $var,
                            expression: $snippet,
                            receiverType: $rel?->receiverType,
                            targetId: $rel !== null ? $rel->targetIds[0] : null,
                            resultType: $rel?->resultType,
                            startFilePos: $stmt->getStartFilePos() >= 0 ? $stmt->getStartFilePos() : null,
                        );
                    }
                    continue;
                }
            }

            if ($stmt instanceof Return_) {
                $startPos = $stmt->getStartFilePos();
                $endPos = $stmt->getEndFilePos();
                $snippet = $startPos >= 0 && $endPos >= $startPos
                    ? trim(substr($code, $startPos, $endPos - $startPos + 1))
                    : 'return';

                $exit = ($startPos >= 0 && isset($exitsByPos[$startPos]))
                    ? $exitsByPos[$startPos]
                    : ($exitsByLine[$stmt->getStartLine()][0] ?? null);

                $checkpoints[] = new LocalExitCheckpoint(
                    line: $stmt->getStartLine(),
                    exitKind: 'return',
                    expressionType: $exit !== null ? $exit->expressionType : ($stmt->expr === null ? 'void' : 'mixed'),
                    variable: $exit?->variable,
                    literalValue: $exit?->literalValue,
                    codeSnippet: $snippet,
                    startFilePos: $stmt->getStartFilePos() >= 0 ? $stmt->getStartFilePos() : null,
                );
                continue;
            }

            if ($stmt instanceof Foreach_) {
                if ($stmt->valueVar instanceof Variable && is_string($stmt->valueVar->name)) {
                    $valVar = '$' . $stmt->valueVar->name;
                    $binding = $bindingsByLineVar[$stmt->getStartLine() . ':' . $valVar] ?? null;
                    $checkpoints[] = new LocalBindingCheckpoint(
                        line: $stmt->getStartLine(),
                        variable: $valVar,
                        resolvedType: $binding !== null ? $binding->resolvedType : 'mixed',
                        expressionKind: 'foreach',
                        codeSnippet: $valVar,
                        startFilePos: $stmt->valueVar->getStartFilePos() >= 0 ? $stmt->valueVar->getStartFilePos() : null,
                    );
                }
                $this->walkStatements(
                    array_values($stmt->stmts),
                    $code,
                    $bindingsByPos,
                    $bindingsByLineVar,
                    $exitsByPos,
                    $exitsByLine,
                    $relationsByLineKind,
                    $checkpoints,
                );
                continue;
            }

            if ($stmt instanceof TryCatch) {
                $this->walkStatements(
                    array_values($stmt->stmts),
                    $code,
                    $bindingsByPos,
                    $bindingsByLineVar,
                    $exitsByPos,
                    $exitsByLine,
                    $relationsByLineKind,
                    $checkpoints,
                );
                foreach ($stmt->catches as $catch) {
                    if ($catch->var !== null && is_string($catch->var->name)) {
                        $catchVar = '$' . $catch->var->name;
                        $binding = $bindingsByLineVar[$catch->getStartLine() . ':' . $catchVar] ?? null;
                        $checkpoints[] = new LocalBindingCheckpoint(
                            line: $catch->getStartLine(),
                            variable: $catchVar,
                            resolvedType: $binding !== null ? $binding->resolvedType : 'Throwable',
                            expressionKind: 'catch',
                            codeSnippet: $catchVar,
                            startFilePos: $catch->getStartFilePos() >= 0 ? $catch->getStartFilePos() : null,
                        );
                    }
                    $this->walkStatements(
                        array_values($catch->stmts),
                        $code,
                        $bindingsByPos,
                        $bindingsByLineVar,
                        $exitsByPos,
                        $exitsByLine,
                        $relationsByLineKind,
                        $checkpoints,
                    );
                }
                continue;
            }

            if (property_exists($stmt, 'stmts') && is_array($stmt->stmts)) {
                /** @var list<Stmt> $nestedStmts */
                $nestedStmts = array_values(array_filter($stmt->stmts, static fn (mixed $s): bool => $s instanceof Stmt));
                $this->walkStatements(
                    $nestedStmts,
                    $code,
                    $bindingsByPos,
                    $bindingsByLineVar,
                    $exitsByPos,
                    $exitsByLine,
                    $relationsByLineKind,
                    $checkpoints,
                );
            }
        }
    }

    /**
     * @param list<Node> $ast
     */
    private function findTargetNode(array $ast, int $lineStart, int $lineEnd): ?Node
    {
        foreach ($ast as $node) {
            if ($node->getStartLine() === $lineStart && $node->getEndLine() === $lineEnd) {
                return $node;
            }
            if (property_exists($node, 'stmts') && is_array($node->stmts)) {
                /** @var list<Node> $nestedNodes */
                $nestedNodes = array_values(array_filter($node->stmts, static fn (mixed $s): bool => $s instanceof Node));
                $found = $this->findTargetNode($nestedNodes, $lineStart, $lineEnd);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function extractConditionVariable(Expr $cond): ?string
    {
        if ($cond instanceof Identical || $cond instanceof NotIdentical || $cond instanceof Equal || $cond instanceof NotEqual) {
            if ($cond->left instanceof Variable && is_string($cond->left->name)) {
                return '$' . $cond->left->name;
            }
            if ($cond->right instanceof Variable && is_string($cond->right->name)) {
                return '$' . $cond->right->name;
            }
        }

        if ($cond instanceof BooleanNot && $cond->expr instanceof Variable && is_string($cond->expr->name)) {
            return '$' . $cond->expr->name;
        }

        if ($cond instanceof Instanceof_ && $cond->expr instanceof Variable && is_string($cond->expr->name)) {
            return '$' . $cond->expr->name;
        }

        if ($cond instanceof Variable && is_string($cond->name)) {
            return '$' . $cond->name;
        }

        if ($cond instanceof FuncCall && isset($cond->args[0]) && $cond->args[0] instanceof Arg && $cond->args[0]->value instanceof Variable && is_string($cond->args[0]->value->name)) {
            return '$' . $cond->args[0]->value->name;
        }

        return null;
    }

    /**
     * @param list<Stmt> $stmts
     * @return array{kind: string, target: ?string}|null
     */
    private function checkBlockExits(array $stmts, string $code): ?array
    {
        if ($stmts === []) {
            return null;
        }

        $last = end($stmts);
        if ($last instanceof Return_) {
            $target = null;
            if ($last->expr !== null) {
                $start = $last->expr->getStartFilePos();
                $end = $last->expr->getEndFilePos();
                if ($start >= 0 && $end >= $start) {
                    $target = trim(substr($code, $start, $end - $start + 1));
                }
            }

            return ['kind' => 'return', 'target' => $target];
        }

        if ($last instanceof Expression && $last->expr instanceof Throw_) {
            $start = $last->expr->expr->getStartFilePos();
            $end = $last->expr->expr->getEndFilePos();
            $target = $start >= 0 && $end >= $start ? trim(substr($code, $start, $end - $start + 1)) : null;

            return ['kind' => 'throw', 'target' => $target];
        }

        return null;
    }

    private function detectNarrowing(Expr $cond, ?string $var): ?string
    {
        if ($var === null) {
            return null;
        }

        if ($cond instanceof Identical || $cond instanceof Equal) {
            $other = null;
            if ($cond->left instanceof Variable && is_string($cond->left->name)) {
                $leftName = '$' . $cond->left->name;
                if ($leftName === $var) {
                    $other = $cond->right;
                }
            }
            if ($other === null && $cond->right instanceof Variable && is_string($cond->right->name)) {
                $rightName = '$' . $cond->right->name;
                if ($rightName === $var) {
                    $other = $cond->left;
                }
            }

            if ($other instanceof ConstFetch) {
                $name = strtolower($other->name->toString());
                if ($name === 'false') {
                    return 'excludes false';
                }
                if ($name === 'null') {
                    return 'excludes null';
                }
                if ($name === 'true') {
                    return 'excludes true';
                }
            }
        }

        if ($cond instanceof BooleanNot) {
            return 'truthy';
        }

        if ($cond instanceof Instanceof_ && $cond->class instanceof Name) {
            return $cond->class->toString();
        }

        return null;
    }

    private function expressionKind(Expr $expr): string
    {
        return match (true) {
            $expr instanceof MethodCall => 'method_call',
            $expr instanceof NullsafeMethodCall => 'nullsafe_method_call',
            $expr instanceof Expr\StaticCall => 'static_call',
            $expr instanceof FuncCall => 'function_call',
            $expr instanceof Expr\PropertyFetch => 'property_fetch',
            $expr instanceof Expr\NullsafePropertyFetch => 'nullsafe_property_fetch',
            $expr instanceof Expr\StaticPropertyFetch => 'static_property_fetch',
            $expr instanceof Expr\New_ => 'new',
            $expr instanceof Expr\Array_ => 'array',
            $expr instanceof Scalar => 'literal',
            $expr instanceof ConstFetch => 'literal',
            $expr instanceof Variable => 'variable',
            $expr instanceof Expr\Ternary => 'ternary',
            default => 'other',
        };
    }

    private function extractLiteral(Expr $expr): ?string
    {
        if ($expr instanceof Scalar\String_) {
            $encoded = json_encode($expr->value, JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : null;
        }
        if ($expr instanceof Scalar\Int_ || $expr instanceof Scalar\Float_) {
            return (string) $expr->value;
        }
        if ($expr instanceof ConstFetch) {
            $name = strtolower($expr->name->toString());
            if (in_array($name, ['true', 'false', 'null'], true)) {
                return $name;
            }
        }

        return null;
    }
}
