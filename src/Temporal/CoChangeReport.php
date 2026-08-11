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
     *     pairs: list<array{left: string, right: string, cochanges: int, left_changes: int, right_changes: int, jaccard: float, smaller_side_ratio: float, semantic_static_weight: float, path_static_weight: float, static_signals: array<string, float>}>
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
