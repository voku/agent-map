<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\Throw_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;

/**
 * @implements Collector<Throw_, array<string, mixed>>
 */
final readonly class ThrowCollector implements Collector
{
    public function getNodeType(): string
    {
        return Throw_::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        return Projection::throw($node, $scope);
    }
}
