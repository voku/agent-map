<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;

/** @implements Collector<PropertyFetch, array<string, mixed>> */
final readonly class PropertyFetchCollector implements Collector
{
    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        return Projection::propertyAccess($node, $scope);
    }
}
