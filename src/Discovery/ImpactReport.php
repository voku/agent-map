<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class ImpactReport
{
    /**
     * @param list<ImpactNode> $impacts
     * @param list<string> $targetArchitecturePath Finest region first.
     * @param list<ImpactRegionBucket> $regionBuckets
     */
    public function __construct(
        public GraphNode $target,
        public array $impacts,
        public int $maximumDepth,
        public int $maximumNodes,
        public bool $truncated,
        public string $mapDigest,
        public array $targetArchitecturePath = [],
        public array $regionBuckets = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'target' => $this->target->toArray(),
            'target_architecture_path' => $this->targetArchitecturePath,
            'maximum_depth' => $this->maximumDepth,
            'maximum_nodes' => $this->maximumNodes,
            'truncated' => $this->truncated,
            'map_digest' => $this->mapDigest,
            'region_buckets' => array_map(
                static fn (ImpactRegionBucket $bucket): array => $bucket->toArray(),
                $this->regionBuckets,
            ),
            'impacts' => array_map(
                static fn (ImpactNode $impact): array => $impact->toArray(),
                $this->impacts,
            ),
        ];
    }
}
