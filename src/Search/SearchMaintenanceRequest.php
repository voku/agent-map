<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\MapArtifactPaths;

/**
 * The bounded owner input for reconciling an optional Search projection.
 *
 * The supplied map is the already-prepared source snapshot. Search does not
 * choose a repository scope or rebuild Map; it only reconciles its own derived
 * state against this verified owner artifact.
 */
final readonly class SearchMaintenanceRequest
{
    public function __construct(
        public AgentMapIndex $index,
        public MapArtifactPaths $artifacts,
    ) {
    }
}
