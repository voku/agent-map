<?php

declare(strict_types=1);

/**
 * Reproducible large-volume sorter memory envelope, no production mutations.
 * Separate workers for each mode, with alternating order and identical seeds.
 * Compare output digests before accepting any benchmark sample.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return array<string, mixed>
 */
function sample(string $mode, int $count, string $shape, int $seed): array
{
    $cmd = [PHP_BINARY, '-d', 'memory_limit=768M', __DIR__ . '/record-sort-memory-worker.php', $mode, (string) $count, $shape, (string) $seed];
    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        throw new RuntimeException('Unable to launch isolated sorter.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0) {
        throw new RuntimeException(sprintf('Sorter worker %s / %d / %s failed with %d: %s', $mode, $count, $shape, $exit, (string) $errors));
    }
    $data = json_decode((string) $output, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['mode'] ?? null) !== $mode || ($data['records'] ?? null) !== $count
        || ($data['shape'] ?? null) !== $shape || !is_string($data['ordered_sha256'] ?? null)
        || !is_numeric($data['sort_ms'] ?? null) || !is_int($data['sort_peak_logical_bytes'] ?? null)) {
        throw new RuntimeException('Sorter worker gave an invalid report.');
    }

    return $data;
}

/** @param list<float|int> $values */
function median(array $values): float
{
    sort($values, SORT_NUMERIC);
    return (float) $values[intdiv(count($values), 2)];
}

$cases = [
    ['records' => 5000, 'shape' => 'compact'],
    ['records' => 50000, 'shape' => 'compact'],
    ['records' => 180000, 'shape' => 'compact'],
    ['records' => 50000, 'shape' => 'wide'],
    ['records' => 180000, 'shape' => 'wide'],
];
$report = [];
foreach ($cases as $case) {
    $rows = [];
    for ($sampleIndex = 0; $sampleIndex < 3; ++$sampleIndex) {
        $seed = $sampleIndex + 1;
        $order = $sampleIndex % 2 === 0 ? ['legacy', 'native'] : ['native', 'legacy'];
        $pair = [];
        foreach ($order as $mode) {
            $pair[$mode] = sample($mode, $case['records'], $case['shape'], $seed);
        }
        if (!hash_equals($pair['legacy']['ordered_sha256'], $pair['native']['ordered_sha256'])) {
            throw new RuntimeException(sprintf(
                'Sorted artifact byte mismatch at %d %s seed %d: baseline %s, candidate %s',
                $case['records'], $case['shape'], $seed,
                $pair['legacy']['ordered_sha256'], $pair['native']['ordered_sha256'],
            ));
        }
        $rows[] = [
            'seed' => $seed,
            'order' => $order,
            'legacy' => $pair['legacy'],
            'native' => $pair['native'],
            'exact_ordered_sha256_parity' => true,
        ];
    }

    $summary = [];
    foreach (['legacy', 'native'] as $mode) {
        foreach ([
            'sort_ms',
            'sort_additional_peak_logical_bytes',
            'sort_additional_peak_allocated_bytes',
            'sort_peak_logical_bytes',
            'sort_peak_allocated_bytes',
        ] as $metric) {
            $summary[$mode][$metric] = median(array_map(
                static fn (array $row): float => (float) $row[$mode][$metric],
                $rows,
            ));
        }
    }
    $summary['native_speedup'] = round(
        $summary['legacy']['sort_ms'] / max(0.001, $summary['native']['sort_ms']),
        3,
    );
    $summary['native_additional_peak_logical_delta_mib'] = round(
        ($summary['native']['sort_additional_peak_logical_bytes'] - $summary['legacy']['sort_additional_peak_logical_bytes']) / 1048576,
        3,
    );
    $report[] = [
        'scenario' => $case,
        'summary' => $summary,
        'paired_samples' => $rows,
    ];

    fwrite(STDERR, sprintf(
        "%s %d records: legacy %.1f ms / +%.1f MiB, native %.1f ms / +%.1f MiB; speedup %.2fx, additional-peak delta %.1f MiB\n",
        $case['shape'],
        $case['records'],
        $summary['legacy']['sort_ms'],
        $summary['legacy']['sort_additional_peak_logical_bytes'] / 1048576,
        $summary['native']['sort_ms'],
        $summary['native']['sort_additional_peak_logical_bytes'] / 1048576,
        $summary['native_speedup'],
        $summary['native_additional_peak_logical_delta_mib'],
    ));
}
$output = [
    'php' => PHP_VERSION,
    'head_commit' => (string) (getenv('GITHUB_SHA') ?: 'local'),
    'sample_count' => 3,
    'all_ordered_json_sha256_parity' => true,
    'cases' => $report,
    'interpretation' => [
        'Three independent identical seeded pairs per scenario; pair order alternated.',
        'Each sample launches a fresh PHP process with memory_limit=768M. Each sorter receives identical synthetic records.',
        'The pre-sort baseline is collected after constructing records; memory_reset_peak_usage() resets the maximum immediately before sorting.',
        'Incremental sort peak is max(0, memory_get_peak_usage(false) - baseline memory_get_usage(false)).',
        'Large PHP memory allocator bucket costs are also recorded using memory_get_peak_usage(true).',
        'JSON hashing occurs strictly after sort memory samples; no JSON encoding peak is counted as sort memory.',
        'Synthetic records mimic collector fields but do not replace a real-project end-to-end memory measurement.',
        'Legacy implementation is the exact usort five-key comparator prior to PR #180; native is the production class.',
        'Only memory/time and byte parity are asserted; the experiment does not alter production code.',
    ],
];
$resultFile = rtrim((string) (getenv('RUNNER_TEMP') ?: sys_get_temp_dir()), '/') . '/record-sort-memory-report.json';
if (file_put_contents($resultFile, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    throw new RuntimeException('Unable to write benchmark report.');
}
echo json_encode([
    'php' => PHP_VERSION,
    'head_commit' => $output['head_commit'],
    'report_file' => $resultFile,
    'cases' => array_map(static fn (array $case): array => [
        'scenario' => $case['scenario'],
        'summary' => $case['summary'],
    ], $report),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

$summaryFile = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summaryFile) && $summaryFile !== '') {
    $text = "## Peak memory of PHPStan record sorting, PHP " . PHP_VERSION . "\n\n";
    $text .= "Three paired independent worker processes per scenario, exact ordered JSON hash parity.\n\n";
    $text .= "| Records / shape | Old sort ms | New sort ms | Speedup | Old peak extra MiB | New peak extra MiB | Difference MiB |\n";
    $text .= "| --- | ---: | ---: | ---: | ---: | ---: | ---: |\n";
    foreach ($report as $case) {
        $s = $case['summary'];
        $text .= sprintf(
            "| %d / %s | %.2f | %.2f | %.2fx | %.2f | %.2f | %+.2f |\n",
            $case['scenario']['records'], $case['scenario']['shape'],
            $s['legacy']['sort_ms'], $s['native']['sort_ms'], $s['native_speedup'],
            $s['legacy']['sort_additional_peak_logical_bytes'] / 1048576,
            $s['native']['sort_additional_peak_logical_bytes'] / 1048576,
            $s['native_additional_peak_logical_delta_mib'],
        );
    }
    $text .= "\nThese are **sort-only synthetic fixtures**, not full PHPStan process or end-to-end memory peaks. Native key-array allocation may trade RAM for CPU time.\n";
    file_put_contents($summaryFile, $text, FILE_APPEND);
}
