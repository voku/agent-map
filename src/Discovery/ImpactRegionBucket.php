<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class ImpactRegionBucket
{
    /**
     * @param list<string> $pathLabels Finest region first.
     * @param list<string> $nodeIds
     */
    public function __construct(
        public ?string $regionId,
        public string $label,
        public array $pathLabels,
        public array $nodeIds,
        public int $uncertainNodes,
        public int $maximumDepth,
    ) {
    }

    /** @return array{region_id: ?string, label: string, path: list<string>, node_ids: list<string>, node_count: int, uncertain_nodes: int, maximum_depth: int} */
    public function toArray(): array
    {
        return [
            'region_id' => $this->regionId,
            'label' => $this->label,
            'path' => $this->pathLabels,
            'node_ids' => $this->nodeIds,
            'node_count' => count($this->nodeIds),
            'uncertain_nodes' => $this->uncertainNodes,
            'maximum_depth' => $this->maximumDepth,
        ];
    }
}
