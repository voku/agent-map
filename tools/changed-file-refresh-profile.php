<?php

declare(strict_types=1);

/**
 * Experimental end-to-end changed-source refresh profiler.
 *
 * No product instrumentation or semantic shortcuts. Both arms analyse the
 * same actual source tree, with independent warm PHPStan caches. The first
 * arm reports non-overlapping builder timing using its public collaborators;
 * the second measures the actual MapPreparationService::refresh() path.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\SemanticAnalyzer;
use voku\AgentMap\Build\SemanticAnalysisResult;
use voku\AgentMap\Extract\ExtractResult;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Extract\SymbolExtractor;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapPreparationService;

final class SemanticTimer implements SemanticAnalyzer
{
    public float $milliseconds = 0.0;

    public function __construct(private SemanticAnalyzer $inner)
    {
    }

    public function backend(): string
    {
        return $this->inner->backend();
    }

    public function analyse(
        string $root,
        array $relativeFiles,
        ?string $configurationFile = null,
        ?string $memoryLimit = null,
        array $analyseDirectories = [],
        array $scanDirectories = [],
    ): SemanticAnalysisResult {
        $start = hrtime(true);
        try {
            return $this->inner->analyse($root, $relativeFiles, $configurationFile, $memoryLimit, $analyseDirectories, $scanDirectories);
        } finally {
            $this->milliseconds += (hrtime(true) - $start) / 1e6;
        }
    }
}

final class ExtractorTimer implements SymbolExtractor
{
    public float $milliseconds = 0.0;

    public function __construct(private SymbolExtractor $inner)
    {
    }

    public function extract(string $file): ExtractResult
    {
        $start = hrtime(true);
        try {
            return $this->inner->extract($file);
        } finally {
            $this->milliseconds += (hrtime(true) - $start) / 1e6;
        }
    }

    public function extractMany(array $files): array
    {
        $start = hrtime(true);
        try {
            return $this->inner->extractMany($files);
        } finally {
            $this->milliseconds += (hrtime(true) - $start) / 1e6;
        }
    }
}

/** @return array{0: float, 1: mixed} */
function measure(callable $operation): array
{
    $start = hrtime(true);
    $result = $operation();

    return [round((hrtime(true) - $start) / 1e6, 3), $result];
}

function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    return round($values[intdiv(count($values), 2)], 3);
}

function requireIdenticalArtifacts(string $left, string $right): void
{
    foreach ([$left, MapArtifactPaths::relationsFileFor($left)] as $file) {
        $other = $file === $left ? $right : MapArtifactPaths::relationsFileFor($right);
        $first = hash_file('sha256', $file);
        $second = hash_file('sha256', $other);
        if (!is_string($first) || !is_string($second) || !hash_equals($first, $second)) {
            throw new RuntimeException('The instrumented and real refresh maps differ: ' . basename($file));
        }
    }

    $graph = new MapGraphIndex();
    $firstStore = $graph->verifyCurrent($left);
    $secondStore = $graph->verifyCurrent($right);
    if ($firstStore->sourceRevision() !== $secondStore->sourceRevision()
        || $firstStore->sourceFingerprint() !== $secondStore->sourceFingerprint()
        || $firstStore->relationCount() !== $secondStore->relationCount()
        || $firstStore->relationCount() < 100) {
        throw new RuntimeException('The instrumented and real refresh graphs differ.');
    }
}

$root = realpath(dirname(__DIR__));
if (!is_string($root)) {
    throw new RuntimeException('Unable to find project root.');
}

$editedSource = $root . '/src/Index/MapGraphIndex.php';
$original = file_get_contents($editedSource);
if (!is_string($original)) {
    throw new RuntimeException('Unable to read changed-source fixture.');
}

$directory = rtrim((string) (getenv('RUNNER_TEMP') ?: sys_get_temp_dir()), '/') . '/map-refresh-profile-' . bin2hex(random_bytes(6));
$profileArtifacts = MapArtifactPaths::forProject($root, $directory . '/profile');
$refreshArtifacts = MapArtifactPaths::forProject($root, $directory . '/refresh');
foreach ([$directory . '/profile', $directory . '/refresh'] as $folder) {
    if (!mkdir($folder, 0775, true) && !is_dir($folder)) {
        throw new RuntimeException('Unable to create profile output.');
    }
}
$left = $profileArtifacts->indexJson();
$right = $refreshArtifacts->indexJson();
$semanticTimer = new SemanticTimer(new PhpStanSemanticAnalyzer($profileArtifacts));
$extractorTimer = new ExtractorTimer(new SimplePhpParserSymbolExtractor());
$profileBuilder = new AgentMapBuilder(
    extractor: $extractorTimer,
    semanticAnalyzer: $semanticTimer,
    artifacts: $profileArtifacts,
);
$refreshBuilder = new AgentMapBuilder(
    semanticAnalyzer: new PhpStanSemanticAnalyzer($refreshArtifacts),
    artifacts: $refreshArtifacts,
);
$writer = new IndexWriter();
$service = new MapPreparationService();
$request = new MapPreparationRequest(
    root: $root,
    indexPath: $right,
    outputPath: $right,
    format: 'json',
    paths: ['src'],
    pathsProvided: true,
    scanPaths: [],
    scanPathsProvided: false,
    excludes: [],
    excludesProvided: true,
    backend: 'phpstan',
    phpStanConfig: null,
    phpStanMemoryLimit: '512M',
    artifacts: $refreshArtifacts,
);

