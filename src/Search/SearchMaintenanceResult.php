<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

/**
 * The explicit outcome of optional Search reconciliation.
 *
 * `absent` and `unavailable` are non-authoritative capability states. A host
 * may decide whether they matter for its use case; neither makes Map
 * preparation fail.
 */
final readonly class SearchMaintenanceResult
{
    /**
     * @param 'absent'|'current'|'failed'|'refreshed'|'refused'|'stale'|'unavailable' $state
     * @param list<string> $skippedPaths
     */
    public function __construct(
        public string $state,
        public string $databasePath,
        public string $mapSnapshot,
        public ?string $searchSnapshot,
        public int $indexedChunks,
        public int $prunedFiles,
        public int $skippedChunks,
        public array $skippedPaths,
        public ?string $reason,
        public string $message,
    ) {
    }
}
