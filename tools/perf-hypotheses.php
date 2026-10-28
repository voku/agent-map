<?php

declare(strict_types=1);

/**
 * Disposable CI experiment, not production replacement code.
 *
 * Benchmarks the current file finder against a default-exclusion-pruning
 * candidate, then times actual agent-map stages and a no-op refresh.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use RuntimeException;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\IO\PhpFileFinder;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapPreparationService;

function timed(callable $operation): array
{
    $start = hrtime(true);
    $value = $operation();

    return ['ms' => round((hrtime(true) - $start) / 1_000_000, 3), 'value' => $value];
}

function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    $n = count($values);
    return round($n % 2 === 0 ? ($values[$n / 2 - 1] + $values[$n / 2]) / 2 : $values[intdiv($n, 2)], 3);
}

/**
 * Candidate only prunes the four fixed default exclusions.
 * Custom patterns still operate on individual files. Pruning arbitrary
 * user regexes is UNSAFE (a regex matching the directory may not match
 * descendants), so it is deliberately not part of this experiment.
 *
 * @param list<string> $paths
 * @param list<string> $excludes
 * @return list<string>
 */
function prunedFind(string $root, array $paths, array $excludes): array
{
    $root = str_replace('\\', '/', (string) realpath($root));
    $patterns = [
        '~(^|/)vendor(/|$)~',
        '~(^|/)\\.git(/|$)~',
        '~(^|/)node_modules(/|$)~',
        '~(^|/)var/cache(/|$)~',
        ...$excludes,
    ];
    $files = [];
    foreach ($paths === [] ? ['.'] : $paths as $path) {
        $absolute = realpath($path === '.' ? $root : $root . '/' . trim(str_replace('\\', '/', $path), '/'));
        if (!is_string($absolute) || ($absolute !== $root && !str_starts_with($absolute, $root . '/'))) {
            continue;
        }
        $items = [];
        if (is_file($absolute)) {
            $items[] = $absolute;
        } elseif (is_dir($absolute)) {
            $filter = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
                static function (SplFileInfo $item): bool {
                    if (!$item->isDir()) {
                        return true;
                    }
                    $name = $item->getFilename();
                    if ($name === 'vendor' || $name === '.git' || $name === 'node_modules') {
                        return false;
                    }
                    return !($name === 'cache' && basename($item->getPath()) === 'var');
                },
            );
            foreach (new RecursiveIteratorIterator($filter) as $item) {
                if ($item->isFile()) {
                    $items[] = $item->getPathname();
                }
            }
        }
        foreach ($items as $pathName) {
            $real = realpath($pathName);
            if (!is_string($real)) {
                continue;
            }
            $real = str_replace('\\', '/', $real);
            if (!str_starts_with($real, $root . '/') || !str_ends_with($real, '.php')) {
                continue;
            }
            $relative = substr($real, strlen($root) + 1);
            $excluded = false;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $relative) === 1 || preg_match($pattern, $real) === 1) {
                    $excluded = true;
                    break;
                }
            }
            if (!$excluded) {
                $files[$relative] = $relative;
            }
        }
    }
    sort($files);
    return array_values($files);
}

$project = (string) realpath(dirname(__DIR__));
$temporary = rtrim(getenv('RUNNER_TEMP') ?: sys_get_temp_dir(), '/') . '/agent-map-perf-' . getmypid();
$fixture = $temporary . '/fixture';
mkdir($fixture . '/src/Service', 0775, true);
mkdir($fixture . '/tests', 0775, true);
foreach (['vendor', 'node_modules', '.git', 'var/cache'] as $ignored) {
    mkdir($fixture . '/' . $ignored . '/nested', 0775, true);
    for ($i = 0; $i < 4000; ++$i) {
        file_put_contents($fixture . '/' . $ignored . '/nested/' . $i . '.php', '<?php // excluded');
    }
}
for ($i = 0; $i < 300; ++$i) {
    file_put_contents($fixture . '/src/Service/C' . $i . '.php', '<?php class C' . $i . ' {}');
}
file_put_contents($fixture . '/tests/RunTest.php', '<?php class RunTest {}');
file_put_contents($fixture . '/src/skip.php', '<?php class Skip {}');
mkdir($fixture . '/src/nested/vendor', 0775, true);
file_put_contents($fixture . '/src/nested/vendor/excluded.php', '<?php class Excluded {}');
file_put_contents($fixture . '/src/vendor-like.php', '<?php class Included {}');
file_put_contents($fixture . '/src/Service/Exact.php', '<?php class Exact {}');
@symlink('/etc/passwd', $fixture . '/src/outside.php');

