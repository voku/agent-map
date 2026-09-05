<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\AssignOp;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;

/**
 * @implements Collector<AssignOp, array<string, mixed>>
 */
final readonly class AssignOpCollector implements Collector
{
    public function getNodeType(): string
    {
        return AssignOp::class;
    }

    /** @return array<string, mixed>|null */
    public function processNode(Node $node, Scope $scope): ?array
    {
        return Projection::assign($node, $scope);
    }
}
