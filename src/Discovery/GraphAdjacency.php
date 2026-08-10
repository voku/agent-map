<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;

final readonly class GraphAdjacency
{
    /** @var array<string, list<RelationEntry>> */
    private array $incoming;

    /** @var array<string, list<RelationEntry>> */
    private array $outgoing;

    public function __construct(AgentMapIndex $map)
    {
        $incoming = [];
        $outgoing = [];
        foreach ($map->relations as $relation) {
            $outgoing[$relation->sourceId][] = $relation;
            foreach ($relation->targetIds as $targetId) {
                $incoming[$targetId][] = $relation;
            }
        }

        $this->incoming = $incoming;
        $this->outgoing = $outgoing;
    }

    /** @return list<RelationEntry> */
    public function incoming(string $nodeId): array
    {
        return $this->incoming[$nodeId] ?? [];
    }

    /** @return list<RelationEntry> */
    public function outgoing(string $nodeId): array
    {
        return $this->outgoing[$nodeId] ?? [];
    }
}
