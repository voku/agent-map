<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

use Throwable;
use voku\AgentMap\Index\AgentMapIndex;

/**
 * Reconciles the optional Search projection for an already-prepared Map.
 *
 * This is deliberately separate from MapPreparationService: Map navigation is
 * authoritative, while Search is a derived capability that may be absent or
 * unavailable on the host. The service owns snapshot, chunk and pruning
 * semantics so consumers do not reconstruct SearchIndexStore choreography.
 */
final readonly class SearchMaintenanceService
{
    public function refreshIfPresent(SearchMaintenanceRequest $request): SearchMaintenanceResult
    {
        $databasePath = $request->artifacts->searchDatabase();
        $mapSnapshot = $this->mapSnapshot($request->index);

        if (!is_file($databasePath)) {
            return $this->result(
                state: 'absent',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: null,
                message: 'Search index is absent: ' . $databasePath,
            );
        }

        if (!SearchIndexStore::supportsFts5()) {
            return $this->result(
                state: 'unavailable',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: null,
                reason: 'fts5_unavailable',
                message: 'SQLite FTS5 is unavailable; Search remains non-authoritative: ' . $databasePath,
            );
        }

        $stale = $request->index->staleEntries();
        if ($stale !== []) {
            return $this->result(
                state: 'refused',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: null,
                reason: 'map_stale',
                message: 'Search was not reconciled because the supplied Map is stale for ' . count($stale) . ' file(s).',
            );
        }

        try {
            $store = new SearchIndexStore($databasePath);
            $searchSnapshot = $store->meta('map_snapshot');
            $changedPaths = $this->changedPaths($request->index, $store, $mapSnapshot);
            $skippedPaths = [];
            $skippedChunks = 0;

            if ($changedPaths !== []) {
                $extractor = new ChunkExtractor();
                $chunks = $extractor->extract($request->index, $changedPaths);
                $skippedPaths = $extractor->skippedPaths();
                $skippedChunks = $store->replaceChunks($chunks, $changedPaths);
            }

            // A removed Map file is not present in changedPaths. The current Map
            // remains the authority on which Search paths are still valid.
            $mapPaths = array_map(
                static fn ($file): string => $file->path,
                $request->index->files,
            );
            $prunedFiles = $store->pruneMissingPaths($mapPaths);

            // Do not claim currentness when source material changed between Map
            // preparation and extraction. The next owner retry can reconcile it.
            if ($skippedPaths !== []) {
                return $this->result(
                    state: 'stale',
                    databasePath: $databasePath,
                    mapSnapshot: $mapSnapshot,
                    searchSnapshot: $searchSnapshot,
                    indexedChunks: $store->chunkCount(),
                    prunedFiles: $prunedFiles,
                    skippedChunks: $skippedChunks,
                    skippedPaths: $skippedPaths,
                    reason: 'source_changed_during_extraction',
                    message: 'Search remains stale because source changed during extraction: ' . implode(', ', $skippedPaths),
                );
            }

            $store->setMeta('map_snapshot', $mapSnapshot);
            $store->setMeta('chunk_policy_version', (string) ChunkPolicy::VERSION);

            $mutated = $changedPaths !== [] || $prunedFiles > 0 || $searchSnapshot !== $mapSnapshot;

            return $this->result(
                state: $mutated ? 'refreshed' : 'current',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: $mapSnapshot,
                indexedChunks: $store->chunkCount(),
                prunedFiles: $prunedFiles,
                skippedChunks: $skippedChunks,
                message: $mutated
                    ? 'Search index reconciled: ' . $databasePath
                    : 'Search index is current: ' . $databasePath,
            );
        } catch (Throwable $exception) {
            return $this->result(
                state: 'failed',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: null,
                reason: 'maintenance_failed',
                message: 'Search reconciliation failed: ' . $exception->getMessage(),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function changedPaths(AgentMapIndex $index, SearchIndexStore $store, string $mapSnapshot): array
    {
        if ($store->meta('map_snapshot') === $mapSnapshot && $store->chunkCount() > 0) {
            return [];
        }

        $indexedHashes = $store->sourceHashesByPath();
        $paths = [];
        foreach ($index->files as $file) {
            if (($indexedHashes[$file->path] ?? null) !== $file->sha256) {
                $paths[] = $file->path;
            }
        }

        return $paths;
    }

    private function mapSnapshot(AgentMapIndex $index): string
    {
        return $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest;
    }

    /**
     * @param 'absent'|'current'|'failed'|'refreshed'|'refused'|'stale'|'unavailable' $state
     * @param list<string> $skippedPaths
     */
    private function result(
        string $state,
        string $databasePath,
        string $mapSnapshot,
        ?string $searchSnapshot,
        int $indexedChunks = 0,
        int $prunedFiles = 0,
        int $skippedChunks = 0,
        array $skippedPaths = [],
        ?string $reason = null,
        string $message = '',
    ): SearchMaintenanceResult {
        return new SearchMaintenanceResult(
            state: $state,
            databasePath: $databasePath,
            mapSnapshot: $mapSnapshot,
            searchSnapshot: $searchSnapshot,
            indexedChunks: $indexedChunks,
            prunedFiles: $prunedFiles,
            skippedChunks: $skippedChunks,
            skippedPaths: $skippedPaths,
            reason: $reason,
            message: $message,
        );
    }
}
