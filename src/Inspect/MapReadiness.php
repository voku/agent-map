<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

use voku\AgentMap\Index\AgentMapIndex;

/**
 * Owner-side observation of the current canonical map and its derived Search index.
 *
 * This object contains facts only. Embedders decide whether a missing, stale, or
 * unavailable capability blocks, warns, or narrows their own workflow.
 */
final readonly class MapReadiness
{
    /**
     * @param 'missing'|'invalid'|'stale'|'ready' $mapState
     * @param list<array{path: string, reason: 'missing'|'hash'}> $staleEntries
     * @param 'unavailable'|'missing'|'invalid'|'stale'|'ready' $searchState
     */
    public function __construct(
        public string $mapState,
        public string $mapPath,
        public ?string $mapSnapshot,
        public array $staleEntries,
        public string $searchState,
        public string $searchPath,
        public ?string $searchSnapshot,
        public ?string $mapFailure = null,
        public ?string $searchFailure = null,
        private ?AgentMapIndex $map = null,
    ) {
    }

    public function currentMap(): ?AgentMapIndex
    {
        return $this->mapState === 'ready' ? $this->map : null;
    }

    public function rankedSearchReady(): bool
    {
        return $this->mapState === 'ready'
            && $this->mapSnapshot !== null
            && $this->searchState === 'ready';
    }
}
