<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\MapArtifactPaths;

if (count($argv) !== 4) {
    fwrite(STDERR, "Usage: php tools/graph-scale-fixture.php INDEX MIN_RELATION_BYTES HOT_NODE_ID\n");
    exit(2);
}

$indexFile = $argv[1];
$minimumRelationBytes = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$hotNodeId = trim($argv[3]);
if (!is_int($minimumRelationBytes) || $hotNodeId === '') {
    fwrite(STDERR, "MIN_RELATION_BYTES must be a positive integer and HOT_NODE_ID must be non-empty.\n");
    exit(2);
}

$relationsFile = MapArtifactPaths::relationsFileFor($indexFile);
if (!is_file($indexFile) || !is_file($relationsFile)) {
    throw new RuntimeException('Scale fixture requires an existing split JSON index.');
}
if (!str_ends_with(strtolower($relationsFile), '.json')) {
    throw new RuntimeException('Scale fixture currently supports JSON relation companions only.');
}

$raw = file_get_contents($relationsFile);
$payload = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
if (!is_array($payload) || !isset($payload['relations']) || !is_array($payload['relations'])) {
    throw new RuntimeException('Invalid relation companion payload: ' . $relationsFile);
}

/** @var list<array<string, mixed>> $originalRelations */
$originalRelations = array_values(array_filter(
    $payload['relations'],
    static fn (mixed $relation): bool => is_array($relation),
));

$temporary = $relationsFile . '.scale-' . getmypid();
$handle = fopen($temporary, 'wb');
if ($handle === false) {
    throw new RuntimeException('Unable to create scale fixture: ' . $temporary);
}

$put = static function ($handle, string $content, string $path): void {
    if (fwrite($handle, $content) === false) {
        throw new RuntimeException('Unable to write scale fixture: ' . $path);
    }
};
$encode = static function (mixed $value): string {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode scale fixture value.');
    }

    return $json;
};

$syntheticRelations = 0;
$firstTopLevel = true;
try {
    $put($handle, '{', $temporary);
    foreach ($payload as $key => $value) {
        $put($handle, ($firstTopLevel ? '' : ",\n") . $encode((string) $key) . ':', $temporary);
        $firstTopLevel = false;

        if ($key !== 'relations') {
            $put($handle, $encode($value), $temporary);
            continue;
        }

        $put($handle, '[', $temporary);
        $firstRelation = true;
        foreach ($originalRelations as $relation) {
            $put($handle, ($firstRelation ? '' : ',') . $encode($relation), $temporary);
            $firstRelation = false;
        }

        while (true) {
            $position = ftell($handle);
            if (!is_int($position)) {
                throw new RuntimeException('Unable to measure scale fixture size.');
            }
            if ($position >= $minimumRelationBytes) {
                break;
            }

            ++$syntheticRelations;
            $suffix = str_pad((string) $syntheticRelations, 9, '0', STR_PAD_LEFT);
            $sourceId = 'method:benchmark\\ScaleSource' . $suffix . '::run';
            $targetId = 'method:benchmark\\ScaleTarget' . $suffix . '::run';

            // Give the benchmark node deterministic fan-in and fan-out while leaving most rows
            // unrelated. This exercises indexed direct queries without turning traversal into an
            // unbounded stress test.
            if ($syntheticRelations % 1000 === 0) {
                $targetId = $hotNodeId;
            } elseif ($syntheticRelations % 1000 === 1) {
                $sourceId = $hotNodeId;
            }

            $relation = [
                'id' => 'relation:scale-' . $suffix,
                'source_id' => $sourceId,
                'kind' => 'calls',
                'target_ids' => [$targetId],
                'file' => 'benchmark/ScaleFixture.php',
                'line_start' => 1,
                'line_end' => 1,
                'resolution' => 'phpstan_resolved',
                'receiver_type' => null,
                'result_type' => null,
                'start_file_pos' => null,
                'end_file_pos' => null,
            ];
            $put($handle, ($firstRelation ? '' : ',') . $encode($relation), $temporary);
            $firstRelation = false;
        }
        $put($handle, ']', $temporary);
    }
    $put($handle, "\n}\n", $temporary);
} finally {
    fclose($handle);
}

if (!rename($temporary, $relationsFile)) {
    @unlink($temporary);
    throw new RuntimeException('Unable to publish scale relation companion: ' . $relationsFile);
}

$relationBytes = filesize($relationsFile);
if (!is_int($relationBytes) || $relationBytes < $minimumRelationBytes) {
    throw new RuntimeException('Scale relation companion did not reach the requested byte floor.');
}

$decodeStarted = hrtime(true);
$map = (new IndexReader())->read($indexFile);
$decodeElapsedMs = (hrtime(true) - $decodeStarted) / 1_000_000;

$rebuildStarted = hrtime(true);
(new MapGraphIndex())->rebuild($map, $indexFile);
$rebuildElapsedMs = (hrtime(true) - $rebuildStarted) / 1_000_000;

$graph = (new MapGraphIndex())->openCurrent($indexFile);
$graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);
$graphBytes = filesize($graphFile);
if (!is_int($graphBytes)) {
    throw new RuntimeException('Scale fixture did not produce graph.sqlite.');
}

$result = [
    'minimum_relation_bytes' => $minimumRelationBytes,
    'relation_artifact_bytes' => $relationBytes,
    'graph_database_bytes' => $graphBytes,
    'original_relation_count' => count($originalRelations),
    'synthetic_relation_count' => $syntheticRelations,
    'canonical_relation_count' => count($map->relations),
    'graph_relation_count' => $graph->relationCount(),
    'canonical_decode_ms' => $decodeElapsedMs,
    'graph_rebuild_ms' => $rebuildElapsedMs,
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'hot_node_id' => $hotNodeId,
];

if ($result['canonical_relation_count'] !== $result['graph_relation_count']) {
    throw new RuntimeException('Scale fixture relation count differs between canonical JSON and graph.sqlite.');
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
