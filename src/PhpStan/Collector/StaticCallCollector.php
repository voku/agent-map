<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;
use voku\AgentMap\PhpStan\TypeProjector;

/**
 * @implements Collector<StaticCall, array<string, mixed>>
 */
final readonly class StaticCallCollector implements Collector
{
    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        $targets = [];
        $receiverType = null;
        if ($node->class instanceof Node\Name) {
            $type = $scope->resolveTypeByName($node->class);
            $receiverType = TypeProjector::describe($type);
            if ($node->name instanceof Node\Identifier) {
                $methodName = $node->name->toString();
                foreach ($type->getObjectClassReflections() as $classReflection) {
                    if (!$classReflection->hasMethod($methodName)) {
                        continue;
                    }
                    $method = $classReflection->getMethod($methodName, $scope);
                    $targets[] = 'method:' . $method->getDeclaringClass()->getName() . '::' . $method->getName();
                }
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
            'receiver_type' => $receiverType,
            'result_type' => TypeProjector::describe($scope->getType($node)),
        ];
    }
}
