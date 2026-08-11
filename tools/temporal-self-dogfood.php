<?php

declare(strict_types=1);

$path = $argv[1] ?? null;
if (!is_string($path) || !is_file($path)) {
    fwrite(STDERR, "[FAIL] temporal dogfood requires a diff JSON file.\n");
    exit(2);
}

$json = file_get_contents($path);
$data = is_string($json) ? json_decode($json, true) : null;
if (!is_array($data)) {
    fwrite(STDERR, "[FAIL] temporal dogfood diff is not valid JSON.\n");
    exit(2);
}

$eventCount = (int) ($data['event_count'] ?? 0);
$counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];
$meaningful = 0;
foreach ($counts as $kind => $count) {
    if (!is_string($kind) || !is_int($count)) {
        continue;
    }
    if (str_starts_with($kind, 'symbol_') || str_starts_with($kind, 'method_') || str_starts_with($kind, 'relation_')) {
        $meaningful += $count;
    }
}

if ($eventCount === 0) {
    fwrite(STDERR, "[FAIL] temporal dogfood observed no change between the base and candidate maps.\n");
    exit(1);
}
if ($meaningful === 0) {
    fwrite(STDERR, "[FAIL] temporal dogfood observed only file hashes; the structural signal added no value.\n");
    exit(1);
}

ksort($counts, SORT_STRING);
echo '[PASS] temporal dogfood: ' . $eventCount . ' event(s), ' . $meaningful . " structural event(s).\n";
foreach ($counts as $kind => $count) {
    if (is_string($kind) && is_int($count)) {
        echo '  ' . str_pad($kind, 34) . $count . "\n";
    }
}
