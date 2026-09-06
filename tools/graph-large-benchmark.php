<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentGraph\Graph\TraversalDirection;
use voku\AgentGraph\Sqlite\GraphStore;
use voku\AgentMap\Discovery\ArchitectureImpactAnalyzer;
use voku\AgentMap\Discovery\GraphAdjacency;
use voku\AgentMap\Index\GraphIndexFingerprint;
use voku\AgentMap\Index\GraphProjectionFactory;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\MapArtifactPaths;

const LOOKUP_ITERATIONS = 100;

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tools/graph-large-benchmark.php INDEX [IMPACT_TARGET]\n");
    exit(2);
};

$signature = static function (object $relation): string {
    /** @var object{id: string, sourceId: string, kind: string, targetIds: list<string>} $relation */
    return $relation->id . "\0" . $relation->sourceId . "\0" . $relation->kind . "\0" . implode("\0", $relation->targetIds);
};

$signatures = static function (array $relations) use ($signature): array {
    return array_map($signature, $relations);
};

$measureRepeated = static function (callable $callback): float {
    $callback();
    $started = hrtime(true);
    for ($i = 0; $i < LOOKUP_ITERATIONS; ++$i) {
        $callback();
    }

    return ((hrtime(true) - $started) / 1_000_000) / LOOKUP_ITERATIONS;
};

$readJson = static function (string $json, string $label): array {
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid benchmark worker JSON for ' . $label . '.');
    }

    return $decoded;
};

$runWorker = static function (string $mode, array $args) use ($readJson): array {
    $command = array_merge([PHP_BINARY, __FILE__, '--worker', $mode], $args);
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $output = [];
    $status = 0;
    exec($escaped . ' 2>&1', $output, $status);
    $text = implode("\n", $output);
    if ($status !== 0) {
        throw new RuntimeException(sprintf("Benchmark worker %s failed (%d):\n%s", $mode, $status, $text));
    }

    return $readJson($text, $mode);
};

