<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

use PDO;
use PDOException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

/**
 * Observes Search capability/currentness without creating, migrating, or repairing it.
 */
final readonly class SearchReadinessInspector
{
    public function inspect(
        AgentMapIndex $index,
        string $indexPath,
        string $databasePath,
    ): SearchReadiness {
        $mapSnapshot = $index->fingerprint?->sourceDigest;
        if ($mapSnapshot === '') {
            $mapSnapshot = null;
        }

        if ($index->staleEntries() !== []) {
            return new SearchReadiness(
                state: 'unavailable',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: null,
                reason: 'map_stale',
                message: 'Ranked Search cannot prove currentness from a stale Map.',
            );
        }

        // A persisted "sha256:none" marker is deliberately not an identity.
        // SearchMaintenanceService must fully reconcile fingerprintless Maps,
        // so a later read-only consumer cannot use equality with that marker as
        // evidence that the persisted Search projection still matches this Map.
        if ($mapSnapshot === null) {
            return new SearchReadiness(
                state: 'unavailable',
                databasePath: $databasePath,
                mapSnapshot: null,
                searchSnapshot: null,
                reason: 'map_snapshot_unverifiable',
                message: 'Ranked Search requires a reproducible Map snapshot identity.',
            );
        }

        if (!SearchIndexStore::supportsFts5()) {
            return new SearchReadiness(
                state: 'unavailable',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: null,
                reason: 'fts5_unavailable',
                message: 'SQLite FTS5 is unavailable; ranked Search is not available on this host.',
            );
        }

        if (!is_file($databasePath)) {
            return new SearchReadiness(
                state: 'missing',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: null,
                reason: 'search_index_missing',
                message: 'Search index is absent: ' . $databasePath,
                recoveryCommand: $this->recoveryCommand('build', $index, $indexPath, $databasePath),
            );
        }

        try {
            $metadata = $this->readMetadata($databasePath);
        } catch (RuntimeException $exception) {
            return new SearchReadiness(
                state: 'invalid',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: null,
                reason: 'search_index_unreadable',
                message: $exception->getMessage(),
            );
        }

        $searchSnapshot = $metadata['map_snapshot'] ?? null;
        if ($searchSnapshot === null || $searchSnapshot === '') {
            return new SearchReadiness(
                state: 'invalid',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: null,
                reason: 'search_snapshot_missing',
                message: 'Search index does not record the Map snapshot it was built from.',
                recoveryCommand: $this->recoveryCommand('refresh', $index, $indexPath, $databasePath),
            );
        }

        if (($metadata['chunk_policy_version'] ?? null) !== (string) ChunkPolicy::VERSION) {
            return new SearchReadiness(
                state: 'stale',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: $searchSnapshot,
                reason: 'chunk_policy_stale',
                message: 'Search index was built with another chunk policy.',
                recoveryCommand: $this->recoveryCommand('refresh', $index, $indexPath, $databasePath),
            );
        }

        if (!hash_equals($mapSnapshot, $searchSnapshot)) {
            return new SearchReadiness(
                state: 'stale',
                databasePath: $databasePath,
                mapSnapshot: $mapSnapshot,
                searchSnapshot: $searchSnapshot,
                reason: 'map_snapshot_mismatch',
                message: 'Search index was built from another Map snapshot.',
                recoveryCommand: $this->recoveryCommand('refresh', $index, $indexPath, $databasePath),
            );
        }

        return new SearchReadiness(
            state: 'ready',
            databasePath: $databasePath,
            mapSnapshot: $mapSnapshot,
            searchSnapshot: $searchSnapshot,
        );
    }

    /** @return array<string, string> */
    private function readMetadata(string $databasePath): array
    {
        try {
            $pdo = $this->openReadOnly($databasePath);
            $statement = $pdo->query(
                "SELECT key, value FROM search_meta WHERE key IN ('map_snapshot', 'chunk_policy_version')",
            );
            if ($statement === false) {
                return [];
            }
            $metadata = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_string($row['key'] ?? null) || !is_string($row['value'] ?? null)) {
                    continue;
                }
                $metadata[$row['key']] = $row['value'];
            }

            return $metadata;
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Unable to inspect Search index: ' . $databasePath . ' (' . $exception->getMessage() . ')',
                0,
                $exception,
            );
        }
    }

    private function openReadOnly(string $databasePath): PDO
    {
        $dsn = 'sqlite:' . $databasePath;
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        if (class_exists('Pdo\\Sqlite')) {
            $options[\Pdo\Sqlite::ATTR_OPEN_FLAGS] = \Pdo\Sqlite::OPEN_READONLY;

            return new \Pdo\Sqlite($dsn, null, null, $options);
        }

        if (!defined('PDO::SQLITE_ATTR_OPEN_FLAGS') || !defined('PDO::SQLITE_OPEN_READONLY')) {
            throw new RuntimeException('PDO SQLite read-only open flags are unavailable.');
        }
        $options[constant('PDO::SQLITE_ATTR_OPEN_FLAGS')] = constant('PDO::SQLITE_OPEN_READONLY');

        return new PDO($dsn, null, null, $options);
    }

    private function recoveryCommand(
        string $operation,
        AgentMapIndex $index,
        string $indexPath,
        string $databasePath,
    ): string {
        return 'agent-map search-index ' . $operation
            . ' --root=' . self::shellArgument($index->root)
            . ' --index=' . self::shellArgument($indexPath)
            . ' --database=' . self::shellArgument($databasePath);
    }

    private static function shellArgument(string $value): string
    {
        return preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $value) === 1
            ? $value
            : escapeshellarg($value);
    }
}
