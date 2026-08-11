<?php

declare(strict_types=1);

$path = $argv[1] ?? null;
if (!is_string($path) || !is_file($path)) {
    fwrite(STDERR, "[FAIL] temporal coupling dogfood requires a JSON report.\n");
    exit(2);
}

$json = file_get_contents($path);
$data = is_string($json) ? json_decode($json, true) : null;
$pairs = is_array($data) && is_array($data['pairs'] ?? null) ? $data['pairs'] : [];
if ($pairs === []) {
    fwrite(STDERR, "[FAIL] Git history produced no repeated co-change pairs for the indexed PHP files.\n");
    exit(1);
}

$withoutSemanticEdge = 0;
foreach ($pairs as $pair) {
    if (!is_array($pair)) {
        continue;
    }
    if ((int) ($pair['cochanges'] ?? 0) >= 2 && (float) ($pair['semantic_static_weight'] ?? 0.0) <= 0.000001) {
        ++$withoutSemanticEdge;
    }
}
if ($withoutSemanticEdge === 0) {
    fwrite(STDERR, "[FAIL] Co-change added no independent signal: every repeated pair already has semantic static coupling.\n");
    exit(1);
}

echo '[PASS] temporal coupling dogfood: ' . count($pairs) . ' ranked pair(s), '
    . $withoutSemanticEdge . " repeated pair(s) without semantic static coupling.\n";
foreach (array_slice($pairs, 0, 10) as $pair) {
    if (!is_array($pair)) {
        continue;
    }
    echo sprintf(
        "  %s <> %s | together=%d | smaller-side=%.3f | semantic=%.3f | path=%.3f\n",
        (string) ($pair['left'] ?? ''),
        (string) ($pair['right'] ?? ''),
        (int) ($pair['cochanges'] ?? 0),
        (float) ($pair['smaller_side_ratio'] ?? 0.0),
        (float) ($pair['semantic_static_weight'] ?? 0.0),
        (float) ($pair['path_static_weight'] ?? 0.0),
    );
}
