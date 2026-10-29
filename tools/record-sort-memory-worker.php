<?php

declare(strict_types=1);

/**
 * Isolated worker for the synthetic collector-record scale A/B.
 *
 * Invoked separately for each arm to prevent PHP allocator peak retention from
 * contaminating the other arm. No production code changes are needed.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentMap\PhpStan\CollectedRecordSorter;

if ($argc !== 5) {
    fwrite(STDERR, "Usage: worker.php <legacy|native> <records> <compact|wide> <seed>\n");
    exit(2);
}

[, $mode, $countArg, $shape, $seedArg] = $argv;
$count = filter_var($countArg, FILTER_VALIDATE_INT);
$seed = filter_var($seedArg, FILTER_VALIDATE_INT);
if (!in_array($mode, ['legacy', 'native'], true)
    || !in_array($shape, ['compact', 'wide'], true)
    || !is_int($count) || $count < 2 || $count > 300000
    || !is_int($seed) || $seed < 0 || $seed > 10000) {
    throw new InvalidArgumentException('Invalid benchmark worker parameters.');
}

$types = ['class', 'method', 'function', 'call', 'property', 'assign', 'return'];
$records = [];
$recordWidth = $shape === 'wide' ? 192 : 8;

for ($i = 0; $i < $count; ++$i) {
    // Each mode/seed reproduces the same synthetic records, including ties,
    // numeric-looking strings, deliberately missing keys, and long payloads.
    $index = ($i * 17 + $seed * 29) % 4096;
    $record = [
        'record_type' => $types[$index % count($types)],
        'file' => sprintf('src/Module_%03d/Class_%04d.php', $index % 97, $index % 1009),
        'line_start' => ($index * 11) % 307,
        'name' => $index % 29 === 0 ? (string) ($index % 13) : 'member_' . ($index % 311),
        'source_id' => 'symbol:' . $index % 2047,
        'details' => str_repeat(chr(65 + $index % 26), $recordWidth),
        'index' => $i,
    ];
    if ($i % 19 === 0) {
        unset($record['name']);
        $record['kind'] = 'fallback_' . ($index % 13);
    }
    if ($i % 23 === 0) {
        unset($record['source_id']);
    }
    if ($i % 17 === 0) {
        unset($record['line_start']);
    }
    $records[] = $record;
}

$baseLogical = memory_get_usage(false);
$baseAllocated = memory_get_usage(true);
if (!function_exists('memory_reset_peak_usage')) {
    throw new RuntimeException('PHP 8.3+ memory_reset_peak_usage() required.');
}
memory_reset_peak_usage();
$started = hrtime(true);

if ($mode === 'native') {
    $records = CollectedRecordSorter::sort($records);
} else {
    usort($records, static function (array $left, array $right): int {
        return ((string) $left['record_type']) <=> ((string) $right['record_type'])
            ?: ((string) ($left['file'] ?? '')) <=> ((string) ($right['file'] ?? ''))
            ?: ((int) ($left['line_start'] ?? 0)) <=> ((int) ($right['line_start'] ?? 0))
            ?: ((string) ($left['name'] ?? $left['kind'] ?? '')) <=> ((string) ($right['name'] ?? $right['kind'] ?? ''))
            ?: ((string) ($left['source_id'] ?? '')) <=> ((string) ($right['source_id'] ?? ''));
    });
}

$elapsedMs = (hrtime(true) - $started) / 1_000_000;
$sortPeakLogical = memory_get_peak_usage(false);
$sortPeakAllocated = memory_get_peak_usage(true);
$afterSortLogical = memory_get_usage(false);
$afterSortAllocated = memory_get_usage(true);
if (count($records) !== $count) {
    throw new RuntimeException('Sorter lost records.');
}

// Important: serialize only AFTER capturing sort-specific peak memory.
// JSON encoding itself can otherwise dominate a "sort peak" measurement.
$encoded = json_encode($records, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$result = [
    'php' => PHP_VERSION,
    'mode' => $mode,
    'shape' => $shape,
    'records' => $count,
    'seed' => $seed,
    'sort_ms' => round($elapsedMs, 3),
    'baseline_logical_bytes' => $baseLogical,
    'sort_peak_logical_bytes' => $sortPeakLogical,
    'sort_additional_peak_logical_bytes' => max(0, $sortPeakLogical - $baseLogical),
    'after_sort_logical_bytes' => $afterSortLogical,
    'baseline_allocated_bytes' => $baseAllocated,
    'sort_peak_allocated_bytes' => $sortPeakAllocated,
    'sort_additional_peak_allocated_bytes' => max(0, $sortPeakAllocated - $baseAllocated),
    'after_sort_allocated_bytes' => $afterSortAllocated,
    'ordered_sha256' => hash('sha256', $encoded),
];
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
