<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InFunctionNode;
use PHPStan\Reflection\ParametersAcceptorSelector;
use voku\AgentMap\PhpStan\Projection;
use voku\AgentMap\PhpStan\TypeProjector;

/**
 * @implements Collector<InFunctionNode, array<string, mixed>>
 */
final readonly class FunctionCollector implements Collector
{
    public function getNodeType(): string
    {
        return InFunctionNode::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        $function = $node->getFunctionReflection();
        $variant = ParametersAcceptorSelector::combineAcceptors($function->getVariants());
        $referenced = TypeProjector::referencedClasses($variant->getReturnType());
        foreach ($variant->getParameters() as $parameter) {
            $referenced = [...$referenced, ...TypeProjector::referencedClasses($parameter->getType())];
        }
        $referenced = array_values(array_unique($referenced));
        sort($referenced, SORT_STRING);

        $nativeReturn = TypeProjector::describe($variant->getNativeReturnType());
        $phpDocReturn = TypeProjector::describe($variant->getPhpDocReturnType());
        if ($phpDocReturn === 'mixed' && $nativeReturn !== 'mixed') {
            $phpDocReturn = null;
        }

        return [
            'record_type' => 'function',
            'name' => ltrim($function->getName(), '\\'),
            'file' => $scope->getFile(),
            'line_start' => $node->getOriginalNode()->getStartLine(),
            'line_end' => $node->getOriginalNode()->getEndLine(),
            'parameters' => Projection::parameters($variant),
            'native_return_type' => $nativeReturn,
            'phpdoc_return_type' => $phpDocReturn,
            'resolved_return_type' => TypeProjector::describe($variant->getReturnType()),
            'referenced_classes' => $referenced,
        ];
    }
}
