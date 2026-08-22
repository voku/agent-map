<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;

/** @implements Collector<NullsafePropertyFetch, array<string, mixed>> */
final readonly class NullsafePropertyFetchCollector implements Collector
{
    public function getNodeType(): string
    {
        return NullsafePropertyFetch::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        return Projection::propertyAccess($node, $scope);
    }
}
