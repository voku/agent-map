<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentGraph\Graph\TraversalDirection;
use voku\AgentMap\Discovery\ArchitectureImpactAnalyzer;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\MapArtifactPaths;

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tools/graph-impact-dogfood.php legacy INDEX TARGET\n");
    fwrite(STDERR, "  php tools/graph-impact-dogfood.php sqlite INDEX TARGET\n");
    fwrite(STDERR, "  php tools/graph-impact-dogfood.php queries INDEX TARGET\n");
    fwrite(STDERR, "  php tools/graph-impact-dogfood.php rebuild INDEX\n");
    fwrite(STDERR, "  php tools/graph-impact-dogfood.php compare LEGACY_JSON SQLITE_JSON\n");
    exit(2);
};

$readJson = static function (string $path): array {
    $json = is_file($path) ? file_get_contents($path) : false;
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data)) {
        throw new RuntimeException('Invalid graph dogfood JSON: ' . $path);
    }

    return $data;
};

$artifactSizes = static function (string $indexFile): array {
    $relationFile = MapArtifactPaths::relationsFileFor($indexFile);
    $graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);
    $relationBytes = is_file($relationFile) ? filesize($relationFile) : false;
    $graphBytes = is_file($graphFile) ? filesize($graphFile) : false;

    return [
        'relation_artifact_bytes' => is_int($relationBytes) ? $relationBytes : 0,
        'graph_database_bytes' => is_int($graphBytes) ? $graphBytes : 0,
    ];
};

$mode = $argv[1] ?? null;
if (!is_string($mode)) {
    $usage();
}

if ($mode === 'compare') {
    if (count($argv) !== 4) {
        $usage();
    }

    $legacy = $readJson($argv[2]);
    $sqlite = $readJson($argv[3]);

    if (($legacy['report'] ?? null) !== ($sqlite['report'] ?? null)) {
        fwrite(STDERR, "[FAIL] SQLite impact result differs from the legacy full-relation path.\n");
        exit(1);
    }
    if ((int) ($legacy['relation_count_loaded'] ?? 0) < 1) {
        fwrite(STDERR, "[FAIL] Legacy proof did not load canonical relations.\n");
        exit(1);
    }
    if ((int) ($sqlite['relation_count_loaded'] ?? -1) !== 0) {
        fwrite(STDERR, "[FAIL] SQLite proof materialized canonical relations in PHP.\n");
        exit(1);
    }

    printf(
        "[PASS] graph impact parity: relation artifact=%d bytes; graph.sqlite=%d bytes; legacy=%.3f ms / %.2f MiB peak; sqlite=%.3f ms / %.2f MiB peak; legacy relations loaded=%d; sqlite relations loaded=%d.\n",
        (int) ($sqlite['relation_artifact_bytes'] ?? 0),
        (int) ($sqlite['graph_database_bytes'] ?? 0),
        (float) ($legacy['elapsed_ms'] ?? 0.0),
        ((int) ($legacy['peak_memory_bytes'] ?? 0)) / 1048576,
        (float) ($sqlite['elapsed_ms'] ?? 0.0),
        ((int) ($sqlite['peak_memory_bytes'] ?? 0)) / 1048576,
        (int) ($legacy['relation_count_loaded'] ?? 0),
        (int) ($sqlite['relation_count_loaded'] ?? 0),
    );
    exit(0);
}

$reader = new IndexReader();

