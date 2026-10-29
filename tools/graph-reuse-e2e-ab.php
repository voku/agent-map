<?php

declare(strict_types=1);

/**
 * Isolated Actions experiment: compare complete real PHPStan-backed agent-map
 * builds that encounter an unchanged ordered graph. This is not product code.
 *
 * The baseline deliberately removes the published projection digest BEFORE
 * each changed-source build, forcing the safe full-replacement fallback. The
 * candidate retains the digest and has a trigger that refuses full rebuilds.
 * Both modes analyze exactly the same real sources and have separate, warmed
 * PHPStan caches. Do not add stage times to these end-to-end measurements.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use PDO;
use RuntimeException;
use voku\AgentMap\Index\MapGraphIndex;

const SAMPLES = 3;

/** @return array{elapsed_ms: float, output: string} */
function build(string $root, string $indexFile): array
{
    $command = [
        PHP_BINARY,
        $root . '/bin/agent-map',
        'build',
        '--root=' . $root,
        '--paths=src',
        '--out=' . $indexFile,
        '--phpstan-memory-limit=512M',
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $started = hrtime(true);
    $process = proc_open($command, $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch real agent-map CLI.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $elapsed = round((hrtime(true) - $started) / 1_000_000, 3);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            "agent-map build failed (code %d, %.3f ms):\n%s\n%s",
            $exitCode,
            $elapsed,
            (string) $stdout,
            (string) $stderr,
        ));
    }

    return ['elapsed_ms' => $elapsed, 'output' => (string) $stdout . (string) $stderr];
}

function median(array $samples): float
{
    sort($samples, SORT_NUMERIC);

    return round($samples[intdiv(count($samples), 2)], 3);
}

/** @return array{revision: string, relation_count: int} */
function verify(string $indexFile): array
{
    $graph = (new MapGraphIndex())->verifyCurrent($indexFile);
    $revision = $graph->sourceRevision();
    if (!is_string($revision)) {
        throw new RuntimeException('Graph has no source revision.');
    }
    $count = $graph->relationCount();
    if ($count < 100) {
        throw new RuntimeException('Measured graph unexpectedly small: ' . $count);
    }

    return ['revision' => $revision, 'relation_count' => $count];
}

function equalFile(string $path1, string $path2): void
{
    $a = hash_file('sha256', $path1);
    $b = hash_file('sha256', $path2);
    if (!is_string($a) || !hash_equals($a, (string) $b)) {
        throw new RuntimeException('Canonical output differs across modes: ' . basename($path1));
    }
}

