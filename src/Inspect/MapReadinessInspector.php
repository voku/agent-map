<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

use PDO;
use PDOException;
use RuntimeException;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;

/**
 * Reads current map/Search readiness without rebuilding, repairing, or migrating state.
 */
final readonly class MapReadinessInspector
{
    public function inspect(MapArtifactPaths $artifacts): MapReadiness
    {
        $mapPath = $artifacts->indexJson();
        $searchPath = $artifacts->searchDatabase();

        if (!is_file($mapPath)) {
            return new MapReadiness(
                mapState: 'missing',
                mapPath: $mapPath,
                mapSnapshot: null,
                staleEntries: [],
                searchState: 'unavailable',
                searchPath: $searchPath,
                searchSnapshot: null,
            );
        }

        try {
            $map = (new IndexReader())->read($mapPath);
        } catch (RuntimeException $exception) {
            return new MapReadiness(
                mapState: 'invalid',
                mapPath: $mapPath,
                mapSnapshot: null,
                staleEntries: [],
                searchState: 'unavailable',
                searchPath: $searchPath,
                searchSnapshot: null,
                mapFailure: $exception->getMessage(),
            );
        }

        $staleEntries = $map->staleEntries();
        $mapSnapshot = $map->fingerprint?->sourceDigest;
        if ($staleEntries !== []) {
            return new MapReadiness(
                mapState: 'stale',
                mapPath: $mapPath,
                mapSnapshot: $mapSnapshot,
                staleEntries: $staleEntries,
                searchState: 'unavailable',
                searchPath: $searchPath,
                searchSnapshot: null,
                map: $map,
            );
        }

        if ($mapSnapshot === null || $mapSnapshot === '') {
            return new MapReadiness(
                mapState: 'ready',
                mapPath: $mapPath,
                mapSnapshot: null,
                staleEntries: [],
                searchState: 'unavailable',
                searchPath: $searchPath,
                searchSnapshot: null,
                map: $map,
            );
        }

        if (!is_file($searchPath)) {
            return new MapReadiness(
                mapState: 'ready',
                mapPath: $mapPath,
                mapSnapshot: $mapSnapshot,
                staleEntries: [],
                searchState: 'missing',
                searchPath: $searchPath,
                searchSnapshot: null,
                map: $map,
            );
        }

        try {
            $searchSnapshot = $this->readSearchSnapshot($searchPath);
        } catch (RuntimeException $exception) {
            return new MapReadiness(
                mapState: 'ready',
                mapPath: $mapPath,
                mapSnapshot: $mapSnapshot,
                staleEntries: [],
                searchState: 'invalid',
                searchPath: $searchPath,
                searchSnapshot: null,
                searchFailure: $exception->getMessage(),
                map: $map,
            );
        }

        if ($searchSnapshot === null || $searchSnapshot === '') {
            return new MapReadiness(
                mapState: 'ready',
                mapPath: $mapPath,
                mapSnapshot: $mapSnapshot,
                staleEntries: [],
                searchState: 'invalid',
                searchPath: $searchPath,
                searchSnapshot: null,
                searchFailure: 'Search index does not record the map snapshot it was built from.',
                map: $map,
            );
        }

        return new MapReadiness(
            mapState: 'ready',
            mapPath: $mapPath,
            mapSnapshot: $mapSnapshot,
            staleEntries: [],
            searchState: hash_equals($mapSnapshot, $searchSnapshot) ? 'ready' : 'stale',
            searchPath: $searchPath,
            searchSnapshot: $searchSnapshot,
            map: $map,
        );
    }

    private function readSearchSnapshot(string $databaseFile): ?string
    {
        try {
            $pdo = $this->openReadOnly($databaseFile);
            $statement = $pdo->prepare('SELECT value FROM search_meta WHERE key = :key');
            $statement->execute(['key' => 'map_snapshot']);
            $value = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Unable to inspect Search index snapshot: ' . $databaseFile . ' (' . $exception->getMessage() . ')',
                0,
                $exception,
            );
        }

        return is_string($value) ? $value : null;
    }

    private function openReadOnly(string $databaseFile): PDO
    {
        $dsn = 'sqlite:' . $databaseFile;
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        if (class_exists('Pdo\\Sqlite')) {
            $options[\Pdo\Sqlite::ATTR_OPEN_FLAGS] = \Pdo\Sqlite::OPEN_READONLY;

            return new \Pdo\Sqlite($dsn, null, null, $options);
        }

        $attribute = constant('PDO::SQLITE_ATTR_OPEN_FLAGS');
        $readOnly = constant('PDO::SQLITE_OPEN_READONLY');
        if (!is_int($attribute) || !is_int($readOnly)) {
            throw new RuntimeException('PDO SQLite read-only open flags are unavailable.');
        }
        $options[$attribute] = $readOnly;

        return new PDO($dsn, null, null, $options);
    }
}
