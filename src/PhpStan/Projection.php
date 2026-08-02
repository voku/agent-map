<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan;

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
