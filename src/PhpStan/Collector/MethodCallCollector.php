<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;
use voku\AgentMap\PhpStan\TypeProjector;

/**
 * @implements Collector<MethodCall, array<string, mixed>>
 */
final readonly class MethodCallCollector implements Collector
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
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
        $targets = array_values(array_unique($targets));
        sort($targets, SORT_STRING);

        return [
            'record_type' => 'relation',
            'kind' => 'calls',
            'source_id' => Projection::callerId($scope),
            'target_ids' => $targets,
            'file' => $scope->getFile(),
            'line_start' => $node->getStartLine(),
            'line_end' => $node->getEndLine(),
            'resolution' => $targets === [] ? 'dynamic' : (count($targets) === 1 ? 'phpstan_resolved' : 'multiple_targets'),
            'receiver_type' => TypeProjector::describe($receiverType),
            'result_type' => TypeProjector::describe($scope->getType($node)),
        ];
    }
}
