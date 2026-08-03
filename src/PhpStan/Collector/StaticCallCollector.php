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

        return Projection::relation(
            kind: 'calls',
            scope: $scope,
            node: $node,
            targetIds: $targets,
            receiverType: $receiverType,
            resultType: TypeProjector::describe($scope->getType($node)),
        );
    }
}
