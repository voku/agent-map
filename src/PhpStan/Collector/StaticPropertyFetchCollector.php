<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;

/** @implements Collector<StaticPropertyFetch, array<string, mixed>> */
final readonly class StaticPropertyFetchCollector implements Collector
{
    public function getNodeType(): string
    {
        return StaticPropertyFetch::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        return Projection::staticPropertyAccess($node, $scope);
    }
}
