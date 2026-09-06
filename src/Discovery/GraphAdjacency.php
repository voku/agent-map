<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use RuntimeException;
use voku\AgentGraph\Graph\GraphAdjacency as SharedGraphAdjacency;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\GraphProjectionFactory;
use voku\AgentMap\Index\RelationEntry;

final readonly class GraphAdjacency
{
    private SharedGraphAdjacency $adjacency;

    /** @var array<string, RelationEntry> */
    private array $relationsById;

    public function __construct(AgentMapIndex $map)
    {
        $relationsById = [];
        foreach ($map->relations as $relation) {
            $relationsById[$relation->id] = $relation;
        }

        $this->relationsById = $relationsById;
        $this->adjacency = new SharedGraphAdjacency((new GraphProjectionFactory())->fromIndex($map));
    }

    /** @return list<RelationEntry> */
    public function incoming(string $nodeId): array
    {
        return $this->ownerRelations($this->adjacency->incoming($nodeId));
    }

    /** @return list<RelationEntry> */
    public function outgoing(string $nodeId): array
    {
        return $this->ownerRelations($this->adjacency->outgoing($nodeId));
    }

    /**
     * @param list<GraphRelation> $relations
     * @return list<RelationEntry>
     */
    private function ownerRelations(array $relations): array
    {
        $ownerRelations = [];
        foreach ($relations as $relation) {
            $owner = $this->relationsById[$relation->id] ?? null;
            if ($owner === null) {
                throw new RuntimeException('Projected graph relation is missing from agent-map owner state: ' . $relation->id);
            }
            $ownerRelations[] = $owner;
        }

        return $ownerRelations;
    }
}
