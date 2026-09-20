<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

/**
 * Read-only owner result for the optional derived Search projection.
 *
 * Search never becomes Map authority. Consumers decide whether a non-ready
 * capability blocks, warns, or simply removes ranked navigation.
 */
final readonly class SearchReadiness
{
    /**
     * @param 'invalid'|'missing'|'ready'|'stale'|'unavailable' $state
     */
    public function __construct(
        public string $state,
        public string $databasePath,
        public ?string $mapSnapshot,
        public ?string $searchSnapshot,
        public ?string $reason = null,
        public ?string $message = null,
        public ?string $recoveryCommand = null,
    ) {
    }

    public function isReady(): bool
    {
        return $this->state === 'ready';
    }
}
