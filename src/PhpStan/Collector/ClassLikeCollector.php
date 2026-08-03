<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClassNode;
use voku\AgentMap\PhpStan\TypeProjector;

/**
 * @implements Collector<InClassNode, array<string, mixed>>
 */
final readonly class ClassLikeCollector implements Collector
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $kind = $class->isInterface() ? 'interface' : ($class->isTrait() ? 'trait' : ($class->isEnum() ? 'enum' : 'class'));

        $parents = [];
        $parent = $class->getParentClass();
        if ($parent !== null) {
            $parents[$parent->getName()] = $parent->getDisplayName();
        }
        if ($class->isInterface()) {
            foreach ($class->getImmediateInterfaces() as $interface) {
                $parents[$interface->getName()] = $interface->getDisplayName();
            }
        }

        $interfaces = [];
        if (!$class->isInterface()) {
            foreach ($class->getImmediateInterfaces() as $interface) {
                $interfaces[$interface->getName()] = $interface->getDisplayName();
            }
        }

        $traitNames = [];
        foreach ($class->getTraits() as $trait) {
            $traitNames[] = $trait->getName();
        }

        $templates = [];
        foreach ($class->getTemplateTypeMap()->getTypes() as $name => $type) {
            $templates[$name] = TypeProjector::describe($type);
        }
        ksort($templates, SORT_STRING);
        ksort($parents, SORT_STRING);
        ksort($interfaces, SORT_STRING);
        sort($traitNames, SORT_STRING);

        return [
            'record_type' => 'symbol',
            'kind' => $kind,
            'name' => $class->getName(),
            'file' => $scope->getFile(),
            'line_start' => $node->getOriginalNode()->getStartLine(),
            'line_end' => $node->getOriginalNode()->getEndLine(),
            'extends' => array_values($parents),
            'extends_names' => array_keys($parents),
            'implements' => array_values($interfaces),
            'implements_names' => array_keys($interfaces),
            'uses' => $traitNames,
            'templates' => $templates,
        ];
    }
}
