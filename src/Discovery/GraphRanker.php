<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use InvalidArgumentException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;

final readonly class GraphRanker
{
    /**
     * Rank repository nodes by unique one-hop graph neighbours.
     *
     * Repeated call sites between the same two nodes count once. Dynamic and
     * multiple-target relations stay visible as uncertainty instead of being
     * silently promoted to precise facts.
     *
     * @return list<RankedNode>
     */
    public function rank(
        AgentMapIndex $map,
        GraphMetric $metric = GraphMetric::DEPENDENTS,
        ?string $kind = null,
        int $limit = 10,
    ): array {
        if ($limit < 1) {
            throw new InvalidArgumentException('Graph rank limit must be positive.');
        }

        $catalog = new GraphNodeCatalog($map);
        $adjacency = new GraphAdjacency($map);
        $ranked = [];
        foreach ($catalog->all($kind) as $node) {
            $neighbours = [];
            $uncertainRelations = [];
            $relations = $metric->incoming() ? $adjacency->incoming($node->id) : $adjacency->outgoing($node->id);
            foreach ($relations as $relation) {
                if (!$metric->acceptsRelation($relation->kind)) {
                    continue;
                }

                foreach ($this->neighbourIds($relation, $metric->incoming()) as $neighbourId) {
                    if (!str_starts_with($neighbourId, 'unresolved:')) {
                        $neighbours[$neighbourId] = true;
                    }
                }
                if ($this->isUncertain($relation)) {
                    $uncertainRelations[$relation->id] = true;
                }
            }

            $ranked[] = new RankedNode(
                node: $node,
                score: count($neighbours),
                uncertainRelations: count($uncertainRelations),
            );
        }

        usort($ranked, static function (RankedNode $left, RankedNode $right): int {
            return $right->score <=> $left->score
                ?: $left->uncertainRelations <=> $right->uncertainRelations
                ?: $left->node->id <=> $right->node->id;
        });

        return array_slice($ranked, 0, $limit);
    }

    /** @return list<string> */
    private function neighbourIds(RelationEntry $relation, bool $incoming): array
    {
        if ($incoming) {
            return [$relation->sourceId];
        }

        return $relation->targetIds;
    }

    private function isUncertain(RelationEntry $relation): bool
    {
        return in_array($relation->resolution, ['dynamic', 'multiple_targets'], true);
    }
}
