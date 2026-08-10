<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use InvalidArgumentException;
use RuntimeException;
use SplQueue;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;

final readonly class ImpactAnalyzer
{
    /**
     * Find repository nodes that can depend on a changed method.
     *
     * Traversal follows dependency edges in reverse. It is deliberately bounded,
     * cycle-safe and evidence preserving. Dynamic or multiple-target relations are
     * not discarded; affected nodes reached through them are marked uncertain.
     */
    public function forMethod(
        AgentMapIndex $map,
        string $target,
        int $maximumDepth = 2,
        int $maximumNodes = 100,
    ): ImpactReport {
        if ($maximumDepth < 1) {
            throw new InvalidArgumentException('Impact depth must be at least 1.');
        }
        if ($maximumNodes < 1) {
            throw new InvalidArgumentException('Impact node limit must be positive.');
        }

        $resolved = $map->resolveMethod($target);

        return $this->fromNodeId($map, $resolved->id, $maximumDepth, $maximumNodes);
    }

    public function fromNodeId(
        AgentMapIndex $map,
        string $targetId,
        int $maximumDepth = 2,
        int $maximumNodes = 100,
    ): ImpactReport {
        if ($maximumDepth < 1) {
            throw new InvalidArgumentException('Impact depth must be at least 1.');
        }
        if ($maximumNodes < 1) {
            throw new InvalidArgumentException('Impact node limit must be positive.');
        }

        $catalog = new GraphNodeCatalog($map);
        $target = $catalog->find($targetId);
        if ($target === null) {
            throw new RuntimeException('Impact target is not an indexed repository node: ' . $targetId);
        }

        /** @var SplQueue<array{id: string, depth: int}> $queue */
        $queue = new SplQueue();
        $queue->enqueue(['id' => $targetId, 'depth' => 0]);
        $expanded = [$targetId => true];
        /** @var array<string, array{node: GraphNode, depth: int, relation_kinds: array<string, true>, evidence_ids: array<string, true>, uncertain: bool}> $found */
        $found = [];
        $truncated = false;

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            if ($current['depth'] >= $maximumDepth) {
                continue;
            }

            foreach ($map->incoming($current['id']) as $relation) {
                if (!$this->canPropagateImpact($relation)) {
                    continue;
                }

                $node = $catalog->find($relation->sourceId);
                if ($node === null || $node->id === $targetId) {
                    continue;
                }

                $depth = $current['depth'] + 1;
                if (!isset($found[$node->id])) {
                    if (count($found) >= $maximumNodes) {
                        $truncated = true;
                        continue;
                    }
                    $found[$node->id] = [
                        'node' => $node,
                        'depth' => $depth,
                        'relation_kinds' => [],
                        'evidence_ids' => [],
                        'uncertain' => false,
                    ];
                }

                $found[$node->id]['depth'] = min($found[$node->id]['depth'], $depth);
                $found[$node->id]['relation_kinds'][$relation->kind] = true;
                $found[$node->id]['evidence_ids'][$relation->id] = true;
                $found[$node->id]['uncertain'] = $found[$node->id]['uncertain'] || $this->isUncertain($relation);

                if (!isset($expanded[$node->id]) && $depth < $maximumDepth) {
                    $expanded[$node->id] = true;
                    $queue->enqueue(['id' => $node->id, 'depth' => $depth]);
                }
            }
        }

        $impacts = [];
        foreach ($found as $entry) {
            $relationKinds = array_keys($entry['relation_kinds']);
            $evidenceIds = array_keys($entry['evidence_ids']);
            sort($relationKinds, SORT_STRING);
            sort($evidenceIds, SORT_STRING);
            /** @var non-empty-list<string> $relationKinds */
            /** @var non-empty-list<string> $evidenceIds */
            $impacts[] = new ImpactNode(
                node: $entry['node'],
                depth: $entry['depth'],
                relationKinds: $relationKinds,
                evidenceIds: $evidenceIds,
                uncertain: $entry['uncertain'],
            );
        }

        usort($impacts, static function (ImpactNode $left, ImpactNode $right): int {
            return $left->depth <=> $right->depth
                ?: $left->uncertain <=> $right->uncertain
                ?: $left->node->id <=> $right->node->id;
        });

        return new ImpactReport(
            target: $target,
            impacts: $impacts,
            maximumDepth: $maximumDepth,
            maximumNodes: $maximumNodes,
            truncated: $truncated,
            mapDigest: $map->mapDigest(),
        );
    }

    private function canPropagateImpact(RelationEntry $relation): bool
    {
        return in_array($relation->kind, [
            'calls',
            'extends',
            'implements',
            'instantiates',
            'overrides',
            'references_type',
            'uses_trait',
        ], true);
    }

    private function isUncertain(RelationEntry $relation): bool
    {
        return in_array($relation->resolution, ['dynamic', 'multiple_targets'], true);
    }
}
