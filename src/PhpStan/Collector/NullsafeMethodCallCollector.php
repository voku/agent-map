<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;

/**
 * @implements Collector<NullsafeMethodCall, array<string, mixed>>
 */
final readonly class NullsafeMethodCallCollector implements Collector
{
    public function getNodeType(): string
    {
        return NullsafeMethodCall::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        return Projection::methodCall($node, $scope);
    }
}
