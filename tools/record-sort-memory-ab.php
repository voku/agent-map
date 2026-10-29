<?php

declare(strict_types=1);

/**
 * Standalone original-vs-native record sort memory A/B (one arm per process).
 * Benchmark only; never called by the product.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use voku\AgentMap\PhpStan\CollectedRecordSorter;

$mode = $argv[1] ?? '';
$count = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
if (!in_array($mode, ['original', 'native'], true) || !is_int($count) || $count < 1 || $count > 150000) {
    fwrite(STDERR, "Usage: record-sort-memory-ab.php original|native 1..150000\n");
    exit(2);
}

$records = [];
$types = ['class', 'method', 'relation', 'type', '1', '02'];
$files = ['src/A.php', 'src/B.php', '2', '10', '01', '0', ''];
$names = ['a', '10', '2', '02', 'm', '', 'X'];
for ($i = 0; $i < $count; ++$i) {
    $record = [
        'record_type' => $types[($i * 17 + 2) % count($types)],
        'file' => $files[($i * 23 + 3) % count($files)],
        'line_start' => ($i * 31) % 101 - 50,
        'name' => $names[($i * 7 + 4) % count($names)],
        'source_id' => $names[($i * 13 + 1) % count($names)],
        'payload' => $i,
    ];
    if ($i % 13 === 0) {
        unset($record['name']);
        $record['kind'] = 'fallback';
    }
    if ($i % 11 === 0) {
        unset($record['line_start']);
    }
    if ($i % 19 === 0) {
        unset($record['source_id']);
    }
    if ($i % 17 === 0) {
        $record['record_type'] = 'equal';
        $record['file'] = 'same.php';
        $record['line_start'] = 1;
        $record['name'] = 'same';
        $record['source_id'] = 'same';
    }
    $records[] = $record;
}

$beforeLogical = memory_get_usage(false);
$beforeAllocated = memory_get_usage(true);
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
$elapsedMs = round((hrtime(true) - $started) / 1_000_000, 3);
$peakLogical = memory_get_peak_usage(false);
$peakAllocated = memory_get_peak_usage(true);

$hash = hash_init('sha256');
foreach ($records as $record) {
    hash_update($hash, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
}

echo json_encode([
    'php' => PHP_VERSION,
    'mode' => $mode,
    'count' => $count,
    'elapsed_ms' => $elapsedMs,
    'before_logical_bytes' => $beforeLogical,
    'peak_logical_bytes' => $peakLogical,
    'sort_peak_delta_logical_bytes' => max(0, $peakLogical - $beforeLogical),
    'before_allocated_bytes' => $beforeAllocated,
    'peak_allocated_bytes' => $peakAllocated,
    'sort_peak_delta_allocated_bytes' => max(0, $peakAllocated - $beforeAllocated),
    'records_sha256' => hash_final($hash),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";