$root = realpath(dirname(__DIR__));
if (!is_string($root)) {
    throw new RuntimeException('Project root missing.');
}
$source = $root . '/src/Index/MapGraphIndex.php';
$original = file_get_contents($source);
if (!is_string($original)) {
    throw new RuntimeException('Benchmark source fixture missing.');
}
$work = rtrim((string) (getenv('RUNNER_TEMP') ?: sys_get_temp_dir()), '/') . '/agent-map-graph-e2e-' . bin2hex(random_bytes(6));
$paths = [
    'fast' => $work . '/fast/php-symbols.json',
    'full' => $work . '/full/php-symbols.json',
];
foreach ($paths as $path) {
    if (!mkdir(dirname($path), 0o775, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Unable to create benchmark workspace.');
    }
}

$times = ['fast' => [], 'full' => []];
$rounds = [];
$relationCount = 0;
try {
    // Equivalent initial state. Caches and SQLite are independent per mode.
    foreach (['full', 'fast'] as $mode) {
        build($root, $paths[$mode]);
    }
    equalFile($paths['full'], $paths['fast']);
    equalFile($work . '/full/php-relations.json', $work . '/fast/php-relations.json');
    $first = verify($paths['fast']);
    if (verify($paths['full']) !== $first) {
        throw new RuntimeException('Baseline and candidate differ before measurement.');
    }

    $fastDatabase = $work . '/fast/php-symbols.graph.sqlite';
    $fullDatabase = $work . '/full/php-symbols.graph.sqlite';
    $fastPdo = new PDO('sqlite:' . $fastDatabase, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $fullPdo = new PDO('sqlite:' . $fullDatabase, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // This is an actual proof that the *real CLI* never rebuilt graph rows.
    $fastPdo->exec(
        "CREATE TRIGGER forbid_replacement BEFORE DELETE ON graph_relations "
        . "BEGIN SELECT RAISE(ABORT, 'guarded path rebuilt the graph'); END",
    );

    $previousRevision = $first['revision'];
    $relationCount = $first['relation_count'];
    for ($round = 0; $round < SAMPLES; ++$round) {
        // One actual changed PHP source, identical for both candidates.
        // Comment-only edit must alter the canonical file digest, not relations.
        file_put_contents($source, $original . "\n// Performance-only comment edit: round " . $round . "\n");
        clearstatcache(true, $source);

        // The baseline deliberately cannot use the fast path, but otherwise
        // runs identical product code and canonical generation logic.
        $removed = $fullPdo->exec("DELETE FROM graph_meta WHERE key = 'projection_digest_v1'");
        if ($removed !== 1) {
            throw new RuntimeException('Baseline digest removal did not take effect.');
        }

        $order = $round % 2 === 0 ? ['full', 'fast'] : ['fast', 'full'];
        foreach ($order as $mode) {
            $result = build($root, $paths[$mode]);
            $times[$mode][] = $result['elapsed_ms'];
        }

        equalFile($paths['full'], $paths['fast']);
        equalFile($work . '/full/php-relations.json', $work . '/fast/php-relations.json');

        $baseline = verify($paths['full']);
        $candidate = verify($paths['fast']);
        if ($baseline !== $candidate || $baseline['revision'] === $previousRevision) {
            throw new RuntimeException('Graph identity or changed-source revision parity failed.');
        }
        if ($baseline['relation_count'] !== $relationCount) {
            throw new RuntimeException('Comment-only edit unexpectedly changed graph relations.');
        }
        $previousRevision = $baseline['revision'];

        $rounds[] = [
            'round' => $round + 1,
            'full_build_ms' => $times['full'][$round],
            'guarded_build_ms' => $times['fast'][$round],
            'canonical_byte_parity' => true,
            'graph_revision_parity' => true,
            'graph_relation_count' => $relationCount,
        ];
    }
} finally {
    // CI workspace should not retain an intentionally modified source file.
    file_put_contents($source, $original);
}

$full = median($times['full']);
$fast = median($times['fast']);
$report = [
    'head_commit' => (string) (getenv('GITHUB_SHA') ?: 'local'),
    'php' => PHP_VERSION,
    'fixture' => 'actual agent-map src, real PHPStan-backed CLI, comment-only changed source',
    'samples' => SAMPLES,
    'graph_relations' => $relationCount,
    'full_rebuild_median_ms' => $full,
    'guarded_reuse_median_ms' => $fast,
    'end_to_end_speedup' => round($full / max($fast, 0.001), 3),
    'absolute_saved_ms' => round($full - $fast, 3),
    'rounds' => $rounds,
    'invariants' => [
        'byte_identical_map_and_relations_artifacts' => true,
        'same_graph_revision_and_relations' => true,
        'old_revision_changed_every_round' => true,
        'full_baseline_digest_removed_each_round' => true,
        'guarded_path_replacement_prohibited_by_sqlite_trigger' => true,
        'per_mode_phpstan_caches_warmed_and_reused' => true,
    ],
    'limits' => [
        'One hosted runner per PHP version; order alternated across three paired samples.',
        'The baseline forces a safe full graph replacement by deleting only the disposable metadata digest.',
        'The source change is comment-only, and generated per-mode canonical files use the same basename.',
        'Measured CLI wall time includes parsing, PHPStan, canonical write, and SQLite; other workloads may differ.',
        'A digest delete and SQLite trigger are benchmark-only controls, not proposed production behavior.',
    ],
];
file_put_contents($work . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";

$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && $summary !== '') {
    $text = "## Real changed-source end-to-end graph reuse A/B\n\n";
    $text .= sprintf("PHP %s, %d graph relations, %d paired rounds.\n\n", PHP_VERSION, $relationCount, SAMPLES);
    $text .= "| Metric | Full SQLite fallback | Guarded reuse |\n| --- | ---: | ---: |\n";
    $text .= sprintf("| Median full CLI build | %.3f ms | %.3f ms |\n", $full, $fast);
    $text .= sprintf("| Absolute saving | | **%.3f ms** |\n", $full - $fast);
    $text .= sprintf("| End-to-end ratio | | **%.3fx** |\n", $report['end_to_end_speedup']);
    $text .= "\nCanonical artifact byte parity, updated graph revision, exact relation count, and trigger proof: **PASS**.\n";
    $text .= "\n**Interpretation:** total PHPStan-backed build wall time for a comment-only changed source. Runner-specific; not a general speedup guarantee.\n";
    file_put_contents($summary, $text, FILE_APPEND);
}
