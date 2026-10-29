<?php

declare(strict_types=1);

/**
 * EXPERIMENT ONLY: GitHub runner PHPStan process attribution.
 *
 * Run 3 independent real CLI cold/warm/changed-source sequences with Xdebug
 * disabled, then profile a separate unchanged build with Xdebug's Cachegrind
 * output. Process profiling changes timings dramatically: never equate the
 * profile's inclusive times with non-profiled wall time.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\MapArtifactPaths;

const SAMPLE_COUNT = 3;

/** @return array{wall_ms: float, exit_code: int, output: string} */
function execute(string $root, string $indexFile, bool $profile, string $profileDirectory): array
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
    $environment = getenv();
    if (!is_array($environment)) {
        throw new RuntimeException('Unable to read process environment.');
    }
    $environment['XDEBUG_MODE'] = $profile ? 'profile' : 'off';
    if ($profile) {
        $environment['PHPSTAN_ALLOW_XDEBUG'] = '1';
        $environment['XDEBUG_CONFIG'] = 'output_dir=' . $profileDirectory;
    }
    $pipes = [];
    $started = hrtime(true);
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start agent-map CLI.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $elapsed = round((hrtime(true) - $started) / 1000000, 3);
    $out = (string) $stdout . "\n" . (string) $stderr;
    if ($status !== 0) {
        throw new RuntimeException('Real agent-map CLI failed (' . $status . '): ' . $out);
    }

    return ['wall_ms' => $elapsed, 'exit_code' => $status, 'output' => $out];
}

function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    return round($values[intdiv(count($values), 2)], 3);
}

function exactFiles(string $a, string $b): void
{
    foreach ([$a, MapArtifactPaths::relationsFileFor($a)] as $file) {
        $other = $file === $a ? $b : MapArtifactPaths::relationsFileFor($b);
        $x = hash_file('sha256', $file);
        $y = hash_file('sha256', $other);
        if (!is_string($x) || !is_string($y) || !hash_equals($x, $y)) {
            throw new RuntimeException('Real CLI canonical/relations byte parity failure.');
        }
    }
}

/** @return array{revision: string, count: int, fingerprint: string} */
function verifiedGraph(string $index): array
{
    $store = (new MapGraphIndex())->verifyCurrent($index);
    $revision = $store->sourceRevision();
    $fingerprint = $store->sourceFingerprint();
    if (!is_string($revision) || !is_string($fingerprint) || $store->relationCount() < 100) {
        throw new RuntimeException('Graph provenance/relation count invalid.');
    }

    return ['revision' => $revision, 'count' => $store->relationCount(), 'fingerprint' => $fingerprint];
}

/**
 * Parse Xdebug Cachegrind *streaming*, keeping only time costs. The exclusive
 * cost is per-function self time; call edge costs are inclusive and MUST NOT
 * be added to ancestor call costs. PHPStan can produce several subprocess
 * profiles due to its own restart mechanism.
 *
 * @return array{file: string, summary_time_us: int, function_self_us: array<string, int>, call_inclusive_us: array<string, int>, traces: int}
 */
