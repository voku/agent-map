<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;

/**
 * @implements Collector<Return_, array<string, mixed>>
 */
final readonly class ReturnCollector implements Collector
{
    public function getNodeType(): string
    {
        return Return_::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        return Projection::return($node, $scope);
    }
}
