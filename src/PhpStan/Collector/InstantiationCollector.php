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
        $targets = array_values(array_unique($targets));
        sort($targets, SORT_STRING);

        return [
            'record_type' => 'relation',
            'kind' => 'instantiates',
            'source_id' => Projection::callerId($scope),
            'target_ids' => $targets,
            'file' => $scope->getFile(),
            'line_start' => $node->getStartLine(),
            'line_end' => $node->getEndLine(),
            'resolution' => $targets === [] ? 'dynamic' : (count($targets) === 1 ? 'phpstan_resolved' : 'multiple_targets'),
            'receiver_type' => null,
            'result_type' => TypeProjector::describe($type),
        ];
    }
}