function parseCachegrind(string $path): array
{
    $stream = fopen($path, 'rb');
    if (!is_resource($stream)) {
        throw new RuntimeException('Cannot open Cachegrind file.');
    }
    $names = [];
    $calleeNames = [];
    $current = null;
    $callee = null;
    $edgePending = false;
    $self = [];
    $edges = [];
    $total = 0;
    $events = null;
    $eventsTime = -1;
    try {
        while (($line = fgets($stream)) !== false) {
            $line = trim($line);
            if (str_starts_with($line, 'events: ')) {
                $events = preg_split('/\s+/', substr($line, 8)) ?: [];
                $idx = array_search('Time', $events, true);
                $eventsTime = is_int($idx) ? $idx : -1;
                continue;
            }
            if (str_starts_with($line, 'summary:')) {
                $columns = preg_split('/\s+/', trim(substr($line, 8))) ?: [];
                if ($eventsTime >= 0) {
                    $total += (int) ($columns[$eventsTime] ?? 0);
                }
                continue;
            }
            if (str_starts_with($line, 'fn=')) {
                $raw = substr($line, 3);
                if (preg_match('/^\((\d+)\)\s*(.*)$/', $raw, $m) === 1) {
                    $current = $m[2] !== '' ? $m[2] : ($names[$m[1]] ?? null);
                    if ($m[2] !== '') {
                        $names[$m[1]] = $m[2];
                    }
                } else {
                    $current = $raw;
                }
                $callee = null;
                $edgePending = false;
                continue;
            }
            if (str_starts_with($line, 'cfn=')) {
                $raw = substr($line, 4);
                if (preg_match('/^\((\d+)\)\s*(.*)$/', $raw, $m) === 1) {
                    $callee = $m[2] !== '' ? $m[2] : ($calleeNames[$m[1]] ?? $names[$m[1]] ?? null);
                    if ($m[2] !== '') {
                        $calleeNames[$m[1]] = $m[2];
                    }
                } else {
                    $callee = $raw;
                }
                $edgePending = false;
                continue;
            }
            if (str_starts_with($line, 'calls=')) {
                $edgePending = true;
                continue;
            }
            if ($eventsTime < 0 || preg_match('/^(?:\d+|\+\d+|\-\d+|\*)\s+(.+)$/', $line, $m) !== 1) {
                continue;
            }
            $columns = preg_split('/\s+/', $m[1]) ?: [];
            $value = (int) ($columns[$eventsTime] ?? 0);
            if ($edgePending) {
                if (is_string($callee)) {
                    $edges[$callee] = ($edges[$callee] ?? 0) + $value;
                }
                $edgePending = false;
            } elseif (is_string($current)) {
                $self[$current] = ($self[$current] ?? 0) + $value;
            }
        }
    } finally {
        fclose($stream);
    }
    if ($eventsTime < 0 || $total === 0) {
        throw new RuntimeException('Missing Cachegrind Time events or summary: ' . basename($path));
    }

    return [
        'file' => basename($path),
        'summary_time_us' => $total,
        'function_self_us' => $self,
        'call_inclusive_us' => $edges,
        'traces' => count($self),
    ];
}

function summarizeProfiles(string $directory): array
{
    $list = glob($directory . '/cachegrind.out.*') ?: [];
    if ($list === []) {
        throw new RuntimeException('Xdebug produced no Cachegrind traces.');
    }
    $profiles = [];
    foreach ($list as $path) {
        $trace = parseCachegrind($path);
        $functions = array_keys($trace['function_self_us'] + $trace['call_inclusive_us']);
        $isPhpStan = false;
        foreach ($functions as $fn) {
            if (str_contains($fn, 'ResultCacheManager') || str_contains($fn, 'AnalyseApplication')) {
                $isPhpStan = true;
                break;
            }
        }
        if (!$isPhpStan) {
            continue;
        }
        $targets = [
            'cache_restore' => 'ResultCacheManager->restore',
            'cache_process' => 'ResultCacheManager->process',
            'phpstan_analyse_app' => 'AnalyseApplication->analyse',
            'phpstan_analyser' => 'Analyser->analyse',
            'cache_save' => 'ResultCacheManager->save',
            'collected_map_export' => 'CollectedMapExportRule->processNode',
        ];
        $selected = [];
        foreach ($targets as $label => $needle) {
            $selected[$label] = [
                'inclusive_us' => 0,
                'exclusive_us' => 0,
                'matched_functions' => [],
            ];
            foreach ($functions as $name) {
                if (!str_contains($name, $needle)) {
                    continue;
                }
                $selected[$label]['inclusive_us'] += (int) ($trace['call_inclusive_us'][$name] ?? 0);
                $selected[$label]['exclusive_us'] += (int) ($trace['function_self_us'][$name] ?? 0);
                $selected[$label]['matched_functions'][] = $name;
            }
        }
        $profiles[] = [
            'file' => $trace['file'],
            'total_profiled_time_us' => $trace['summary_time_us'],
            'functions' => $selected,
            'unique_functions' => $trace['traces'],
        ];
    }
    if ($profiles === []) {
        throw new RuntimeException('Profiler observed only CLI parent, not PHPStan analyser.');
    }

    return [
        'all_xdebug_profile_files' => count($list),
        'phpstan_process_profiles' => $profiles,
        'warning' => 'Cachegrind inclusive calls may overlap other functions; do not sum them. Xdebug instrumented cost is not normal wall time.',
    ];
}

