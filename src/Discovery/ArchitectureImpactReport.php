<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class ArchitectureImpactReport
{
    /**
     * @param list<string> $targetArchitecturePath Finest region first.
     * @param list<ImpactRegionBucket> $regionBuckets
     */
    public function __construct(
        public ImpactReport $impact,
        public array $targetArchitecturePath,
        public array $regionBuckets,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->impact->toArray(),
            'target_architecture_path' => $this->targetArchitecturePath,
            'region_buckets' => array_map(
                static fn (ImpactRegionBucket $bucket): array => $bucket->toArray(),
                $this->regionBuckets,
            ),
        ];
    }
}
