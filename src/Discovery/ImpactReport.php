<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class ImpactReport
{
    /** @param list<ImpactNode> $impacts */
    public function __construct(
        public GraphNode $target,
        public array $impacts,
        public int $maximumDepth,
        public int $maximumNodes,
        public bool $truncated,
        public string $mapDigest,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'target' => $this->target->toArray(),
            'maximum_depth' => $this->maximumDepth,
            'maximum_nodes' => $this->maximumNodes,
            'truncated' => $this->truncated,
            'map_digest' => $this->mapDigest,
            'impacts' => array_map(
                static fn (ImpactNode $impact): array => $impact->toArray(),
                $this->impacts,
            ),
        ];
    }
}
