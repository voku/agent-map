<?php

declare(strict_types=1);

if (count($argv) !== 7) {
    fwrite(STDERR, "[FAIL] temporal dogfood expects diff, coupling, claims, observe, stable-history, and new-history JSON files.\n");
    exit(2);
}

$read = static function (string $path): array {
    $json = is_file($path) ? file_get_contents($path) : false;
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data)) {
        fwrite(STDERR, '[FAIL] invalid temporal dogfood JSON: ' . $path . "\n");
        exit(2);
    }

    return $data;
};

[$diff, $coupling, $claims, $observe, $stable, $new] = array_map($read, array_slice($argv, 1));

$counts = is_array($diff['counts'] ?? null) ? $diff['counts'] : [];
$structural = 0;
foreach ($counts as $kind => $count) {
    if (is_string($kind) && is_int($count)
        && (str_starts_with($kind, 'symbol_') || str_starts_with($kind, 'method_') || str_starts_with($kind, 'relation_'))
    ) {
        $structural += $count;
    }
}
if ((int) ($diff['event_count'] ?? 0) === 0 || $structural === 0) {
    fwrite(STDERR, "[FAIL] structural diff added no evidence beyond file hashes.\n");
    exit(1);
}

$pairs = is_array($coupling['pairs'] ?? null) ? $coupling['pairs'] : [];
$independentPairs = array_filter($pairs, static function (mixed $pair): bool {
    if (!is_array($pair)) {
        return false;
    }
    $revisions = is_array($pair['revisions'] ?? null) ? $pair['revisions'] : [];

    return (int) ($pair['cochanges'] ?? 0) >= 2
        && count($revisions) === (int) ($pair['cochanges'] ?? 0)
        && (float) ($pair['semantic_static_weight'] ?? 1.0) <= 0.000001;
});
if ($independentPairs === []) {
    fwrite(STDERR, "[FAIL] Git co-change added no independently attributable signal beyond the semantic graph.\n");
    exit(1);
}

$claimRows = is_array($claims['claims'] ?? null) ? $claims['claims'] : [];
if ($claimRows === []) {
    fwrite(STDERR, "[FAIL] real history produced no temporal claims.\n");
    exit(1);
}
foreach ($claimRows as $claim) {
    $evidence = is_array($claim) && is_array($claim['evidence'] ?? null) ? $claim['evidence'] : [];
    $revisions = is_array($claim) && is_array($claim['revisions'] ?? null) ? $claim['revisions'] : [];
    if (($claim['kind'] ?? null) !== 'hidden_temporal_coupling'
        || ($claim['heuristic'] ?? null) !== true
        || count($revisions) !== (int) ($evidence['cochanges'] ?? -1)
        || (float) ($evidence['semantic_static_weight'] ?? 1.0) > 0.000001
        || (float) ($evidence['smaller_side_ratio'] ?? 0.0) < 0.6
    ) {
        fwrite(STDERR, "[FAIL] temporal claim escaped its evidence/provenance contract.\n");
        exit(1);
    }
}

$describe = static fn (array $history): string => sprintf(
    'entity=%s status=%s observations=%d',
    (string) ($history['entity_id'] ?? '?'),
    (string) ($history['status'] ?? '?'),
    (int) ($history['observation_count'] ?? 0),
);
if (($observe['snapshot_count'] ?? null) !== 2
    || ($stable['status'] ?? null) !== 'present'
    || (int) ($stable['observation_count'] ?? 0) < 2
    || ($new['status'] ?? null) !== 'present'
    || (int) ($new['observation_count'] ?? 0) !== 1
) {
    fwrite(STDERR, '[FAIL] temporal snapshot lifecycle mismatch: ' . $describe($stable) . '; ' . $describe($new) . "\n");
    exit(1);
}

printf(
    "[PASS] temporal dogfood: events=%d structural=%d; co-change pairs=%d independent=%d; claims=%d; snapshots=%d; current entities=%d.\n",
    (int) $diff['event_count'],
    $structural,
    count($pairs),
    count($independentPairs),
    count($claimRows),
    (int) $observe['snapshot_count'],
    (int) $observe['observation_count'],
);
foreach (array_slice($claimRows, 0, 5) as $claim) {
    $evidence = $claim['evidence'];
    $revisions = $claim['revisions'];
    printf(
        "  claim %s <> %s | together=%d | smaller-side=%.3f | commits=%s\n",
        (string) $claim['left'],
        (string) $claim['right'],
        (int) $evidence['cochanges'],
        (float) $evidence['smaller_side_ratio'],
        implode(',', array_map(static fn (string $revision): string => substr($revision, 0, 8), array_slice($revisions, 0, 4))),
    );
}
