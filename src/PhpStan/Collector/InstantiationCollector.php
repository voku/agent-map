<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;
use voku\AgentMap\PhpStan\TypeProjector;

/**
 * @implements Collector<New_, array<string, mixed>>
 */
final readonly class InstantiationCollector implements Collector
{
    public function getNodeType(): string
    {
        return New_::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        $type = $scope->getType($node);
        $targets = [];
        foreach ($type->getObjectClassNames() as $className) {
            $targets[] = 'class:' . $className;
        }

        return Projection::relation(
            kind: 'instantiates',
            scope: $scope,
            node: $node,
            targetIds: $targets,
            receiverType: null,
            resultType: TypeProjector::describe($type),
        );
    }
}
