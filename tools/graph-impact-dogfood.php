<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentMap\Discovery\ArchitectureImpactAnalyzer;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\MapArtifactPaths;

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tools/graph-impact-dogfood.php legacy INDEX TARGET\n");
    fwrite(STDERR, "  php tools/graph-impact-dogfood.php sqlite INDEX TARGET\n");
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

if (!in_array($mode, ['legacy', 'sqlite'], true) || count($argv) !== 4) {
    $usage();
}

$indexFile = $argv[2];
$target = $argv[3];
$reader = new IndexReader();
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

$relationFile = MapArtifactPaths::relationsFileFor($indexFile);
$graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);
$relationBytes = is_file($relationFile) ? filesize($relationFile) : false;
$graphBytes = is_file($graphFile) ? filesize($graphFile) : false;

$result = [
    'mode' => $mode,
    'elapsed_ms' => $elapsedMs,
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'relation_count_loaded' => $relationCountLoaded,
    'relation_artifact_bytes' => is_int($relationBytes) ? $relationBytes : 0,
    'graph_database_bytes' => is_int($graphBytes) ? $graphBytes : 0,
    'report' => $reportData,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
