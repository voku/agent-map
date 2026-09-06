<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentMap\Index\RelationEntry;

final readonly class GraphRelationFacts
{
    public static function isUncertain(RelationEntry|GraphRelation $relation): bool
    {
        if ($relation instanceof RelationEntry) {
            return in_array($relation->resolution, ['dynamic', 'multiple_targets'], true);
        }

        if (count($relation->targetIds) > 1) {
            return true;
        }

        foreach ($relation->targetIds as $targetId) {
            if (str_starts_with($targetId, 'unresolved:')) {
                return true;
            }
        }

        return false;
    }
}
