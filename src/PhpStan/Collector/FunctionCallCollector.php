<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use voku\AgentMap\PhpStan\Projection;
use voku\AgentMap\PhpStan\TypeProjector;

/**
 * @implements Collector<FuncCall, array<string, mixed>>
 */
final readonly class FunctionCallCollector implements Collector
{
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        $targets = [];
        if ($node->name instanceof Node\Name) {
            $resolvedName = $this->reflectionProvider->resolveFunctionName($node->name, $scope);
            if ($resolvedName !== null) {
                $targets[] = 'function:' . ltrim($resolvedName, '\\');
            }
        }

        return Projection::relation(
            kind: 'calls',
            scope: $scope,
            node: $node,
            targetIds: $targets,
            receiverType: null,
            resultType: TypeProjector::describe($scope->getType($node)),
        );
    }
}
