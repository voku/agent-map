<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use voku\AgentGraph\Graph\GraphProjection;
use voku\AgentGraph\Graph\GraphRelation;

final readonly class GraphProjectionFactory
{
    /** @return iterable<GraphRelation> */
    public function relations(AgentMapIndex $map): iterable
    {
        foreach ($map->relations as $relation) {
            yield new GraphRelation(
                $relation->id,
                $relation->sourceId,
                $relation->kind,
                $relation->targetIds,
            );
        }
    }

    public function fromIndex(AgentMapIndex $map): GraphProjection
    {
        return new GraphProjection(iterator_to_array($this->relations($map), false));
    }
}
