<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Stmt\Catch_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;

/**
 * @implements Collector<Catch_, array<string, mixed>>
 */
final readonly class CatchCollector implements Collector
{
    public function getNodeType(): string
    {
        return Catch_::class;
    }

    /** @return array<string, mixed>|null */
    public function processNode(Node $node, Scope $scope): ?array
    {
        return Projection::catch($node, $scope);
    }
}