$root = realpath(dirname(__DIR__));
if (!is_string($root)) {
    throw new RuntimeException('Missing repository root.');
}
$source = $root . '/src/Index/MapGraphIndex.php';
$original = file_get_contents($source);
if (!is_string($original)) {
    throw new RuntimeException('Missing editable source fixture.');
}
$work = rtrim((string) (getenv('RUNNER_TEMP') ?: sys_get_temp_dir()), '/') . '/phpstan-process-probe-' . bin2hex(random_bytes(5));
$profileDir = $work . '/cachegrind';
if (!mkdir($profileDir, 0775, true) && !is_dir($profileDir)) {
    throw new RuntimeException('Unable to create profiler directory.');
}
$phases = ['cold' => [], 'warm' => [], 'changed' => []];
$sampleReports = [];
try {
    for ($sample = 0; $sample < SAMPLE_COUNT; ++$sample) {
        $out = $work . '/sample-' . $sample . '/php-symbols.json';
        if (!mkdir(dirname($out), 0775, true) && !is_dir(dirname($out))) {
            throw new RuntimeException('Unable to create independent cache directory.');
        }
        file_put_contents($source, $original);
        clearstatcache(true, $source);

        $cold = execute($root, $out, false, $profileDir);
        $coldGraph = verifiedGraph($out);
        $coldCanonical = $out . '.baseline';
        $coldRelations = MapArtifactPaths::relationsFileFor($out) . '.baseline';
        copy($out, $coldCanonical);
        copy(MapArtifactPaths::relationsFileFor($out), $coldRelations);

        $warm = execute($root, $out, false, $profileDir);
        if (hash_file('sha256', $out) !== hash_file('sha256', $coldCanonical)
            || hash_file('sha256', MapArtifactPaths::relationsFileFor($out)) !== hash_file('sha256', $coldRelations)
            || verifiedGraph($out) !== $coldGraph) {
            throw new RuntimeException('Cold-to-warm semantic output/graph parity failed.');
        }
        $changedContent = $original . "\n// PHPStan profiler-only changed-source round " . $sample . "\n";
        file_put_contents($source, $changedContent);
        clearstatcache(true, $source);
        $changed = execute($root, $out, false, $profileDir);
        $changedGraph = verifiedGraph($out);
        if ($changedGraph['count'] !== $coldGraph['count'] || $changedGraph['revision'] === $coldGraph['revision']) {
            throw new RuntimeException('Changed source did not change revision or unexpectedly changed relations.');
        }
        foreach (['cold' => $cold, 'warm' => $warm, 'changed' => $changed] as $phase => $result) {
            $phases[$phase][] = $result['wall_ms'];
        }
        $sampleReports[] = [
            'sample' => $sample + 1,
            'cold_ms' => $cold['wall_ms'],
            'warm_ms' => $warm['wall_ms'],
            'changed_ms' => $changed['wall_ms'],
            'relations' => $changedGraph['count'],
            'stable_warm_canonical_and_graph' => true,
            'changed_revision_and_verified_graph' => true,
        ];

        if ($sample === 0) {
            // A separate already-warm pass under Xdebug. Do not include in
            // production timing samples. Check byte-for-byte parity afterward.
            $baselineJson = file_get_contents($out);
            $baselineRelationsJson = file_get_contents(MapArtifactPaths::relationsFileFor($out));
            $beforeMap = hash_file('sha256', $out);
            $beforeRelations = hash_file('sha256', MapArtifactPaths::relationsFileFor($out));
            $profiled = execute($root, $out, true, $profileDir);
            $profiledGraph = verifiedGraph($out);
            // Enabling Xdebug makes PHPStan print exactly three additional
            // advisory notes. They become diagnostics and therefore legitimately
            // change the canonical JSON byte fingerprint. Only those exact
            // messages may be removed before comparing all semantic fields.
            $baseline = json_decode((string) $baselineJson, true, 512, JSON_THROW_ON_ERROR);
            $after = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($baseline) || !is_array($after) || !is_array($after['diagnostics'] ?? null)) {
                throw new RuntimeException('Xdebug experiment canonical JSON is invalid.');
            }
            $knownNotices = [
                '! [NOTE] The Xdebug PHP extension is active, but "--xdebug" is not used. This',
                '!        may slow down performance and the process will not halt at',
                '!        breakpoints.',
            ];
            $kept = [];
            $ignored = [];
            foreach ($after['diagnostics'] as $diagnostic) {
                $text = $diagnostic['message'] ?? null;
                if (is_string($text) && in_array($text, $knownNotices, true)) {
                    if (($diagnostic['kind'] ?? null) !== 'phpstan_finding'
                        || ($diagnostic['file'] ?? null) !== null
                        || ($diagnostic['line'] ?? null) !== null) {
                        throw new RuntimeException('Xdebug note has surprising provenance.');
                    }
                    $ignored[] = $text;
                } else {
                    $kept[] = $diagnostic;
                }
            }
            if (count($ignored) !== 3 || count(array_unique($ignored)) !== 3) {
                throw new RuntimeException('Unexpected Xdebug diagnostic shape: ' . json_encode($after['diagnostics']));
            }
            $after['diagnostics'] = $kept;
            if ($baseline !== $after
                || hash_file('sha256', MapArtifactPaths::relationsFileFor($out)) !== $beforeRelations
                || $profiledGraph['count'] !== $changedGraph['count']) {
                throw new RuntimeException('Xdebug changed real semantic evidence beyond its three verified notes.');
            }
        }
    }
} finally {
    file_put_contents($source, $original);
}

