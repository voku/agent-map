<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Expr\Throw_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParameterReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptor;

final readonly class Projection
{
    public static function callerId(Scope $scope): string
    {
        $function = $scope->getFunction();
        if ($function instanceof ExtendedMethodReflection) {
            return 'method:' . $function->getDeclaringClass()->getName() . '::' . $function->getName();
        }
        if ($function instanceof FunctionReflection) {
            return 'function:' . ltrim($function->getName(), '\\');
        }

        return 'file:' . str_replace('\\', '/', $scope->getFile());
    }

    /**
     * @param list<string> $targetIds
     * @return array<string, mixed>
     */
    public static function relation(
        string $kind,
        Scope $scope,
        Node $node,
        array $targetIds,
        ?string $receiverType,
        ?string $resultType,
    ): array {
        $targetIds = array_values(array_unique($targetIds));
        sort($targetIds, SORT_STRING);

        return [
            'record_type' => 'relation',
            'kind' => $kind,
            'source_id' => self::callerId($scope),
            'target_ids' => $targetIds,
            'file' => $scope->getFile(),
            'line_start' => $node->getStartLine(),
            'line_end' => $node->getEndLine(),
            'start_file_pos' => $node->getStartFilePos() >= 0 ? $node->getStartFilePos() : null,
            'end_file_pos' => $node->getEndFilePos() >= 0 ? $node->getEndFilePos() : null,
            'resolution' => $targetIds === [] ? 'dynamic' : (count($targetIds) === 1 ? 'phpstan_resolved' : 'multiple_targets'),
            'receiver_type' => $receiverType,
            'result_type' => $resultType,
        ];
    }

    /** @return array<string, mixed> */
    public static function methodCall(MethodCall|NullsafeMethodCall $node, Scope $scope): array
    {
        $receiverType = $scope->getType($node->var);
        $targets = [];
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->toString();
            foreach ($receiverType->getObjectClassReflections() as $classReflection) {
                if (!$classReflection->hasMethod($methodName)) {
                    continue;
                }
                $method = $classReflection->getMethod($methodName, $scope);
                $targets[] = 'method:' . $method->getDeclaringClass()->getName() . '::' . $method->getName();
            }
        }

        return self::relation(
            kind: 'calls',
            scope: $scope,
            node: $node,
            targetIds: $targets,
            receiverType: TypeProjector::describe($receiverType),
            resultType: TypeProjector::describe($scope->getType($node)),
        );
    }

    /** @return array<string, mixed> */
    public static function propertyAccess(PropertyFetch|NullsafePropertyFetch $node, Scope $scope): array
    {
        $receiverType = $scope->getType($node->var);
        $targets = [];
        if ($node->name instanceof Node\Identifier) {
            $propertyName = $node->name->toString();
            $property = $scope->getInstancePropertyReflection($receiverType, $propertyName);
            if ($property !== null) {
                $targets[] = 'property:' . $property->getDeclaringClass()->getName() . '::$' . $propertyName;
            }
        }

        return self::relation(
            kind: 'property_access',
            scope: $scope,
            node: $node,
            targetIds: $targets,
            receiverType: TypeProjector::describe($receiverType),
            resultType: TypeProjector::describe($scope->getType($node)),
        );
    }

    /** @return array<string, mixed> */
    public static function staticPropertyAccess(StaticPropertyFetch $node, Scope $scope): array
    {
        $receiverType = $node->class instanceof Node\Name
            ? $scope->resolveTypeByName($node->class)
            : $scope->getType($node->class);
        $targets = [];
        if ($node->name instanceof Node\VarLikeIdentifier) {
            $propertyName = $node->name->toString();
            $property = $scope->getStaticPropertyReflection($receiverType, $propertyName);
            if ($property !== null) {
                $targets[] = 'property:' . $property->getDeclaringClass()->getName() . '::$' . $propertyName;
            }
        }

        return self::relation(
            kind: 'property_access',
            scope: $scope,
            node: $node,
            targetIds: $targets,
            receiverType: TypeProjector::describe($receiverType),
            resultType: TypeProjector::describe($scope->getType($node)),
        );
    }

    /**
     * @return list<array{name: string, native_type: ?string, phpdoc_type: ?string, resolved_type: string, by_reference: bool, variadic: bool, referenced_classes: list<string>}>
     */
    public static function parameters(ParametersAcceptor $acceptor): array
    {
        $result = [];
        foreach ($acceptor->getParameters() as $parameter) {
            $nativeType = null;
            $phpDocType = null;
            if ($parameter instanceof ExtendedParameterReflection) {
                $nativeType = $parameter->hasNativeType() ? TypeProjector::describe($parameter->getNativeType()) : null;
                $phpDocType = TypeProjector::describe($parameter->getPhpDocType());
                if ($phpDocType === 'mixed' && $nativeType !== null && $nativeType !== 'mixed') {
                    $phpDocType = null;
                }
            }
            $resolvedType = TypeProjector::describe($parameter->getType());
            $result[] = [
                'name' => $parameter->getName(),
                'native_type' => $nativeType,
                'phpdoc_type' => $phpDocType,
                'resolved_type' => $resolvedType,
                'by_reference' => $parameter->passedByReference()->yes(),
                'variadic' => $parameter->isVariadic(),
                'referenced_classes' => TypeProjector::referencedClasses($parameter->getType()),
            ];
        }

        return $result;
    }

    public static function extractLiteral(Expr $expr): ?string
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

    public static function expressionKind(Expr $expr): string
    {
        if ($expr instanceof MethodCall) {
            return 'method_call';
        }
        if ($expr instanceof NullsafeMethodCall) {
            return 'nullsafe_method_call';
        }
        if ($expr instanceof StaticCall) {
            return 'static_call';
        }
        if ($expr instanceof FuncCall) {
            return 'func_call';
        }
        if ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            return 'property_fetch';
        }
        if ($expr instanceof StaticPropertyFetch) {
            return 'static_property_fetch';
        }
        if ($expr instanceof New_) {
            return 'new';
        }
        if ($expr instanceof Scalar || $expr instanceof ConstFetch) {
            return 'literal';
        }
        if ($expr instanceof Variable) {
            return 'variable';
        }
        if ($expr instanceof BinaryOp\Coalesce) {
            return 'coalesce';
        }
        if ($expr instanceof Ternary) {
            return 'ternary';
        }
        if ($expr instanceof ArrayDimFetch) {
            return 'array_dim_fetch';
        }
        if ($expr instanceof BinaryOp) {
            return 'binary_op';
        }

        return 'other';
    }

    public static function extractVariableName(Expr $expr): ?string
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            return '$' . $expr->name;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function assign(Assign|AssignOp|AssignRef $node, Scope $scope): ?array
    {
        $variable = self::extractVariableName($node->var);
        if ($variable === null) {
            return null;
        }

        $rhsType = $scope->getType($node->expr);
        $startFilePos = $node->getStartFilePos() >= 0 ? $node->getStartFilePos() : null;
        $endFilePos = $node->getEndFilePos() >= 0 ? $node->getEndFilePos() : null;
        $rhsStartFilePos = $node->expr->getStartFilePos() >= 0 ? $node->expr->getStartFilePos() : null;
        $rhsEndFilePos = $node->expr->getEndFilePos() >= 0 ? $node->expr->getEndFilePos() : null;

        return [
            'record_type' => 'local_binding',
            'owner_id' => self::callerId($scope),
            'variable' => $variable,
            'file' => $scope->getFile(),
            'line_start' => $node->getStartLine(),
            'line_end' => $node->getEndLine(),
            'resolved_type' => TypeProjector::describe($rhsType),
            'expression_kind' => self::expressionKind($node->expr),
            'literal_value' => self::extractLiteral($node->expr),
            'start_file_pos' => $startFilePos,
            'end_file_pos' => $endFilePos,
            'rhs_start_file_pos' => $rhsStartFilePos,
            'rhs_end_file_pos' => $rhsEndFilePos,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function foreach(Foreach_ $node, Scope $scope): array
    {
        $ownerId = self::callerId($scope);
        $file = $scope->getFile();
        $records = [];

        $valueVar = self::extractVariableName($node->valueVar);
        if ($valueVar !== null) {
            $records[] = [
                'record_type' => 'local_binding',
                'owner_id' => $ownerId,
                'variable' => $valueVar,
                'file' => $file,
                'line_start' => $node->valueVar->getStartLine(),
                'line_end' => $node->valueVar->getEndLine(),
                'resolved_type' => TypeProjector::describe($scope->getType($node->valueVar)),
                'expression_kind' => 'foreach',
                'literal_value' => null,
                'start_file_pos' => $node->valueVar->getStartFilePos() >= 0 ? $node->valueVar->getStartFilePos() : null,
                'end_file_pos' => $node->valueVar->getEndFilePos() >= 0 ? $node->valueVar->getEndFilePos() : null,
                'rhs_start_file_pos' => $node->expr->getStartFilePos() >= 0 ? $node->expr->getStartFilePos() : null,
                'rhs_end_file_pos' => $node->expr->getEndFilePos() >= 0 ? $node->expr->getEndFilePos() : null,
            ];
        }

        if ($node->keyVar !== null) {
            $keyVar = self::extractVariableName($node->keyVar);
            if ($keyVar !== null) {
                $records[] = [
                    'record_type' => 'local_binding',
                    'owner_id' => $ownerId,
                    'variable' => $keyVar,
                    'file' => $file,
                    'line_start' => $node->keyVar->getStartLine(),
                    'line_end' => $node->keyVar->getEndLine(),
                    'resolved_type' => TypeProjector::describe($scope->getType($node->keyVar)),
                    'expression_kind' => 'foreach',
                    'literal_value' => null,
                    'start_file_pos' => $node->keyVar->getStartFilePos() >= 0 ? $node->keyVar->getStartFilePos() : null,
                    'end_file_pos' => $node->keyVar->getEndFilePos() >= 0 ? $node->keyVar->getEndFilePos() : null,
                    'rhs_start_file_pos' => $node->expr->getStartFilePos() >= 0 ? $node->expr->getStartFilePos() : null,
                    'rhs_end_file_pos' => $node->expr->getEndFilePos() >= 0 ? $node->expr->getEndFilePos() : null,
                ];
            }
        }

        return $records;
    }

    /** @return array<string, mixed>|null */
    public static function catch(Catch_ $node, Scope $scope): ?array
    {
        if ($node->var === null || !is_string($node->var->name)) {
            return null;
        }
        $variable = '$' . $node->var->name;
        $type = $scope->getType($node->var);

        return [
            'record_type' => 'local_binding',
            'owner_id' => self::callerId($scope),
            'variable' => $variable,
            'file' => $scope->getFile(),
            'line_start' => $node->var->getStartLine(),
            'line_end' => $node->var->getEndLine(),
            'resolved_type' => TypeProjector::describe($type),
            'expression_kind' => 'catch',
            'literal_value' => null,
            'start_file_pos' => $node->var->getStartFilePos() >= 0 ? $node->var->getStartFilePos() : null,
            'end_file_pos' => $node->var->getEndFilePos() >= 0 ? $node->var->getEndFilePos() : null,
            'rhs_start_file_pos' => null,
            'rhs_end_file_pos' => null,
        ];
    }

    /** @return array<string, mixed> */
    public static function return(Return_ $node, Scope $scope): array
    {
        $exprType = $node->expr !== null ? TypeProjector::describe($scope->getType($node->expr)) : 'void';
        $literal = $node->expr !== null ? self::extractLiteral($node->expr) : null;
        $var = $node->expr !== null ? self::extractVariableName($node->expr) : null;
        $exprStart = $node->expr !== null && $node->expr->getStartFilePos() >= 0 ? $node->expr->getStartFilePos() : null;
        $exprEnd = $node->expr !== null && $node->expr->getEndFilePos() >= 0 ? $node->expr->getEndFilePos() : null;

        return [
            'record_type' => 'local_exit',
            'owner_id' => self::callerId($scope),
            'kind' => 'return',
            'file' => $scope->getFile(),
            'line_start' => $node->getStartLine(),
            'line_end' => $node->getEndLine(),
            'expression_type' => $exprType,
            'literal_value' => $literal,
            'variable' => $var,
            'start_file_pos' => $node->getStartFilePos() >= 0 ? $node->getStartFilePos() : null,
            'end_file_pos' => $node->getEndFilePos() >= 0 ? $node->getEndFilePos() : null,
            'expr_start_file_pos' => $exprStart,
            'expr_end_file_pos' => $exprEnd,
        ];
    }

    /** @return array<string, mixed> */
    public static function throw(Throw_ $node, Scope $scope): array
    {
        $exprType = TypeProjector::describe($scope->getType($node->expr));
        $literal = self::extractLiteral($node->expr);
        $var = self::extractVariableName($node->expr);
        $exprStart = $node->expr->getStartFilePos() >= 0 ? $node->expr->getStartFilePos() : null;
        $exprEnd = $node->expr->getEndFilePos() >= 0 ? $node->expr->getEndFilePos() : null;

        return [
            'record_type' => 'local_exit',
            'owner_id' => self::callerId($scope),
            'kind' => 'throw',
            'file' => $scope->getFile(),
            'line_start' => $node->getStartLine(),
            'line_end' => $node->getEndLine(),
            'expression_type' => $exprType,
            'literal_value' => $literal,
            'variable' => $var,
            'start_file_pos' => $node->getStartFilePos() >= 0 ? $node->getStartFilePos() : null,
            'end_file_pos' => $node->getEndFilePos() >= 0 ? $node->getEndFilePos() : null,
            'expr_start_file_pos' => $exprStart,
            'expr_end_file_pos' => $exprEnd,
        ];
    }
}
