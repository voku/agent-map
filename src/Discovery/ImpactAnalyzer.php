<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use InvalidArgumentException;
use RuntimeException;
use SplQueue;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Sqlite\GraphStore;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;

final readonly class ImpactAnalyzer
{
    /**
     * Find repository nodes that can depend on a changed method.
     *
     * Traversal follows dependency edges in reverse. It is deliberately bounded,
     * cycle-safe and evidence preserving. Dynamic or multiple-target relations are
     * not discarded; uncertainty propagates through the whole path until a certain
     * path to the same node proves the impact independently.
     */
    public function forMethod(
        AgentMapIndex $map,
        string $target,
        int $maximumDepth = 2,
        int $maximumNodes = 100,
    ): ImpactReport {
        $resolved = $map->resolveMethod($target);

        return $this->analyze(
            $map,
            $resolved->id,
            $maximumDepth,
            $maximumNodes,
            null,
            $map->mapDigest(),
        );
    }

    public function forMethodUsingGraph(
        AgentMapIndex $map,
        GraphStore $graph,
        string $mapDigest,
        string $target,
        int $maximumDepth = 2,
        int $maximumNodes = 100,
    ): ImpactReport {
        $resolved = $map->resolveMethod($target);

        return $this->analyze($map, $resolved->id, $maximumDepth, $maximumNodes, $graph, $mapDigest);
    }

    public function fromNodeId(
        AgentMapIndex $map,
        string $targetId,
        int $maximumDepth = 2,
        int $maximumNodes = 100,
    ): ImpactReport {
        return $this->analyze(
            $map,
            $targetId,
            $maximumDepth,
            $maximumNodes,
            null,
            $map->mapDigest(),
        );
    }

    /**
     * Find repository nodes outside one indexed file that can depend on it.
     *
     * A caller asking about a file - a Contract scope entry, a changed path -
     * has no single node to start from, and picking one declaration out of the
     * file would answer a narrower question than the one asked. Every
     * declaration the file contains seeds the same traversal instead, and the
     * node bound applies to their union so the result stays as bounded as a
     * method impact.
     *
     * Nodes declared in the file itself are not impacts. Changing a file is not
     * something that file notices; the question is what outside it does.
     */
    public function forFile(
        AgentMapIndex $map,
        string $path,
        int $maximumDepth = 2,
        int $maximumNodes = 100,
    ): FileImpactReport {
        $this->assertBounds($maximumDepth, $maximumNodes);

        $file = $map->file($path);
        if ($file === null) {
            throw new RuntimeException('Impact target is not an indexed repository file: ' . $path);
        }

        $catalog = new GraphNodeCatalog($map);
        $seeds = [];
        $declared = [];
        foreach ($catalog->all() as $node) {
            if ($node->file !== $file->path) {
                continue;
            }
            $seeds[] = $node;
            $declared[$node->id] = true;
        }

        $traversal = $this->traverse(
            $map,
            $catalog,
            array_keys($declared),
            $declared,
            $maximumDepth,
            $maximumNodes,
            null,
        );

        return new FileImpactReport(
            path: $file->path,
            seeds: $seeds,
            impacts: $traversal['impacts'],
            maximumDepth: $maximumDepth,
            maximumNodes: $maximumNodes,
            truncated: $traversal['truncated'],
            mapDigest: $map->mapDigest(),
        );
    }

    private function analyze(
        AgentMapIndex $map,
        string $targetId,
        int $maximumDepth,
        int $maximumNodes,
        ?GraphStore $graph,
        string $mapDigest,
    ): ImpactReport {
        $this->assertBounds($maximumDepth, $maximumNodes);

        $catalog = new GraphNodeCatalog($map);
        $target = $catalog->find($targetId);
        if ($target === null) {
            throw new RuntimeException('Impact target is not an indexed repository node: ' . $targetId);
        }

        $traversal = $this->traverse(
            $map,
            $catalog,
            [$targetId],
            [$targetId => true],
            $maximumDepth,
            $maximumNodes,
            $graph,
        );

        return new ImpactReport(
            target: $target,
            impacts: $traversal['impacts'],
            maximumDepth: $maximumDepth,
            maximumNodes: $maximumNodes,
            truncated: $traversal['truncated'],
            mapDigest: $mapDigest,
        );
    }

    /**
     * The reverse traversal both entry points share.
     *
     * @param list<string> $seedIds nodes the traversal starts from, all at depth 0
     * @param array<string, true> $excludedIds nodes that may never be reported as impacts
     * @return array{impacts: list<ImpactNode>, truncated: bool}
     */
    private function traverse(
        AgentMapIndex $map,
        GraphNodeCatalog $catalog,
        array $seedIds,
        array $excludedIds,
        int $maximumDepth,
        int $maximumNodes,
        ?GraphStore $graph,
    ): array {
        $adjacency = $graph === null ? new GraphAdjacency($map) : null;

        /** @var SplQueue<array{id: string, depth: int, uncertain: bool}> $queue */
        $queue = new SplQueue();
        $queuedStates = [];
        foreach ($seedIds as $seedId) {
            $queue->enqueue(['id' => $seedId, 'depth' => 0, 'uncertain' => false]);
            $queuedStates[$this->stateKey($seedId, false)] = true;
        }
        /**
         * @var array<string, array{
         *   node: GraphNode,
         *   depth: int,
         *   relation_kinds: array<string, true>,
         *   evidence_ids: array<string, true>,
         *   via_node_ids: array<string, true>,
         *   has_certain_path: bool,
         *   has_uncertain_path: bool
         * }> $found
         */
        $found = [];
        $truncated = false;

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            if ($current['depth'] >= $maximumDepth) {
                continue;
            }

            $relations = $graph === null
                ? $adjacency?->incoming($current['id']) ?? []
                : $graph->incoming($current['id']);
            foreach ($relations as $relation) {
                if (!$this->canPropagateImpact($relation)) {
                    continue;
                }

                $node = $catalog->find($relation->sourceId);
                if ($node === null || isset($excludedIds[$node->id])) {
                    continue;
                }

                $depth = $current['depth'] + 1;
                $pathUncertain = $current['uncertain'] || GraphRelationFacts::isUncertain($relation);
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
                        'via_node_ids' => [],
                        'has_certain_path' => false,
                        'has_uncertain_path' => false,
                    ];
                }

                $found[$node->id]['depth'] = min($found[$node->id]['depth'], $depth);
                $found[$node->id]['relation_kinds'][$relation->kind] = true;
                $found[$node->id]['evidence_ids'][$relation->id] = true;
                $found[$node->id]['via_node_ids'][$current['id']] = true;
                if ($pathUncertain) {
                    $found[$node->id]['has_uncertain_path'] = true;
                } else {
                    $found[$node->id]['has_certain_path'] = true;
                }

                $stateKey = $this->stateKey($node->id, $pathUncertain);
                if (!isset($queuedStates[$stateKey]) && $depth < $maximumDepth) {
                    $queuedStates[$stateKey] = true;
                    $queue->enqueue([
                        'id' => $node->id,
                        'depth' => $depth,
                        'uncertain' => $pathUncertain,
                    ]);
                }
            }
        }

        $impacts = [];
        foreach ($found as $entry) {
            $relationKinds = array_keys($entry['relation_kinds']);
            $evidenceIds = array_keys($entry['evidence_ids']);
            $viaNodeIds = array_keys($entry['via_node_ids']);
            sort($relationKinds, SORT_STRING);
            sort($evidenceIds, SORT_STRING);
            sort($viaNodeIds, SORT_STRING);
            /** @var non-empty-list<string> $relationKinds */
            /** @var non-empty-list<string> $evidenceIds */
            /** @var non-empty-list<string> $viaNodeIds */
            $impacts[] = new ImpactNode(
                node: $entry['node'],
                depth: $entry['depth'],
                relationKinds: $relationKinds,
                evidenceIds: $evidenceIds,
                viaNodeIds: $viaNodeIds,
                uncertain: !$entry['has_certain_path'],
            );
        }

        usort($impacts, static function (ImpactNode $left, ImpactNode $right): int {
            return $left->depth <=> $right->depth
                ?: $left->uncertain <=> $right->uncertain
                ?: $left->node->id <=> $right->node->id;
        });

        return ['impacts' => $impacts, 'truncated' => $truncated];
    }

    private function assertBounds(int $maximumDepth, int $maximumNodes): void
    {
        if ($maximumDepth < 1) {
            throw new InvalidArgumentException('Impact depth must be at least 1.');
        }
        if ($maximumNodes < 1) {
            throw new InvalidArgumentException('Impact node limit must be positive.');
        }
    }

    private function stateKey(string $nodeId, bool $uncertain): string
    {
        return $nodeId . "\0" . ($uncertain ? 'uncertain' : 'certain');
    }

    private function canPropagateImpact(RelationEntry|GraphRelation $relation): bool
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
}