$medianTimes = [];
foreach ($phases as $phase => $values) {
    $medianTimes[$phase . '_wall_ms'] = median($values);
}

$profiles = summarizeProfiles($profileDir);
$report = [
    'php' => PHP_VERSION,
    'head' => getenv('GITHUB_SHA') ?: 'local',
    'composer_lock_sha256' => hash_file('sha256', $root . '/composer.lock'),
    'three_independent_real_builds' => $sampleReports,
    'unprofiled_medians' => $medianTimes,
    'xdebug_profiling' => $profiles,
    'limits' => [
        'Cold -> unchanged warm -> comment-only changed sequential phases are required to create valid cache states, not randomized A/B.',
        'All runs use the real public bin/agent-map CLI, PHPStan and the stable complete src directory scope.',
        'Xdebug profiler was enabled only in its own additional already-warm CLI run; it changes execution costs and cannot directly predict optimization savings.',
        'The PHPStan subprocess can restart (including for Turbo); only processes with PHPStan cache/analyser symbols are classified as PHPStan.',
        'Cachegrind self time and inclusive call-edge costs answer different questions and are not additive.',
        'This trace identifies attributable functions; it cannot alone distinguish config bootstrap from overall process start.',
        'No production source semantics, PHPStan config or cache invalidation rules were modified.',
    ],
];
$reportPath = $work . '/report.json';
if (file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    throw new RuntimeException('Unable to save profiling report.');
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && $summary !== '') {
    $lines = ['## Real PHPStan process attribution', '', 'PHP ' . PHP_VERSION . '. Three independent cold / warm / changed-source CLI sequences.', '', '| Unprofiled full build | Median (ms) |', '| --- | ---: |'];
    foreach ($medianTimes as $key => $value) {
        $lines[] = '| ' . $key . ' | ' . number_format($value, 3) . ' |';
    }
    $lines[] = '';
    $lines[] = 'Xdebug profile is a separate warm run; its function costs are **not comparable to unprofiled wall time**.';
    $lines[] = '';
    foreach ($profiles['phpstan_process_profiles'] as $profile) {
        $lines[] = '### ' . $profile['file'] . ': profiled time ' . $profile['total_profiled_time_us'] . ' μs';
        $lines[] = '| PHPStan function | Inclusive call μs | Exclusive self μs |';
        $lines[] = '| --- | ---: | ---: |';
        foreach ($profile['functions'] as $key => $item) {
            $lines[] = '| ' . $key . ' | ' . $item['inclusive_us'] . ' | ' . $item['exclusive_us'] . ' |';
        }
        $lines[] = '';
    }
    $lines[] = 'All canonical maps, relations and graph provenance were verified; profiler-induced semantic change is a hard failure.';
    file_put_contents($summary, implode("\n", $lines) . "\n", FILE_APPEND);
}
