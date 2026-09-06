<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;
use voku\AgentGraph\Sqlite\GraphStore;
use voku\AgentMap\MapArtifactPaths;

final readonly class MapGraphIndex
{
    public function __construct(
        private GraphProjectionFactory $projectionFactory = new GraphProjectionFactory(),
        private GraphIndexFingerprint $fingerprint = new GraphIndexFingerprint(),
    ) {
    }

    public function rebuild(AgentMapIndex $index, string $indexFile): void
    {
        $fingerprint = $this->fingerprint->forIndexFile($indexFile);
        $store = new GraphStore(MapArtifactPaths::graphDatabaseFor($indexFile));
        $store->replace(
            $this->projectionFactory->relations($index),
            sourceRevision: $index->mapDigest(),
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

        $expectedFingerprint = $this->fingerprint->forIndexFile($indexFile);
        $store = new GraphStore($database);
        $actualFingerprint = $store->sourceFingerprint();
        if ($actualFingerprint === null || !hash_equals($expectedFingerprint, $actualFingerprint)) {
            throw new RuntimeException('Derived graph index is stale; rebuild the agent-map index: ' . $database);
        }

        $integrityFailures = $store->integrityFailures();
        if ($integrityFailures !== []) {
            throw new RuntimeException('Derived graph index failed integrity checks: ' . implode(', ', $integrityFailures));
        }

        return $store;
    }
}
