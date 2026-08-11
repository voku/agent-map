<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

use PDO;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

final class TemporalHistoryStore
{
    public const SCHEMA_VERSION = '1.0';

    private PDO $pdo;

    public function __construct(string $databaseFile)
    {
        $directory = dirname($databaseFile);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create temporal history directory: ' . $directory);
        }

        $this->pdo = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->migrate();
    }

    /** @param list<EntityObservation> $observations */
    public function record(AgentMapIndex $map, GitRevision $revision, array $observations): int
    {
        $this->pdo->beginTransaction();
        try {
            $snapshot = $this->pdo->prepare(
                'INSERT OR IGNORE INTO temporal_snapshots (revision, committed_at, map_digest)
                 VALUES (:revision, :committed_at, :map_digest)',
            );
            $snapshot->execute([
                'revision' => $revision->commit,
                'committed_at' => $revision->committedAt,
                'map_digest' => $map->mapDigest(),
            ]);

            $snapshotId = $this->snapshotId($revision->commit, $map->mapDigest());
            $delete = $this->pdo->prepare('DELETE FROM entity_observations WHERE snapshot_id = :snapshot_id');
            $delete->execute(['snapshot_id' => $snapshotId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO entity_observations
                    (snapshot_id, entity_type, entity_id, file_path, signature, callers, callees,
                     dependents, dependencies, region_id, region_label)
                 VALUES
                    (:snapshot_id, :entity_type, :entity_id, :file_path, :signature, :callers, :callees,
                     :dependents, :dependencies, :region_id, :region_label)',
            );
            foreach ($observations as $observation) {
                $insert->execute([
                    'snapshot_id' => $snapshotId,
                    'entity_type' => $observation->entityType,
                    'entity_id' => $observation->entityId,
                    'file_path' => $observation->filePath,
                    'signature' => $observation->signature,
                    'callers' => $observation->callers,
                    'callees' => $observation->callees,
                    'dependents' => $observation->dependents,
                    'dependencies' => $observation->dependencies,
                    'region_id' => $observation->regionId,
                    'region_label' => $observation->regionLabel,
                ]);
            }

            $this->pdo->commit();

            return $snapshotId;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Snapshot ids are the observation sequence. Git commit timestamps are metadata only: rebases,
     * cherry-picks and synthetic merge commits do not provide a reliable chronological ordering.
     *
     * @return list<array{
     *   snapshot_id: int,
     *   revision: string,
     *   committed_at: string,
     *   map_digest: string,
     *   entity_type: string,
     *   entity_id: string,
     *   file_path: string,
     *   signature: string,
     *   callers: int,
     *   callees: int,
     *   dependents: int,
     *   dependencies: int,
     *   region_id: ?string,
     *   region_label: ?string
     * }>
     */
    public function entityHistory(string $entityId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.snapshot_id, s.revision, s.committed_at, s.map_digest,
                    o.entity_type, o.entity_id, o.file_path, o.signature,
                    o.callers, o.callees, o.dependents, o.dependencies, o.region_id, o.region_label
             FROM entity_observations o
             JOIN temporal_snapshots s ON s.snapshot_id = o.snapshot_id
             WHERE o.entity_id = :entity_id
             ORDER BY s.snapshot_id',
        );
        $statement->execute(['entity_id' => $entityId]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->historyRow($row);
        }

        return $rows;
    }

    /**
     * @return array{snapshot_id: int, revision: string, committed_at: string, map_digest: string}|null
     */
    public function latestSnapshot(): ?array
    {
        $statement = $this->pdo->query(
            'SELECT snapshot_id, revision, committed_at, map_digest
             FROM temporal_snapshots
             ORDER BY snapshot_id DESC
             LIMIT 1',
        );
        if ($statement === false) {
            return null;
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'snapshot_id' => (int) $row['snapshot_id'],
            'revision' => (string) $row['revision'],
            'committed_at' => (string) $row['committed_at'],
            'map_digest' => (string) $row['map_digest'],
        ];
    }

    public function snapshotCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM temporal_snapshots');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    public function observationCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM entity_observations');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    private function snapshotId(string $revision, string $mapDigest): int
    {
        $statement = $this->pdo->prepare(
            'SELECT snapshot_id FROM temporal_snapshots WHERE revision = :revision AND map_digest = :map_digest',
        );
        $statement->execute(['revision' => $revision, 'map_digest' => $mapDigest]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Unable to resolve recorded temporal snapshot.');
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   snapshot_id: int,
     *   revision: string,
     *   committed_at: string,
     *   map_digest: string,
     *   entity_type: string,
     *   entity_id: string,
     *   file_path: string,
     *   signature: string,
     *   callers: int,
     *   callees: int,
     *   dependents: int,
     *   dependencies: int,
     *   region_id: ?string,
     *   region_label: ?string
     * }
     */
    private function historyRow(array $row): array
    {
        return [
            'snapshot_id' => (int) $row['snapshot_id'],
            'revision' => (string) $row['revision'],
            'committed_at' => (string) $row['committed_at'],
            'map_digest' => (string) $row['map_digest'],
            'entity_type' => (string) $row['entity_type'],
            'entity_id' => (string) $row['entity_id'],
            'file_path' => (string) $row['file_path'],
            'signature' => (string) $row['signature'],
            'callers' => (int) $row['callers'],
            'callees' => (int) $row['callees'],
            'dependents' => (int) $row['dependents'],
            'dependencies' => (int) $row['dependencies'],
            'region_id' => is_string($row['region_id'] ?? null) ? $row['region_id'] : null,
            'region_label' => is_string($row['region_label'] ?? null) ? $row['region_label'] : null,
        ];
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS temporal_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )',
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS temporal_snapshots (
                snapshot_id INTEGER PRIMARY KEY,
                revision TEXT NOT NULL,
                committed_at TEXT NOT NULL,
                map_digest TEXT NOT NULL,
                UNIQUE(revision, map_digest)
            )',
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS entity_observations (
                snapshot_id INTEGER NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                file_path TEXT NOT NULL,
                signature TEXT NOT NULL,
                callers INTEGER NOT NULL,
                callees INTEGER NOT NULL,
                dependents INTEGER NOT NULL,
                dependencies INTEGER NOT NULL,
                region_id TEXT NULL,
                region_label TEXT NULL,
                PRIMARY KEY(snapshot_id, entity_id),
                FOREIGN KEY(snapshot_id) REFERENCES temporal_snapshots(snapshot_id) ON DELETE CASCADE
            )',
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_temporal_entity ON entity_observations(entity_id, snapshot_id)');
        $statement = $this->pdo->prepare(
            'INSERT INTO temporal_meta (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
        );
        $statement->execute(['key' => 'schema_version', 'value' => self::SCHEMA_VERSION]);
    }
}
