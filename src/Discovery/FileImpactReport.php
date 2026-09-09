<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

/**
 * What can notice a change to one indexed file.
 *
 * A method impact has one target node. A file does not: it is whatever
 * declarations it happens to contain, so the answer is seeded from all of them
 * at once and the bound applies to the union. Keeping the seeds visible matters
 * because an empty impact set means something different for a file with no
 * indexed declarations than for one whose declarations nothing depends on.
 */
final readonly class FileImpactReport
{
    /**
     * @param list<GraphNode> $seeds declarations in the file the traversal started from
     * @param list<ImpactNode> $impacts nodes outside the file that can notice a change to it
     */
    public function __construct(
        public string $path,
        public array $seeds,
        public array $impacts,
        public int $maximumDepth,
        public int $maximumNodes,
        public bool $truncated,
        public string $mapDigest,
    ) {
    }

    /** @return list<string> the distinct files the impact set covers, in traversal order */
    public function impactedFiles(): array
    {
        $files = [];
        foreach ($this->impacts as $impact) {
            $files[$impact->node->file] = true;
        }

        return array_keys($files);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'seeds' => array_map(
                static fn (GraphNode $seed): array => $seed->toArray(),
                $this->seeds,
            ),
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
