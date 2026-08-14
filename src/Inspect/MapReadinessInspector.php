<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use voku\AgentMap\Index\AgentMapIndex;
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
        $mapState = 'missing';
        $map = null;
        $mapSnapshot = null;
        $staleEntries = [];
        $mapFailure = null;

        if (is_file($mapPath)) {
            try {
                $map = (new IndexReader())->read($mapPath);
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
        if ($mapState === 'ready' && $mapSnapshot !== null) {
            if (!is_file($searchPath)) {
                $searchState = 'missing';
            } else {
                try {
                    $searchSnapshot = $this->readSearchSnapshot($searchPath);
                    if ($searchSnapshot === null || $searchSnapshot === '') {
                        $searchState = 'invalid';
                        $searchSnapshot = null;
                        $searchFailure = 'Search index does not record the map snapshot it was built from.';
                    } else {
                        $searchState = hash_equals($mapSnapshot, $searchSnapshot) ? 'ready' : 'stale';
                    }
                } catch (RuntimeException $exception) {
                    $searchState = 'invalid';
                    $searchFailure = $exception->getMessage();
                }
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

        if (!defined('PDO::SQLITE_ATTR_OPEN_FLAGS') || !defined('PDO::SQLITE_OPEN_READONLY')) {
            throw new RuntimeException('PDO SQLite read-only open flags are unavailable.');
        }
        $attribute = constant('PDO::SQLITE_ATTR_OPEN_FLAGS');
        $readOnly = constant('PDO::SQLITE_OPEN_READONLY');
        if (!is_int($attribute) || !is_int($readOnly)) {
            throw new RuntimeException('PDO SQLite read-only open flags are invalid.');
        }
        $options[$attribute] = $readOnly;

        return new PDO($dsn, null, null, $options);
    }
}
