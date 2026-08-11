<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class CoChangeReport
{
    /** @param list<CoChangePair> $pairs */
    public function __construct(
        public int $commitsAnalyzed,
        public int $bulkCommitsSkipped,
        public array $pairs,
    ) {
    }

    /**
     * @return array{
     *     commits_analyzed: int,
     *     bulk_commits_skipped: int,
     *     pair_count: int,
     *     pairs: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'commits_analyzed' => $this->commitsAnalyzed,
            'bulk_commits_skipped' => $this->bulkCommitsSkipped,
            'pair_count' => count($this->pairs),
            'pairs' => array_map(static fn (CoChangePair $pair): array => $pair->toArray(), $this->pairs),
        ];
    }
}