$rounds = [];
try {
    // Seed both independent caches and canonical artifacts before collecting
    // changed-source warm-run measurements.
    $writer->write($profileBuilder->build($root, ['src'], [], phpStanMemoryLimit: '512M'), $left);
    $writer->write($refreshBuilder->build($root, ['src'], [], phpStanMemoryLimit: '512M'), $right);
    requireIdenticalArtifacts($left, $right);

    [$noopMs, $noop] = measure(static fn () => $service->refresh($request));
    if ($noop->mutated || $noop->changedFiles !== 0) {
        throw new RuntimeException('No-change refresh unnecessarily rewrote the map.');
    }

    $previousDigest = (new MapGraphIndex())->verifyCurrent($left)->sourceRevision();
    for ($i = 0; $i < 3; ++$i) {
        $newContent = $original . "\n// profiler: changed source round " . $i . "\n";
        if (file_put_contents($editedSource, $newContent) === false) {
            throw new RuntimeException('Unable to change benchmark source file.');
        }
        clearstatcache(true, $editedSource);

        $semanticTimer->milliseconds = 0;
        $extractorTimer->milliseconds = 0;
        [$builderMs, $index] = measure(static fn () => $profileBuilder->build($root, ['src'], [], phpStanMemoryLimit: '512M'));
        [$writeMs] = measure(static fn () => $writer->write($index, $left));

        [$refreshMs, $refreshResult] = measure(static fn () => $service->refresh($request));
        if (!$refreshResult->mutated || $refreshResult->changedFiles !== 1) {
            throw new RuntimeException('Real refresh must detect exactly one modified PHP file.');
        }
        requireIdenticalArtifacts($left, $right);

        $revision = (new MapGraphIndex())->verifyCurrent($left)->sourceRevision();
        if ($revision === $previousDigest) {
            throw new RuntimeException('Changed source did not change graph source provenance.');
        }
        $previousDigest = $revision;

        [$postRefreshNoopMs, $postRefreshNoop] = measure(static fn () => $service->refresh($request));
        if ($postRefreshNoop->mutated) {
            throw new RuntimeException('Second refresh on unchanged source was not a no-op.');
        }
        $semanticMs = round($semanticTimer->milliseconds, 3);
        $extractMs = round($extractorTimer->milliseconds, 3);
        $rounds[] = [
            'round' => $i + 1,
            'changed_files' => $refreshResult->changedFiles,
            'real_refresh_ms' => $refreshMs,
            'profile_builder_ms' => $builderMs,
            'phpstan_analysis_ms' => $semanticMs,
            'structural_parser_ms' => $extractMs,
            'other_builder_ms' => round($builderMs - $semanticMs - $extractMs, 3),
            'canonical_and_sqlite_write_ms' => $writeMs,
            'no_change_refresh_ms' => $postRefreshNoopMs,
            'canonical_byte_parity' => true,
            'sqlite_graph_integrity' => true,
        ];
    }
} finally {
    file_put_contents($editedSource, $original);
}
$metrics = [];
foreach (['real_refresh_ms', 'profile_builder_ms', 'phpstan_analysis_ms', 'structural_parser_ms', 'other_builder_ms', 'canonical_and_sqlite_write_ms', 'no_change_refresh_ms'] as $name) {
    $metrics[$name] = median(array_column($rounds, $name));
}
$report = [
    'php' => PHP_VERSION,
    'head' => getenv('GITHUB_SHA') ?: 'local',
    'source' => 'real agent-map/src, PHPStan-backed changed-source refresh',
    'sample_count' => count($rounds),
    'initial_no_change_refresh_ms' => $noopMs,
    'medians' => $metrics,
    'rounds' => $rounds,
    'limits' => [
        'The profiled builder and public refresh have separate warmed caches; they run sequentially on identical sources.',
        'Builder residual is total builder time minus child semantic and structural extraction timers; it includes file discovery, source hashing, structural cache, reconciliation and object assembly.',
        'Canonical+SQLite write is measured separately and not added to end-to-end refresh time.',
        'The real refresh path checks stale entries and complete semantic scope, including cross-file PHPStan dependencies.',
        'Only a comment-only source change was benchmarked on this hosted runner; no semantic work was skipped.',
    ],
];
if (file_put_contents($directory . '/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    throw new RuntimeException('Unable to write profiler report.');
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && $summary !== '') {
    $lines = ["## Real changed-file refresh profiler", "", 'PHP ' . PHP_VERSION . '; three changed-source rounds with exact artifact and graph parity.', '', '| Measurement | Median (ms) |', '| --- | ---: |'];
    foreach ($metrics as $name => $value) {
        $lines[] = '| ' . $name . ' | ' . number_format($value, 3) . ' |';
    }
    $lines[] = '';
    $lines[] = 'Builder sub-stages do not overlap, but the builder, writer, and real-refresh runs are **separate measurements**. Do not add them together.';
    file_put_contents($summary, implode("\n", $lines) . "\n", FILE_APPEND);
}
