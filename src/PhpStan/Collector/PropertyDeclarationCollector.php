<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\ClassPropertiesNode;

/**
 * Publishes concrete property declaration identities without turning them into mutation instructions.
 *
 * @implements Collector<ClassPropertiesNode, array<string, mixed>|null>
 */
final readonly class PropertyDeclarationCollector implements Collector
{
    public function getNodeType(): string
    {
        return ClassPropertiesNode::class;
    }

    /** @return array<string, mixed>|null */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $className = $node->getClassReflection()->getName();
        $targets = [];
        foreach ($node->getProperties() as $property) {
            $targets[] = 'property:' . $className . '::$' . $property->getName();
        }
        if ($targets === []) {
            return null;
        }
        $targets = array_values(array_unique($targets));
        sort($targets, SORT_STRING);

        return [
            'record_type' => 'relation',
            'kind' => 'declares_property',
            'source_id' => 'type:' . $className,
            'target_ids' => $targets,
            'file' => $scope->getFile(),
            'line_start' => $node->getClass()->getStartLine(),
            'line_end' => $node->getClass()->getEndLine(),
            'resolution' => 'phpstan_resolved',
            'receiver_type' => $className,
            'result_type' => null,
        ];
    }
}
