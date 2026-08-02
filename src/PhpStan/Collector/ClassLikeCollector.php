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
        $parentNames = [];
        $displayExtends = [];
        $parent = $class->getParentClass();
        if ($parent !== null) {
            $parentNames[] = $parent->getName();
            $displayExtends[] = $parent->getDisplayName();
        }
        if ($class->isInterface()) {
            foreach ($class->getImmediateInterfaces() as $interface) {
                $parentNames[] = $interface->getName();
                $displayExtends[] = $interface->getDisplayName();
            }
        }

        $interfaceNames = [];
        $displayImplements = [];
        if (!$class->isInterface()) {
            foreach ($class->getImmediateInterfaces() as $interface) {
                $interfaceNames[] = $interface->getName();
                $displayImplements[] = $interface->getDisplayName();
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
        sort($parentNames, SORT_STRING);
        sort($displayExtends, SORT_STRING);
        sort($interfaceNames, SORT_STRING);
        sort($displayImplements, SORT_STRING);
        sort($traitNames, SORT_STRING);

        return [
            'record_type' => 'symbol',
            'kind' => $kind,
            'name' => $class->getName(),
            'file' => $scope->getFile(),
            'line_start' => $node->getOriginalNode()->getStartLine(),
            'line_end' => $node->getOriginalNode()->getEndLine(),
            'extends' => $displayExtends,
            'extends_names' => $parentNames,
            'implements' => $displayImplements,
            'implements_names' => $interfaceNames,
            'uses' => $traitNames,
            'templates' => $templates,
        ];
    }
}
