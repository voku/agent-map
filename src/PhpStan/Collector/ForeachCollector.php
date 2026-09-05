<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Stmt\Foreach_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;

/**
 * @implements Collector<Foreach_, list<array<string, mixed>>>
 */
final readonly class ForeachCollector implements Collector
{
    public function getNodeType(): string
    {
        return Foreach_::class;
    }

    /** @return list<array<string, mixed>> */
    public function processNode(Node $node, Scope $scope): array
    {
        return Projection::foreach($node, $scope);
    }
}
