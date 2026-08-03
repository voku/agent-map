<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
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
}
