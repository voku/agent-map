<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use JsonException;
use RuntimeException;
use voku\AgentGraph\Sqlite\GraphStore;
use voku\AgentMap\MapArtifactPaths;

final readonly class MapGraphIndex
{
    private const GENERATION_SCHEMA_VERSION = '1';

    public function __construct(
        private GraphProjectionFactory $projectionFactory = new GraphProjectionFactory(),
        private GraphIndexFingerprint $fingerprint = new GraphIndexFingerprint(),
    ) {
    }

    public function rebuild(AgentMapIndex $index, string $indexFile): void
    {
        $mapDigest = $index->mapDigest();
        $this->publishGeneration($indexFile, $mapDigest);

        $fingerprint = $this->fingerprint->forIndexFile($indexFile);
        $store = new GraphStore(MapArtifactPaths::graphDatabaseFor($indexFile));
        $store->replace(
            $this->projectionFactory->relations($index),
            sourceRevision: $mapDigest,
            sourceFingerprint: $fingerprint,
            allowEmpty: true,
        );
    }

    public function openCurrent(string $indexFile): GraphStore
    {
        $database = MapArtifactPaths::graphDatabaseFor($indexFile);
        if (!is_file($database)) {
            throw new RuntimeException('Derived graph index not found; rebuild the agent-map index: ' . $database);
        }

        $expectedRevision = $this->generationRevision($indexFile);
        $store = new GraphStore($database);
        $actualRevision = $store->sourceRevision();
        if ($actualRevision === null || !hash_equals($expectedRevision, $actualRevision)) {
            throw new RuntimeException('Derived graph index is stale; rebuild the agent-map index: ' . $database);
        }

        return $store;
    }

    public function verifyCurrent(string $indexFile): GraphStore
    {
        $store = $this->openCurrent($indexFile);

        $expectedFingerprint = $this->fingerprint->forIndexFile($indexFile);
        $actualFingerprint = $store->sourceFingerprint();
        if ($actualFingerprint === null || !hash_equals($expectedFingerprint, $actualFingerprint)) {
            throw new RuntimeException('Derived graph index failed canonical artifact fingerprint verification.');
        }

        $integrityFailures = $store->integrityFailures();
        if ($integrityFailures !== []) {
            throw new RuntimeException('Derived graph index failed integrity checks: ' . implode(', ', $integrityFailures));
        }

        return $store;
    }

    private function publishGeneration(string $indexFile, string $mapDigest): void
    {
        $generationFile = MapArtifactPaths::graphGenerationFor($indexFile);
        $temporary = $generationFile . '.tmp-' . getmypid();
        $content = json_encode([
            'schema_version' => self::GENERATION_SCHEMA_VERSION,
            'map_digest' => $mapDigest,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        if (file_put_contents($temporary, $content) === false) {
            throw new RuntimeException('Unable to write graph generation marker: ' . $temporary);
        }
        if (!rename($temporary, $generationFile)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish graph generation marker: ' . $generationFile);
        }
    }

    private function generationRevision(string $indexFile): string
    {
        $generationFile = MapArtifactPaths::graphGenerationFor($indexFile);
        if (!is_file($generationFile)) {
            throw new RuntimeException('Derived graph generation marker not found; rebuild the agent-map index: ' . $generationFile);
        }

        $content = file_get_contents($generationFile);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read derived graph generation marker: ' . $generationFile);
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid derived graph generation marker: ' . $generationFile, previous: $exception);
        }

        $schemaVersion = is_array($data) ? ($data['schema_version'] ?? null) : null;
        $mapDigest = is_array($data) ? ($data['map_digest'] ?? null) : null;
        if ($schemaVersion !== self::GENERATION_SCHEMA_VERSION || !is_string($mapDigest) || !str_starts_with($mapDigest, 'sha256:')) {
            throw new RuntimeException('Invalid derived graph generation marker: ' . $generationFile);
        }

        return $mapDigest;
    }
}
