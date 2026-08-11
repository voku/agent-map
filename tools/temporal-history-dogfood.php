<?php

declare(strict_types=1);

if (count($argv) !== 4) {
    fwrite(STDERR, "[FAIL] temporal history dogfood expects observe, stable-history, and new-history JSON files.\n");
    exit(2);
}

$read = static function (string $path): array {
    $json = is_file($path) ? file_get_contents($path) : false;
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data)) {
        fwrite(STDERR, '[FAIL] invalid temporal history dogfood JSON: ' . $path . "\n");
        exit(2);
    }
    return $data;
};

$observe = $read($argv[1]);
$stable = $read($argv[2]);
$new = $read($argv[3]);

if (($observe['snapshot_count'] ?? null) !== 2) {
    fwrite(STDERR, "[FAIL] temporal history did not persist exactly two revision snapshots.\n");
    exit(1);
}
if (($stable['status'] ?? null) !== 'present' || ($stable['observation_count'] ?? 0) < 2) {
    fwrite(STDERR, "[FAIL] stable agent-map entity is not visible across both temporal snapshots.\n");
    exit(1);
}
if (($new['status'] ?? null) !== 'present' || ($new['observation_count'] ?? 0) !== 1) {
    fwrite(STDERR, "[FAIL] newly introduced temporal entity does not have the expected one-snapshot lifecycle.\n");
    exit(1);
}

printf(
    "[PASS] temporal history dogfood: %d snapshots; stable entity observations=%d; new entity observations=%d; latest snapshot entities=%d.\n",
    (int) $observe['snapshot_count'],
    (int) $stable['observation_count'],
    (int) $new['observation_count'],
    (int) $observe['observation_count'],
);