if ($mode === 'queries') {
    if (count($argv) !== 4) {
        $usage();
    }

    $indexFile = $argv[2];
    $target = $argv[3];
    memory_reset_peak_usage();

    $filesStarted = hrtime(true);
    $map = $reader->readSections($indexFile, ['files']);
    $filesElapsedMs = (hrtime(true) - $filesStarted) / 1_000_000;
    if ($map->relations !== []) {
        throw new RuntimeException('Graph query benchmark unexpectedly loaded canonical relations.');
    }

    $openStarted = hrtime(true);
    $graph = (new MapGraphIndex())->openCurrent($indexFile);
    $openElapsedMs = (hrtime(true) - $openStarted) / 1_000_000;

    $incomingStarted = hrtime(true);
    $incoming = $graph->incoming($target);
    $incomingElapsedMs = (hrtime(true) - $incomingStarted) / 1_000_000;

    $outgoingStarted = hrtime(true);
    $outgoing = $graph->outgoing($target);
    $outgoingElapsedMs = (hrtime(true) - $outgoingStarted) / 1_000_000;

    $incomingTraversalStarted = hrtime(true);
    $incomingTraversal = $graph->traverse($target, TraversalDirection::INCOMING, 3, 500);
    $incomingTraversalElapsedMs = (hrtime(true) - $incomingTraversalStarted) / 1_000_000;

    $outgoingTraversalStarted = hrtime(true);
    $outgoingTraversal = $graph->traverse($target, TraversalDirection::OUTGOING, 3, 500);
    $outgoingTraversalElapsedMs = (hrtime(true) - $outgoingTraversalStarted) / 1_000_000;

    $result = [
        'mode' => $mode,
        'target' => $target,
        'files_only_load_ms' => $filesElapsedMs,
        'graph_open_ms' => $openElapsedMs,
        'incoming_ms' => $incomingElapsedMs,
        'incoming_count' => count($incoming),
        'outgoing_ms' => $outgoingElapsedMs,
        'outgoing_count' => count($outgoing),
        'incoming_traversal_ms' => $incomingTraversalElapsedMs,
        'incoming_traversal_nodes' => count($incomingTraversal->nodeIds),
        'incoming_traversal_relations' => count($incomingTraversal->relations),
        'incoming_traversal_truncated' => $incomingTraversal->truncated,
        'outgoing_traversal_ms' => $outgoingTraversalElapsedMs,
        'outgoing_traversal_nodes' => count($outgoingTraversal->nodeIds),
        'outgoing_traversal_relations' => count($outgoingTraversal->relations),
        'outgoing_traversal_truncated' => $outgoingTraversal->truncated,
        'graph_relation_count' => $graph->relationCount(),
        'relation_count_loaded' => count($map->relations),
        'peak_memory_bytes' => memory_get_peak_usage(true),
        ...$artifactSizes($indexFile),
    ];

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

if ($mode === 'rebuild') {
    if (count($argv) !== 3) {
        $usage();
    }

    $indexFile = $argv[2];
    $relationFile = MapArtifactPaths::relationsFileFor($indexFile);
    if (!is_file($indexFile) || !is_file($relationFile)) {
        throw new RuntimeException('Graph rebuild benchmark requires a split index and its relation companion.');
    }

    $temporaryDirectory = sys_get_temp_dir() . '/agent-map-graph-benchmark-' . bin2hex(random_bytes(8));
    if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
        throw new RuntimeException('Unable to create graph benchmark directory: ' . $temporaryDirectory);
    }

    $benchmarkIndex = $temporaryDirectory . '/' . basename($indexFile);
    $benchmarkRelations = MapArtifactPaths::relationsFileFor($benchmarkIndex);
    if (!copy($indexFile, $benchmarkIndex) || !copy($relationFile, $benchmarkRelations)) {
        throw new RuntimeException('Unable to stage canonical artifacts for graph rebuild benchmark.');
    }

    try {
        memory_reset_peak_usage();
        $decodeStarted = hrtime(true);
        $map = $reader->read($benchmarkIndex);
        $decodeElapsedMs = (hrtime(true) - $decodeStarted) / 1_000_000;
        $decodePeakMemoryBytes = memory_get_peak_usage(true);

        memory_reset_peak_usage();
        $rebuildStarted = hrtime(true);
        (new MapGraphIndex())->rebuild($map, $benchmarkIndex);
        $rebuildElapsedMs = (hrtime(true) - $rebuildStarted) / 1_000_000;
        $rebuildPeakMemoryBytes = memory_get_peak_usage(true);

        $graph = (new MapGraphIndex())->openCurrent($benchmarkIndex);
        $result = [
            'mode' => $mode,
            'canonical_decode_ms' => $decodeElapsedMs,
            'canonical_decode_peak_memory_bytes' => $decodePeakMemoryBytes,
            'graph_rebuild_ms' => $rebuildElapsedMs,
            'graph_rebuild_peak_memory_bytes' => $rebuildPeakMemoryBytes,
            'canonical_relation_count' => count($map->relations),
            'graph_relation_count' => $graph->relationCount(),
            ...$artifactSizes($benchmarkIndex),
        ];

        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } finally {
        $files = glob($temporaryDirectory . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        @rmdir($temporaryDirectory);
    }

    exit(0);
}

if (!in_array($mode, ['legacy', 'sqlite'], true) || count($argv) !== 4) {
    $usage();
}

$indexFile = $argv[2];
$target = $argv[3];
$started = hrtime(true);

if ($mode === 'legacy') {
    $map = $reader->read($indexFile);
    $relationCountLoaded = count($map->relations);
    $report = (new ArchitectureImpactAnalyzer())->forMethod($map, $target, 3, 500);
} else {
    $map = $reader->readSections($indexFile, ['files']);
    $relationCountLoaded = count($map->relations);
    if ($relationCountLoaded !== 0) {
        throw new RuntimeException('Files-only graph consumer unexpectedly loaded canonical relations.');
    }

    $graph = (new MapGraphIndex())->openCurrent($indexFile);
    $mapDigest = $graph->sourceRevision();
    if ($mapDigest === null) {
        throw new RuntimeException('Derived graph index has no source revision.');
    }

    $report = (new ArchitectureImpactAnalyzer())->forMethodUsingGraph($map, $graph, $mapDigest, $target, 3, 500);
}

$elapsedMs = (hrtime(true) - $started) / 1_000_000;
$reportData = $report->toArray();
unset($reportData['map_digest']);

$result = [
    'mode' => $mode,
    'elapsed_ms' => $elapsedMs,
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'relation_count_loaded' => $relationCountLoaded,
    ...$artifactSizes($indexFile),
    'report' => $reportData,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";