if (($argv[1] ?? null) === '--worker') {
    $mode = $argv[2] ?? null;
    $indexFile = $argv[3] ?? null;
    if (!is_string($mode) || !is_string($indexFile) || !is_file($indexFile)) {
        $usage();
    }

    $reader = new IndexReader();

    if ($mode === 'select') {
        $map = $reader->read($indexFile);
        if ($map->relations === []) {
            throw new RuntimeException('Benchmark index contains no relations.');
        }

        $sourceDegree = [];
        $targetDegree = [];
        foreach ($map->relations as $relation) {
            $sourceDegree[$relation->sourceId] = ($sourceDegree[$relation->sourceId] ?? 0) + 1;
            foreach ($relation->targetIds as $targetId) {
                $targetDegree[$targetId] = ($targetDegree[$targetId] ?? 0) + 1;
            }
        }

        $pickHighest = static function (array $counts): string {
            $items = [];
            foreach ($counts as $id => $count) {
                $items[] = ['id' => (string) $id, 'count' => (int) $count];
            }
            usort(
                $items,
                static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['id'], $b['id']),
            );

            return $items[0]['id'] ?? throw new RuntimeException('Unable to select benchmark node.');
        };

        $sourceId = $pickHighest($sourceDegree);
        $targetId = $pickHighest($targetDegree);
        $kindCounts = [];
        foreach ($map->relations as $relation) {
            if ($relation->sourceId !== $sourceId && !in_array($targetId, $relation->targetIds, true)) {
                continue;
            }
            $kindCounts[$relation->kind] = ($kindCounts[$relation->kind] ?? 0) + 1;
        }

        echo json_encode([
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'kind' => $pickHighest($kindCounts),
            'relation_count' => count($map->relations),
        ], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }

    $sourceId = $argv[4] ?? null;
    $targetId = $argv[5] ?? null;
    $kind = $argv[6] ?? null;
    $impactTarget = $argv[7] ?? '';
    if (!is_string($sourceId) || !is_string($targetId) || !is_string($kind) || !is_string($impactTarget)) {
        $usage();
    }

    if ($mode === 'legacy') {
        $started = hrtime(true);
        $map = $reader->read($indexFile);
        $decodeMs = (hrtime(true) - $started) / 1_000_000;

        $started = hrtime(true);
        $adjacency = new GraphAdjacency($map);
        $adjacencyBuildMs = (hrtime(true) - $started) / 1_000_000;

        $incoming = $adjacency->incoming($targetId);
        $outgoing = $adjacency->outgoing($sourceId);
        $incomingKind = array_values(array_filter(
            $incoming,
            static fn (RelationEntry $relation): bool => $relation->kind === $kind,
        ));
        $outgoingKind = array_values(array_filter(
            $outgoing,
            static fn (RelationEntry $relation): bool => $relation->kind === $kind,
        ));

        $impactMs = null;
        $impactReport = null;
        if ($impactTarget !== '') {
            $started = hrtime(true);
            $report = (new ArchitectureImpactAnalyzer())->forMethod($map, $impactTarget, 3, 500);
            $impactMs = (hrtime(true) - $started) / 1_000_000;
            $impactReport = $report->toArray();
            unset($impactReport['map_digest']);
        }

        echo json_encode([
            'decode_ms' => $decodeMs,
            'adjacency_build_ms' => $adjacencyBuildMs,
            'incoming_lookup_ms' => $measureRepeated(static fn (): array => $adjacency->incoming($targetId)),
            'outgoing_lookup_ms' => $measureRepeated(static fn (): array => $adjacency->outgoing($sourceId)),
            'incoming' => $signatures($incoming),
            'outgoing' => $signatures($outgoing),
            'incoming_kind' => $signatures($incomingKind),
            'outgoing_kind' => $signatures($outgoingKind),
            'impact_ms' => $impactMs,
            'impact_report' => $impactReport,
            'relations_materialized' => count($map->relations),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }

    if ($mode === 'sqlite') {
        $started = hrtime(true);
        $map = $reader->readSections($indexFile, ['files']);
        $filesOnlyLoadMs = (hrtime(true) - $started) / 1_000_000;
        if ($map->relations !== []) {
            throw new RuntimeException('Files-only benchmark unexpectedly materialized canonical relations.');
        }

        $started = hrtime(true);
        $graph = (new MapGraphIndex())->openCurrent($indexFile);
        $graphOpenMs = (hrtime(true) - $started) / 1_000_000;

        $incoming = $graph->incoming($targetId);
        $outgoing = $graph->outgoing($sourceId);
        $incomingKind = $graph->incoming($targetId, $kind);
        $outgoingKind = $graph->outgoing($sourceId, $kind);

        $started = hrtime(true);
        $traversal = $graph->traverse($sourceId, TraversalDirection::OUTGOING, 3, 500);
        $traversalMs = (hrtime(true) - $started) / 1_000_000;

        $impactMs = null;
        $impactReport = null;
        if ($impactTarget !== '') {
            $revision = $graph->sourceRevision();
            if ($revision === null) {
                throw new RuntimeException('Graph benchmark store has no source revision.');
            }
            $started = hrtime(true);
            $report = (new ArchitectureImpactAnalyzer())->forMethodUsingGraph($map, $graph, $revision, $impactTarget, 3, 500);
            $impactMs = (hrtime(true) - $started) / 1_000_000;
            $impactReport = $report->toArray();
            unset($impactReport['map_digest']);
        }

        echo json_encode([
            'files_only_load_ms' => $filesOnlyLoadMs,
            'graph_open_ms' => $graphOpenMs,
            'incoming_lookup_ms' => $measureRepeated(static fn (): array => $graph->incoming($targetId)),
            'outgoing_lookup_ms' => $measureRepeated(static fn (): array => $graph->outgoing($sourceId)),
            'incoming' => $signatures($incoming),
            'outgoing' => $signatures($outgoing),
            'incoming_kind' => $signatures($incomingKind),
            'outgoing_kind' => $signatures($outgoingKind),
            'traversal_ms' => $traversalMs,
            'traversal_node_count' => count($traversal->nodeIds),
            'traversal_relation_count' => count($traversal->relations),
            'impact_ms' => $impactMs,
            'impact_report' => $impactReport,
            'relations_materialized' => count($map->relations),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }

    if ($mode === 'rebuild') {
        $started = hrtime(true);
        $map = $reader->read($indexFile);
        $decodeMs = (hrtime(true) - $started) / 1_000_000;

        $started = hrtime(true);
        $fingerprint = (new GraphIndexFingerprint())->forIndexFile($indexFile);
        $fingerprintMs = (hrtime(true) - $started) / 1_000_000;

        $temporaryDatabase = tempnam(sys_get_temp_dir(), 'agent-map-graph-benchmark-');
        if ($temporaryDatabase === false) {
            throw new RuntimeException('Unable to allocate temporary graph database.');
        }
        unlink($temporaryDatabase);

        try {
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $started = hrtime(true);
            $store = new GraphStore($temporaryDatabase);
            $store->replace(
                (new GraphProjectionFactory())->relations($map),
                sourceRevision: $map->mapDigest(),
                sourceFingerprint: $fingerprint,
                allowEmpty: true,
            );
            $replaceMs = (hrtime(true) - $started) / 1_000_000;
            $replacePeak = memory_get_peak_usage(true);
            $databaseBytes = is_file($temporaryDatabase) ? filesize($temporaryDatabase) : false;

            echo json_encode([
                'decode_ms' => $decodeMs,
                'fingerprint_ms' => $fingerprintMs,
                'graph_replace_ms' => $replaceMs,
                'graph_replace_peak_memory_bytes' => $replacePeak,
                'rebuilt_graph_database_bytes' => is_int($databaseBytes) ? $databaseBytes : 0,
                'relation_count' => count($map->relations),
            ], JSON_THROW_ON_ERROR) . "\n";
        } finally {
            @unlink($temporaryDatabase);
            @unlink($temporaryDatabase . '-shm');
            @unlink($temporaryDatabase . '-wal');
        }
        exit(0);
    }

    $usage();
}

$indexFile = $argv[1] ?? null;
$impactTarget = $argv[2] ?? '';
if (!is_string($indexFile) || !is_file($indexFile) || !is_string($impactTarget)) {
    $usage();
}

$relationFile = MapArtifactPaths::relationsFileFor($indexFile);
$graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);
if (!is_file($relationFile) || !is_file($graphFile)) {
    throw new RuntimeException('Benchmark requires canonical relations and a current graph.sqlite beside the index.');
}

$selection = $runWorker('select', [$indexFile]);
$sourceId = (string) ($selection['source_id'] ?? '');
$targetId = (string) ($selection['target_id'] ?? '');
$kind = (string) ($selection['kind'] ?? '');
$workerArgs = [$indexFile, $sourceId, $targetId, $kind, $impactTarget];
$legacy = $runWorker('legacy', $workerArgs);
$sqlite = $runWorker('sqlite', $workerArgs);
$rebuild = $runWorker('rebuild', $workerArgs);

foreach (['incoming', 'outgoing', 'incoming_kind', 'outgoing_kind'] as $key) {
    if (($legacy[$key] ?? null) !== ($sqlite[$key] ?? null)) {
        throw new RuntimeException('Graph parity failed for ' . $key . '.');
    }
}
if ($impactTarget !== '' && ($legacy['impact_report'] ?? null) !== ($sqlite['impact_report'] ?? null)) {
    throw new RuntimeException('Graph-backed impact report differs from legacy full-relation impact.');
}
if ((int) ($sqlite['relations_materialized'] ?? -1) !== 0) {
    throw new RuntimeException('SQLite benchmark materialized canonical relations in PHP.');
}

$relationBytes = filesize($relationFile);
$graphBytes = filesize($graphFile);
$result = [
    'index' => $indexFile,
    'selection' => $selection,
    'artifact_bytes' => [
        'relations' => is_int($relationBytes) ? $relationBytes : 0,
        'graph_sqlite' => is_int($graphBytes) ? $graphBytes : 0,
    ],
    'legacy' => $legacy,
    'sqlite' => $sqlite,
    'rebuild' => $rebuild,
    'parity' => [
        'incoming' => true,
        'outgoing' => true,
        'kind_filtered' => true,
        'impact' => $impactTarget === '' ? null : true,
    ],
];

printf(
    "[PASS] graph benchmark: relations=%d bytes; sqlite=%d bytes; decode=%.3f ms; adjacency=%.3f ms; graph-open=%.3f ms; incoming legacy/sqlite=%.6f/%.6f ms; outgoing legacy/sqlite=%.6f/%.6f ms; traversal sqlite=%.3f ms; rebuild replace=%.3f ms; peak legacy/sqlite=%.2f/%.2f MiB; relations loaded legacy/sqlite=%d/%d%s\n",
    $result['artifact_bytes']['relations'],
    $result['artifact_bytes']['graph_sqlite'],
    (float) $legacy['decode_ms'],
    (float) $legacy['adjacency_build_ms'],
    (float) $sqlite['graph_open_ms'],
    (float) $legacy['incoming_lookup_ms'],
    (float) $sqlite['incoming_lookup_ms'],
    (float) $legacy['outgoing_lookup_ms'],
    (float) $sqlite['outgoing_lookup_ms'],
    (float) $sqlite['traversal_ms'],
    (float) $rebuild['graph_replace_ms'],
    ((int) $legacy['peak_memory_bytes']) / 1048576,
    ((int) $sqlite['peak_memory_bytes']) / 1048576,
    (int) $legacy['relations_materialized'],
    (int) $sqlite['relations_materialized'],
    $impactTarget === '' ? '' : sprintf('; impact legacy/sqlite=%.3f/%.3f ms', (float) $legacy['impact_ms'], (float) $sqlite['impact_ms']),
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