$finder = new PhpFileFinder();
$scenarios = [
    'whole-tree' => [['.'], []],
    'source-only' => [['src', 'tests'], []],
    'custom-regex' => [['.'], ['~(^|/)skip[.]php$~']],
    'directory-anchored-regex' => [['.'], ['~^src/Service$~']],
    'overlapping-scope' => [['src', 'src/Service'], []],
];
$discovery = [];
foreach ($scenarios as $label => [$paths, $excludes]) {
    $baselineTimes = [];
    $candidateTimes = [];
    for ($round = 0; $round < 8; ++$round) {
        if ($round % 2 === 0) {
            $baseline = timed(static fn (): array => $finder->find($fixture, $paths, $excludes));
            $candidate = timed(static fn (): array => prunedFind($fixture, $paths, $excludes));
        } else {
            $candidate = timed(static fn (): array => prunedFind($fixture, $paths, $excludes));
            $baseline = timed(static fn (): array => $finder->find($fixture, $paths, $excludes));
        }
        if ($baseline['value'] !== $candidate['value']) {
            throw new RuntimeException('Directory pruning changed file selection in scenario: ' . $label);
        }
        $baselineTimes[] = $baseline['ms'];
        $candidateTimes[] = $candidate['ms'];
    }
    $before = median($baselineTimes);
    $after = median($candidateTimes);
    $discovery[$label] = [
        'files' => count($baseline['value']),
        'baseline_median_ms' => $before,
        'pruned_median_ms' => $after,
        'speedup' => round($before / max($after, 0.001), 2),
        'parity' => true,
    ];
}

// Real project: time the public extraction/build/write/read/refresh stages
// separately. These timings include different subsets of work, and MUST NOT
// be added together as though they formed one single invocation.
$paths = ['src'];
$artifactPaths = MapArtifactPaths::forProject($project, $temporary . '/actual');
$indexPath = $artifactPaths->indexJson();
$discovered = timed(static fn (): array => $finder->find($project, $paths, []));
$hashed = timed(static function () use ($project, $discovered): int {
    foreach ($discovered['value'] as $path) {
        if (!is_string(hash_file('sha256', $project . '/' . $path))) {
            throw new RuntimeException('Hash failure.');
        }
    }
    return count($discovered['value']);
});
$parsed = timed(static function () use ($project, $discovered): int {
    $files = array_map(static fn (string $path): string => $project . '/' . $path, $discovered['value']);
    $results = (new SimplePhpParserSymbolExtractor())->extractMany($files);
    foreach ($results as $result) {
        if (!$result->ok) {
            throw new RuntimeException('Structural parser failed: ' . $result->error);
        }
    }
    return count($results);
});
$structuralBuilder = new AgentMapBuilder(
    semanticAnalyzer: new StructuralOnlySemanticAnalyzer(),
    artifacts: $artifactPaths,
);
$structural = timed(static fn () => $structuralBuilder->build($project, $paths, []));
$writer = new IndexWriter();
$structuralWrite = timed(static function () use ($writer, $structural, $indexPath): void {
    $writer->write($structural['value'], $indexPath);
});

