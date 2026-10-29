<?php

declare(strict_types=1);

/**
 * Research-only: baseline and candidate both execute actual installed agent-map
 * in independent PHP processes, against one identical fixture root.
 * Use with the matching GitHub Actions workflow. Never publish as product CLI.
 */

if (($argv[1] ?? '') === 'probe') {
    $install = (string) ($argv[2] ?? '');
    $root = (string) ($argv[3] ?? '');
    require $install . '/vendor/autoload.php';
    $files = (new \voku\AgentMap\IO\PhpFileFinder())->find($root, ['src']);
    $started = hrtime(true);
    $method = new ReflectionMethod(\voku\AgentMap\Build\PhpStanSemanticAnalyzer::class, 'excludedPaths');
    $excludes = $method->invoke(new \voku\AgentMap\Build\PhpStanSemanticAnalyzer(), $root, ['src'], $files);
    $ms = round((hrtime(true) - $started) / 1_000_000, 3);
    if (!is_array($excludes)) {
        throw new RuntimeException('Invalid PHPStan exclusions.');
    }
    echo json_encode(['ms' => $ms, 'count' => count($files), 'exclusions' => $excludes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$baseline = getenv('MAP_AB_BASELINE');
$candidate = getenv('MAP_AB_CANDIDATE');
$root = getenv('MAP_AB_FIXTURE');
if (!is_string($baseline) || !is_string($candidate) || !is_string($root)
    || !is_file($baseline . '/bin/agent-map') || !is_file($candidate . '/bin/agent-map')
    || !is_file($root . '/src/Index/MapGraphIndex.php')) {
    throw new RuntimeException('Missing benchmark checkout or shared fixture.');
}
$root = (string) realpath($root);
$installs = ['baseline' => $baseline, 'candidate' => $candidate];
$index = [
    'baseline' => $root . '/artifacts/baseline/php-symbols.json',
    'candidate' => $root . '/artifacts/candidate/php-symbols.json',
];
foreach ($index as $path) {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Unable to create independent artifact directory.');
    }
}

/** @return array{ms: float, count: int, exclusions: list<string>} */
function probe(string $install, string $root): array
{
    $command = [PHP_BINARY, __FILE__, 'probe', $install, $root];
    $data = command($command, $install);
    $json = json_decode($data['stdout'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($json) || !is_numeric($json['ms'] ?? null) || !is_array($json['exclusions'] ?? null)) {
        throw new RuntimeException('Invalid exclusion probe output.');
    }
    return $json;
}

/** @param list<string> $command
 * @return array{ms: float, stdout: string}
 */
function command(array $command, string $cwd): array
{
    $started = hrtime(true);
    $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to launch real PHP process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    if ($status !== 0) {
        throw new RuntimeException('Subprocess failed [' . $status . ']: ' . (string) $stderr . "\n" . (string) $stdout);
    }
    return ['ms' => round((hrtime(true) - $started) / 1_000_000, 3), 'stdout' => (string) $stdout];
}

/** @return float */
function build(string $install, string $root, string $out): float
{
    $cmd = [
        PHP_BINARY,
        $install . '/bin/agent-map',
        'build',
        '--root=' . $root,
        '--paths=src',
        '--backend=phpstan',
        '--out=' . $out,
        '--phpstan-memory-limit=512M',
    ];
    return command($cmd, $install)['ms'];
}

function assertParity(string $a, string $b): int
{
    require_once (string) getenv('MAP_AB_CANDIDATE') . '/vendor/autoload.php';
    foreach ([
        [$a, $b],
        [\voku\AgentMap\MapArtifactPaths::relationsFileFor($a), \voku\AgentMap\MapArtifactPaths::relationsFileFor($b)],
    ] as [$left, $right]) {
        $first = hash_file('sha256', $left);
        $second = hash_file('sha256', $right);
        if (!is_string($first) || !is_string($second) || !hash_equals($first, $second)) {
            $aJson = json_decode((string) file_get_contents($left), true);
            $bJson = json_decode((string) file_get_contents($right), true);
            if (is_array($aJson) && is_array($bJson)) {
                foreach (array_unique([...array_keys($aJson), ...array_keys($bJson)]) as $key) {
                    if (($aJson[$key] ?? null) !== ($bJson[$key] ?? null)) {
                        fwrite(STDERR, 'Mismatched canonical JSON key: ' . $key . "\n");
                    }
                }
            }
            throw new RuntimeException('Baseline and candidate output bytes differ: ' . basename($left));
        }
    }
    $graph = new \voku\AgentMap\Index\MapGraphIndex();
    $first = $graph->verifyCurrent($a);
    $second = $graph->verifyCurrent($b);
    if ($first->sourceRevision() !== $second->sourceRevision()
        || $first->sourceFingerprint() !== $second->sourceFingerprint()
        || $first->relationCount() !== $second->relationCount()
        || $first->relationCount() < 100) {
        throw new RuntimeException('SQLite provenance/graph integrity parity failed.');
    }
    return $first->relationCount();
}

/** @param list<float> $values */
function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    return round($values[intdiv(count($values), 2)], 3);
}

function growExcludedTree(string $root, int $from, int $to): void
{
    $roots = [
        $root . '/src/Module/vendor',
        $root . '/src/node_modules',
        $root . '/src/var/cache',
        $root . '/src/.git',
    ];
    for ($i = $from; $i < $to; ++$i) {
        $dir = $roots[$i % count($roots)] . '/group_' . intdiv($i, 2000);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create excluded fixture directory.');
        }
        $file = $dir . '/F_' . $i . '.php';
        if (file_put_contents($file, "<?php\n// Excluded PHP file " . $i . "\n") === false) {
            throw new RuntimeException('Failed to generate excluded fixture.');
        }
    }
}

$source = $root . '/src/Index/MapGraphIndex.php';
$original = (string) file_get_contents($source);
$report = [];
$generated = 0;
try {
    foreach ([0, 1000, 12000, 30000] as $size) {
        growExcludedTree($root, $generated, $size);
        $generated = $size;
        $probeTimes = ['baseline' => [], 'candidate' => []];
        $probed = [];
        // The baseline and candidate must generate EXACTLY the same PHPStan
        // exclusions on these nonempty default-excluded directories.
        for ($iteration = 0; $iteration < 3; ++$iteration) {
            $order = $iteration % 2 === 0 ? ['baseline', 'candidate'] : ['candidate', 'baseline'];
            foreach ($order as $mode) {
                $probed[$mode] = probe($installs[$mode], $root);
                $probeTimes[$mode][] = $probed[$mode]['ms'];
            }
            if ($probed['baseline']['exclusions'] !== $probed['candidate']['exclusions']
                || $probed['baseline']['count'] !== $probed['candidate']['count']) {
                throw new RuntimeException(
                    'Effective PHPStan exclude list changed at size ' . $size
                    . ': original=' . json_encode($probed['baseline']['exclusions'])
                    . ' candidate=' . json_encode($probed['candidate']['exclusions']),
                );
            }
        }
        $entry = [
            'excluded_php_files' => $size,
            'indexed_files' => $probed['baseline']['count'],
            'same_exact_phpstan_exclusion_list' => true,
            'exclude_paths_count' => count($probed['baseline']['exclusions']),
            'exclude_discovery_median_ms' => [
                'baseline' => median($probeTimes['baseline']),
                'candidate' => median($probeTimes['candidate']),
            ],
            'full_phpstan_builds' => [],
        ];
        // Full CLI proof on zero excluded files and both large stress sizes.
        // Each mode has independent warm cache and canonical index.
        if ($size === 0 || $size === 12000 || $size === 30000) {
            $samples = ['baseline' => [], 'candidate' => []];
            for ($iteration = 0; $iteration < 3; ++$iteration) {
                file_put_contents($source, $original . "\n// A/B changed indexed source: " . $size . '/' . $iteration . "\n");
                clearstatcache(true, $source);
                $order = $iteration % 2 === 0 ? ['baseline', 'candidate'] : ['candidate', 'baseline'];
                foreach ($order as $mode) {
                    $samples[$mode][] = build($installs[$mode], $root, $index[$mode]);
                }
                $entry['verified_graph_relations'] = assertParity($index['baseline'], $index['candidate']);
            }
            $entry['full_phpstan_builds'] = [
                'baseline_median_ms' => median($samples['baseline']),
                'candidate_median_ms' => median($samples['candidate']),
                'saved_ms' => round(median($samples['baseline']) - median($samples['candidate']), 3),
                'speedup' => round(median($samples['baseline']) / max(0.001, median($samples['candidate'])), 3),
                'all_canonical_and_graph_parity' => true,
            ];
        }
        $report[] = $entry;
        fwrite(STDERR, sprintf(
            "Excluded %d PHP: exclusion computation %.3f -> %.3f ms; complete-build saved %.3f ms\n",
            $size,
            $entry['exclude_discovery_median_ms']['baseline'],
            $entry['exclude_discovery_median_ms']['candidate'],
            $entry['full_phpstan_builds']['saved_ms'] ?? 0.0,
        ));
    }
} finally {
    file_put_contents($source, $original);
}
$document = [
    'php' => PHP_VERSION,
    'head_commit' => (string) (getenv('GITHUB_SHA') ?: 'local'),
    'fixture' => 'actual agent-map src copied to one shared root, 0/1k/12k/30k additional excluded PHP files',
    'independent_locked_package_installs' => true,
    'paired_samples' => 3,
    'all_exact_exclusion_lists_identical' => true,
    'all_canonical_byte_and_graph_parity' => true,
    'cases' => $report,
    'limitations' => [
        'Runs on one GitHub Actions host; timings are not universal.',
        'The alternate mode receives an independent PHPStan result cache in the same absolute fixture root.',
        'Three changed-source full-build pairs per measured size; order alternates.',
        'This tests prefix-closed default excludes only. Custom regex semantics have separate unit and existing integration tests.',
        'Synthetic excluded files are deliberately tiny, so filesystem metadata traversal dominates; real vendor trees may differ.',
    ],
];
$path = $root . '/exclusion-ab-report.json';
file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

if (is_string($summary = getenv('GITHUB_STEP_SUMMARY')) && $summary !== '') {
    $text = "## PHPStan excluded-subtree A/B: PHP " . PHP_VERSION . "\n\n";
    $text .= "| Excluded PHP | Previous exclusion pass | Pruned exclusion pass | Full CLI baseline | Full CLI candidate | Saved ms |\n";
    $text .= "| ---: | ---: | ---: | ---: | ---: | ---: |\n";
    foreach ($report as $entry) {
        $build = $entry['full_phpstan_builds'];
        $text .= sprintf(
            "| %d | %.3f ms | %.3f ms | %s | %s | %s |\n",
            $entry['excluded_php_files'],
            $entry['exclude_discovery_median_ms']['baseline'],
            $entry['exclude_discovery_median_ms']['candidate'],
            isset($build['baseline_median_ms']) ? $build['baseline_median_ms'] . ' ms' : 'not run',
            isset($build['candidate_median_ms']) ? $build['candidate_median_ms'] . ' ms' : 'not run',
            isset($build['saved_ms']) ? $build['saved_ms'] . ' ms' : 'not run',
        );
    }
    $text .= "\nExact PHPStan exclusion lists, canonical and split relation bytes, SQLite revision/fingerprint/integrity: **PASS**.\n";
    file_put_contents($summary, $text, FILE_APPEND);
}
