<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassMethodNode;
use voku\AgentMap\PhpStan\Projection;
use voku\AgentMap\PhpStan\TypeProjector;

/**
 * @implements Collector<InClassMethodNode, array<string, mixed>>
 */
final readonly class ClassMethodCollector implements Collector
{
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getMethodReflection();
        $nativeReturn = TypeProjector::describe($method->getNativeReturnType());
        $phpDocReturn = TypeProjector::describe($method->getPhpDocReturnType());
        $resolvedReturn = TypeProjector::describe($method->getReturnType());
        if ($phpDocReturn === 'mixed' && $nativeReturn !== 'mixed') {
            $phpDocReturn = null;
        }
        $referenced = [
            ...TypeProjector::referencedClasses($method->getNativeReturnType()),
            ...TypeProjector::referencedClasses($method->getPhpDocReturnType()),
            ...TypeProjector::referencedClasses($method->getReturnType()),
        ];
        foreach ($method->getParameters() as $parameter) {
            $referenced = [...$referenced, ...TypeProjector::referencedClasses($parameter->getType())];
        }
        $referenced = array_values(array_unique($referenced));
        sort($referenced, SORT_STRING);

        $prototype = $method->getPrototype();
        $prototypeId = null;
        if ($prototype->getDeclaringClass()->getName() !== $method->getDeclaringClass()->getName()) {
            $prototypeId = 'method:' . $prototype->getDeclaringClass()->getName() . '::' . $method->getName();
        }

        return [
            'record_type' => 'method',
            'class' => $method->getDeclaringClass()->getName(),
            'name' => $method->getName(),
            'file' => $scope->getFile(),
            'line_start' => $node->getOriginalNode()->getStartLine(),
            'line_end' => $node->getOriginalNode()->getEndLine(),
            'visibility' => $method->isPrivate() ? 'private' : ($method->isPublic() ? 'public' : 'protected'),
            'static' => $method->isStatic(),
            'abstract' => self::yes($method->isAbstract()),
            'final' => self::yes($method->isFinal()),
            'parameters' => Projection::parameters($method),
            'native_return_type' => $nativeReturn,
            'phpdoc_return_type' => $phpDocReturn,
            'resolved_return_type' => $resolvedReturn,
            'referenced_classes' => $referenced,
            'prototype_id' => $prototypeId,
        ];
    }
    private static function yes(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_object($value) && method_exists($value, 'yes') && $value->yes();
    }
}
