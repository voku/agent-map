<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\MethodCallableNode;
use voku\AgentMap\PhpStan\Projection;

/**
 * First-class callable syntax `$object->method(...)`. PHPStan replaces it with a MethodCallableNode, so the call
 * collector never sees it; projecting the original call keeps a resolved receiver as rename and caller evidence.
 *
 * @implements Collector<MethodCallableNode, array<string, mixed>>
 */
final readonly class MethodCallableCollector implements Collector
{
    public function getNodeType(): string
    {
        return MethodCallableNode::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        return Projection::methodCall($node->getOriginalNode(), $scope);
    }
}