$semanticBuilder = new AgentMapBuilder(
    semanticAnalyzer: new PhpStanSemanticAnalyzer($artifactPaths),
    artifacts: $artifactPaths,
);
$semanticCold = timed(static fn () => $semanticBuilder->build($project, $paths, []));
$semanticWarm = timed(static fn () => $semanticBuilder->build($project, $paths, []));
if ($semanticCold['value']->toArray() !== $semanticWarm['value']->toArray()) {
    throw new RuntimeException('PHPStan cold/warm semantic map mismatch.');
}
$semanticWrite = timed(static function () use ($writer, $semanticWarm, $indexPath): void {
    $writer->write($semanticWarm['value'], $indexPath);
});
$graphRebuild = timed(static function () use ($indexPath, $semanticWarm): void {
    (new MapGraphIndex())->rebuild($semanticWarm['value'], $indexPath);
});
$loaded = timed(static fn () => (new IndexReader())->readForRoot($indexPath, $project));
$freshnessSamples = [];
$querySamples = [];
for ($i = 0; $i < 7; ++$i) {
    $check = timed(static fn () => $loaded['value']->staleEntries());
    if ($check['value'] !== []) {
        throw new RuntimeException('Unmodified project has stale entries.');
    }
    $freshnessSamples[] = $check['ms'];
    $querySamples[] = timed(static fn () => $loaded['value']->query('Map'))['ms'];
}
$request = new MapPreparationRequest(
    root: $project,
    indexPath: $indexPath,
    outputPath: $indexPath,
    format: 'json',
    paths: ['src'],
    pathsProvided: true,
    scanPaths: [],
    scanPathsProvided: false,
    excludes: [],
    excludesProvided: true,
    backend: 'phpstan',
    phpStanConfig: null,
    phpStanMemoryLimit: null,
    artifacts: $artifactPaths,
);
$noop = timed(static fn () => (new MapPreparationService())->refresh($request));
if ($noop['value']->mutated) {
    throw new RuntimeException('Unchanged refresh unexpectedly rebuilt the map.');
}

$report = [
    'php' => PHP_VERSION,
    'source_files' => count($discovered['value']),
    'relations' => count($semanticWarm['value']->relations),
    'synthetic_excluded_files' => 16000,
    'discovery' => $discovery,
    'stages_ms' => [
        'real_discovery' => $discovered['ms'],
        'sha256_all_sources' => $hashed['ms'],
        'extract_all_structural' => $parsed['ms'],
        'structural_full_build' => $structural['ms'],
        'structural_write_including_sqlite' => $structuralWrite['ms'],
        'phpstan_semantic_cold_builder' => $semanticCold['ms'],
        'phpstan_semantic_warm_builder' => $semanticWarm['ms'],
        'phpstan_write_including_sqlite' => $semanticWrite['ms'],
        'sqlite_rebuild_only' => $graphRebuild['ms'],
        'index_read_with_relations' => $loaded['ms'],
        'repeated_sha256_freshness_median' => median($freshnessSamples),
        'in_memory_exact_query_median' => median($querySamples),
        'no_change_refresh' => $noop['ms'],
    ],
    'no_change_refresh_mutated' => $noop['value']->mutated,
    'peak_php_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 2),
    'limits' => [
        'Synthetic directory pruning is an experimental function, not production code.',
        'Stage measurements overlap and cannot be added to represent a single build.',
        'Single CI runner and single fixture are not general performance guarantees.',
        'Filename/mtime checks alone are not equivalent to content-hash freshness guarantees.',
    ],
];
$output = $temporary . '/report.json';
file_put_contents($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
$summaryPath = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summaryPath) && $summaryPath !== '') {
    $markdown = "## agent-map performance hypotheses\n\n";
    $markdown .= "PHP " . PHP_VERSION . "; real source files: " . $report['source_files'] . "; relations: " . $report['relations'] . ".\n\n";
    $markdown .= "| Discovery scenario | Baseline median ms | Pruned median ms | Speedup | Same output |\n";
    $markdown .= "| --- | ---: | ---: | ---: | --- |\n";
    foreach ($discovery as $label => $result) {
        $markdown .= "| " . $label . " | " . $result['baseline_median_ms'] . " | " . $result['pruned_median_ms'] . " | " . $result['speedup'] . "x | yes |\n";
    }
    $markdown .= "\n| Real-project operation | Milliseconds |\n| --- | ---: |\n";
    foreach ($report['stages_ms'] as $name => $ms) {
        $markdown .= "| " . $name . " | " . $ms . " |\n";
    }
    $markdown .= "\n**Important:** Stage timings overlap; no-change refresh already skips full analysis; the pruning candidate was only tested in a disposable script.\n";
    file_put_contents($summaryPath, $markdown, FILE_APPEND);
}
