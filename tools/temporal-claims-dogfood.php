<?php

declare(strict_types=1);

$path = $argv[1] ?? null;
$json = is_string($path) && is_file($path) ? file_get_contents($path) : false;
$data = is_string($json) ? json_decode($json, true) : null;
$claims = is_array($data) && is_array($data['claims'] ?? null) ? $data['claims'] : [];
if ($claims === []) {
    fwrite(STDERR, "[FAIL] agent-map history produced no evidence-backed temporal claims.\n");
    exit(1);
}

foreach ($claims as $claim) {
    if (!is_array($claim)
        || ($claim['kind'] ?? null) !== 'hidden_temporal_coupling'
        || ($claim['heuristic'] ?? null) !== true
        || !is_array($claim['evidence'] ?? null)
        || (float) ($claim['evidence']['semantic_static_weight'] ?? 1.0) > 0.000001
        || (float) ($claim['evidence']['smaller_side_ratio'] ?? 0.0) < 0.6
    ) {
        fwrite(STDERR, "[FAIL] temporal claim escaped its evidence contract.\n");
        exit(1);
    }
}

printf("[PASS] temporal claims dogfood: %d explicit heuristic claim(s).\n", count($claims));
foreach (array_slice($claims, 0, 10) as $claim) {
    $evidence = $claim['evidence'];
    printf(
        "  %s <> %s | together=%d | smaller-side=%.3f | semantic=%.3f\n",
        (string) $claim['left'],
        (string) $claim['right'],
        (int) $evidence['cochanges'],
        (float) $evidence['smaller_side_ratio'],
        (float) $evidence['semantic_static_weight'],
    );
}
