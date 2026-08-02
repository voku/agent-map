<?php

declare(strict_types=1);

namespace voku\AgentMap\PhpStan\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use voku\AgentMap\PhpStan\Projection;
use voku\AgentMap\PhpStan\TypeProjector;

/**
 * @implements Collector<FuncCall, array<string, mixed>>
 */
final readonly class FunctionCallCollector implements Collector
{
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /** @return array<string, mixed> */
    public function processNode(Node $node, Scope $scope): array
    {
        $targets = [];
        if ($node->name instanceof Node\Name) {
            $targets[] = 'function:' . ltrim($scope->resolveName($node->name), '\\');
        }

        return [
            'record_type' => 'relation',
            'kind' => 'calls',
            'source_id' => Projection::callerId($scope),
            'target_ids' => $targets,
            'file' => $scope->getFile(),
            'line_start' => $node->getStartLine(),
            'line_end' => $node->getEndLine(),
            'resolution' => $targets === [] ? 'dynamic' : 'phpstan_resolved',
            'receiver_type' => null,
            'result_type' => TypeProjector::describe($scope->getType($node)),
        ];
    }
}
