<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use voku\AgentGraph\Graph\GraphProjection;
use voku\AgentGraph\Graph\GraphRelation;

final readonly class GraphProjectionFactory
{
    public function fromIndex(AgentMapIndex $map): GraphProjection
    {
        $relations = [];
        foreach ($map->relations as $relation) {
            $relations[] = new GraphRelation(
                $relation->id,
                $relation->sourceId,
                $relation->kind,
                $relation->targetIds,
            );
        }

        return new GraphProjection($relations);
    }
}
