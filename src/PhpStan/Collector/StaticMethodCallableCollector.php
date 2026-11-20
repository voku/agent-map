<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\StaticMethodCallableNode;

/**
 * First-class callable syntax `Class::method(...)`. PHPStan replaces it with a StaticMethodCallableNode, so the static
 * call collector never sees it; projecting the original call keeps a resolved target as rename and caller evidence.
 *
 * @implements Collector<StaticMethodCallableNode, array<string, mixed>>
 */
final readonly class StaticMethodCallableCollector implements Collector
{
    public function getNodeType(): string
    {
        return StaticMethodCallableNode::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        return (new StaticCallCollector())->processNode($node->getOriginalNode(), $scope);
    }
}
