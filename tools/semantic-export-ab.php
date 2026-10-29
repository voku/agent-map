<?php

declare(strict_types=1);

/**
 * Runs real CLI A/B against the exact same disposable source tree.
 * Use with CI checkouts of baseline main and this candidate, each with its
 * independent Composer install and PHPStan result cache.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\MapArtifactPaths;

function runBuild(string $directory, string $root, string $index): float
{
    $command = [
        PHP_BINARY,
        $directory . '/bin/agent-map',
        'build',
        '--root=' . $root,
        '--paths=src',
        '--out=' . $index,
        '--phpstan-memory-limit=512M',
    ];
    $p = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    if (!is_resource($p)) {
        throw new RuntimeException('Unable to start A/B CLI.');
    }
    $started = hrtime(true);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($p);
    $ms = round((hrtime(true) - $started) / 1e6, 3);
    if ($code !== 0) {
        throw new RuntimeException('Real build failed (' . $code . '): ' . (string) $out . ' ' . (string) $err);
    }

    return $ms;
}

function requireParity(string $a, string $b): int
{
    foreach ([$a, MapArtifactPaths::relationsFileFor($a)] as $path) {
        $other = $path === $a ? $b : MapArtifactPaths::relationsFileFor($b);
        $digestA = hash_file('sha256', $path);
        $digestB = hash_file('sha256', $other);
        if (!is_string($digestA) || !is_string($digestB) || !hash_equals($digestA, $digestB)) {
            $first = json_decode((string) file_get_contents($path), true);
            $second = json_decode((string) file_get_contents($other), true);
            if (is_array($first) && is_array($second)) {
                foreach (array_unique([...array_keys($first), ...array_keys($second)]) as $field) {
                    if (($first[$field] ?? null) !== ($second[$field] ?? null)) {
                        fwrite(STDERR, 'Canonical parity differs in field ' . $field . "\n");
                    }
                }
            }
            throw new RuntimeException('Canonical map/relations byte parity failed: ' . basename($path));
        }
    }

    $aGraph = (new MapGraphIndex())->verifyCurrent($a);
    $bGraph = (new MapGraphIndex())->verifyCurrent($b);
    if ($aGraph->sourceRevision() !== $bGraph->sourceRevision()
        || $aGraph->sourceFingerprint() !== $bGraph->sourceFingerprint()
        || $aGraph->relationCount() !== $bGraph->relationCount()
        || $aGraph->relationCount() < 100) {
        throw new RuntimeException('SQLite graph provenance / relation parity failed.');
    }

    return $aGraph->relationCount();
}

function median(array $a): float
{
    sort($a, SORT_NUMERIC);
    return round($a[intdiv(count($a), 2)], 3);
}

$root = getenv('AGENT_MAP_AB_FIXTURE');
$baseline = getenv('AGENT_MAP_AB_BASELINE');
$candidate = getenv('AGENT_MAP_AB_CANDIDATE');
if (!is_string($root) || !is_string($baseline) || !is_string($candidate)
    || !is_dir($root . '/src') || !is_file($baseline . '/bin/agent-map') || !is_file($candidate . '/bin/agent-map')) {
    throw new RuntimeException('A/B checkout or real fixture is missing.');
}
$root = (string) realpath($root);
$sourceFile = $root . '/src/Index/MapGraphIndex.php';
$original = file_get_contents($sourceFile);
if (!is_string($original)) {
    throw new RuntimeException('Missing real source fixture.');
}
$index = [
    'baseline' => $root . '/artifacts/baseline/php-symbols.json',
    'candidate' => $root . '/artifacts/candidate/php-symbols.json',
];
foreach ($index as $path) {
    if (!mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Cannot create isolated result cache.');
    }
}
$dirs = ['baseline' => $baseline, 'candidate' => $candidate];
$samples = ['cold' => [], 'warm' => [], 'comment_changed' => []];
$raw = [];
try {
    foreach (['baseline', 'candidate'] as $mode) {
        $samples['cold'][$mode][] = runBuild($dirs[$mode], $root, $index[$mode]);
    }
    $initialRelations = requireParity($index['baseline'], $index['candidate']);
    for ($i = 0; $i < 5; ++$i) {
        if ($i > 0) {
            file_put_contents($sourceFile, $original . "\n// A/B real source comment iteration " . $i . "\n");
            clearstatcache(true, $sourceFile);
        }

        $order = $i % 2 === 0 ? ['baseline', 'candidate'] : ['candidate', 'baseline'];
        $round = ['round' => $i + 1, 'order' => $order];
        foreach ($order as $mode) {
            $ms = runBuild($dirs[$mode], $root, $index[$mode]);
            $phase = $i === 0 ? 'warm' : 'comment_changed';
            $samples[$phase][$mode][] = $ms;
            $round[$mode . '_ms'] = $ms;
        }
        $round['relations'] = requireParity($index['baseline'], $index['candidate']);
        if ($round['relations'] !== $initialRelations) {
            throw new RuntimeException('Comment-only changed-source changed relations.');
        }
        $raw[] = $round;
    }

    // Negative case: introduce a real new source file with new call edges.
    $negative = $root . '/src/AbNegativeSemanticProbe.php';
    if (is_file($negative)) {
        throw new RuntimeException('Negative fixture path already in use.');
    }
    file_put_contents($negative, <<<'PHP'
<?php

declare(strict_types=1);

namespace voku\AgentMap\NegativeFixture;

final class AdditionalEdge
{
    public function value(): int
    {
        return strlen('semantic-change');
    }
}
PHP
    );
    foreach (['candidate', 'baseline'] as $mode) {
        runBuild($dirs[$mode], $root, $index[$mode]);
    }
    $newRelations = requireParity($index['baseline'], $index['candidate']);
    if ($newRelations <= $initialRelations) {
        throw new RuntimeException('Negative relation-change fixture failed to grow graph.');
    }
} finally {
    file_put_contents($sourceFile, $original);
    if (isset($negative) && is_file($negative)) {
        unlink($negative);
    }
}

$median = [];
foreach ($samples as $phase => $modes) {
    foreach ($modes as $mode => $times) {
        $median[$phase][$mode] = median($times);
    }
}
$ratio = round($median['comment_changed']['baseline'] / max(0.001, $median['comment_changed']['candidate']), 3);
$report = [
    'php' => PHP_VERSION,
    'head' => getenv('GITHUB_SHA') ?: 'local',
    'baseline_main_sha' => (string) (getenv('AGENT_MAP_AB_MAIN_SHA') ?: 'unknown'),
    'fixture' => 'single identical copy of actual agent-map src analysed by two independent package checkouts',
    'initial_graph_relations' => $initialRelations,
    'negative_relation_count' => $newRelations,
    'median_ms' => $median,
    'changed_source_speedup' => $ratio,
    'changed_source_saved_ms' => round($median['comment_changed']['baseline'] - $median['comment_changed']['candidate'], 3),
    'paired_runs' => $raw,
    'invariants' => [
        'same_complete_source_fixture' => true,
        'exact_canonical_byte_parity' => true,
        'exact_split_relations_byte_parity' => true,
        'graph_provenance_and_integrity_valid' => true,
        'real_semantic_change_still_updates_graph' => true,
    ],
    'limits' => [
        'Five paired rounds including one unchanged warm and four comment-changed; order alternates.',
        'Cold was measured once in each independent checkout, not a statistical median.',
        'A/B PHPStan cache directories are independent; Composer installs share lockfile but resolve to different absolute vendor locations.',
        'Only a single GitHub runner and source fixture per PHP version; benchmark is not a guaranteed user-wide speedup.',
        'No semantic shortcuts or special collector configuration; both checkouts run the actual CLI.',
    ],
];
$output = $root . '/semantic-export-ab-report.json';
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && $summary !== '') {
    $text = "## Real PHPStan exporter-sort A/B\n\n";
    $text .= '| Phase | Baseline median | Candidate median |' . "\n| --- | ---: | ---: |\n";
    foreach ($median as $phase => $numbers) {
        $text .= sprintf("| %s | %.3f ms | %.3f ms |\n", $phase, $numbers['baseline'], $numbers['candidate']);
    }
    $text .= "\nChanged source end-to-end ratio: **" . $ratio . "x**; saving: **" . $report['changed_source_saved_ms'] . " ms**.\n";
    $text .= "Both full semantic maps, split relations, graph provenance and negative changed-relation fallback: **exact parity**.\n";
    file_put_contents($summary, $text, FILE_APPEND);
}
