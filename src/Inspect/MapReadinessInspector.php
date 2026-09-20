<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

use Throwable;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Search\SearchReadinessInspector;

/**
 * Reads current map/Search readiness without rebuilding, repairing, or migrating state.
 */
final readonly class MapReadinessInspector
{
    public function inspect(MapArtifactPaths $artifacts, bool $loadRelations = true): MapReadiness
    {
        $mapPath = $artifacts->indexJson();
        $searchPath = $artifacts->searchDatabase();
        $mapState = 'missing';
        $map = null;
        $mapSnapshot = null;
        /** @var list<array{path: string, reason: 'missing'|'hash'}> $staleEntries */
        $staleEntries = [];
        $mapFailure = null;

        if (is_file($mapPath)) {
            try {
                $reader = new IndexReader();
                $map = $loadRelations
                    ? $reader->read($mapPath)
                    : $reader->readSections($mapPath, ['files']);
                /** @var list<array{path: string, reason: 'missing'|'hash'}> $staleEntries */
                $staleEntries = $map->staleEntries();
                $mapSnapshot = $map->fingerprint?->sourceDigest;
                if ($mapSnapshot === '') {
                    $mapSnapshot = null;
                }
                $mapState = $staleEntries === [] ? 'ready' : 'stale';
            } catch (Throwable $exception) {
                $mapState = 'invalid';
                $mapFailure = $exception->getMessage();
                $map = null;
            }
        }

        $searchState = 'unavailable';
        $searchSnapshot = null;
        $searchFailure = null;
        if ($mapState === 'ready' && $map instanceof AgentMapIndex) {
            $search = (new SearchReadinessInspector())->inspect($map, $mapPath, $searchPath);
            $searchState = $search->state;
            $searchSnapshot = $search->searchSnapshot;
            if ($search->state === 'invalid') {
                $searchFailure = $search->message;
            }
        }

        return new MapReadiness(
            mapState: $mapState,
            mapPath: $mapPath,
            mapSnapshot: $mapSnapshot,
            staleEntries: $staleEntries,
            searchState: $searchState,
            searchPath: $searchPath,
            searchSnapshot: $searchSnapshot,
            mapFailure: $mapFailure,
            searchFailure: $searchFailure,
            map: $map,
        );
    }
}
