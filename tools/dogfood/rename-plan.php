<?php

declare(strict_types=1);

use RuntimeException;
use voku\AgentMap\Index\IndexReader;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php tools/dogfood/rename-plan.php PROJECT_ROOT MAP_JSON PLAN_JSON\n");
    exit(2);
}

$projectRoot = realpath($argv[1]);
if ($projectRoot === false || !is_dir($projectRoot)) {
    throw new RuntimeException('Dogfood project root does not exist: ' . $argv[1]);
}

$map = (new IndexReader())->read($argv[2]);
$planJson = file_get_contents($argv[3]);
if (!is_string($planJson)) {
    throw new RuntimeException('Unable to read rename plan: ' . $argv[3]);
}
$plan = json_decode($planJson, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($plan)) {
    throw new RuntimeException('Rename plan must decode to an object.');
}

$type = $plan['type'] ?? null;
if (!is_string($type) || !in_array($type, ['method_rename_plan', 'function_rename_plan'], true)) {
    throw new RuntimeException('Unsupported dogfood rename-plan type: ' . (is_scalar($type) ? (string) $type : get_debug_type($type)));
}
if (($plan['contract_version'] ?? null) !== '1.0') {
    throw new RuntimeException('Dogfood only accepts rename-plan contract 1.0.');
}
if (($plan['status'] ?? null) !== 'safe') {
    throw new RuntimeException('Dogfood requires a safe rename plan.');
}
foreach (['blockers', 'blind_spots', 'stale_evidence'] as $field) {
    if (($plan[$field] ?? null) !== []) {
        throw new RuntimeException('Safe dogfood plan unexpectedly contains ' . $field . '.');
    }
}

$provenance = $plan['provenance'] ?? null;
if (!is_array($provenance)) {
    throw new RuntimeException('Rename plan has no provenance object.');
}
if (($provenance['map_digest'] ?? null) !== $map->mapDigest()) {
    throw new RuntimeException('Rename plan map digest does not match the dogfood map.');
}
if (($provenance['backend'] ?? null) !== $map->backend) {
    throw new RuntimeException('Rename plan backend does not match the dogfood map.');
}

$edits = $plan['edits'] ?? null;
if (!is_array($edits) || $edits === []) {
    throw new RuntimeException('Safe dogfood plan must publish at least one exact edit.');
}

/** @var array<string, list<array<string, mixed>>> $editsByPath */
$editsByPath = [];
foreach ($edits as $edit) {
    if (!is_array($edit)) {
        throw new RuntimeException('Rename plan edit must be an object.');
    }
    $path = $edit['path'] ?? null;
    if (!is_string($path) || $path === '') {
        throw new RuntimeException('Rename plan edit requires a relative path.');
    }
    $editsByPath[$path][] = $edit;
}

$rewrittenPaths = [];
foreach ($editsByPath as $path => $pathEdits) {
    $absolutePath = realpath($projectRoot . DIRECTORY_SEPARATOR . $path);
    if ($absolutePath === false || !is_file($absolutePath)) {
        throw new RuntimeException('Rename plan edit source does not exist: ' . $path);
    }
    if ($absolutePath !== $projectRoot && !str_starts_with($absolutePath, $projectRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Rename plan edit escapes the dogfood project root: ' . $path);
    }

    $source = file_get_contents($absolutePath);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read rename source: ' . $path);
    }
    $sourceHash = 'sha256:' . hash('sha256', $source);

    usort(
        $pathEdits,
        static fn (array $left, array $right): int => ((int) ($left['start_file_pos'] ?? -1)) <=> ((int) ($right['start_file_pos'] ?? -1)),
    );

    $previousEnd = -1;
    foreach ($pathEdits as $edit) {
        $start = $edit['start_file_pos'] ?? null;
        $end = $edit['end_file_pos'] ?? null;
        $expected = $edit['expected'] ?? null;
        $replacement = $edit['replacement'] ?? null;
        $editHash = $edit['source_sha256'] ?? null;
        if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
            throw new RuntimeException('Rename plan edit contains an invalid byte range for ' . $path . '.');
        }
        if (!is_string($expected) || !is_string($replacement) || !is_string($editHash)) {
            throw new RuntimeException('Rename plan edit contains invalid token evidence for ' . $path . '.');
        }
        if ($editHash !== $sourceHash) {
            throw new RuntimeException('Rename plan edit source hash is stale for ' . $path . '.');
        }
        if ($start <= $previousEnd) {
            throw new RuntimeException('Rename plan contains overlapping edits for ' . $path . '.');
        }
        if (substr($source, $start, $end - $start + 1) !== $expected) {
            throw new RuntimeException('Rename plan expected token no longer matches ' . $path . '.');
        }
        $previousEnd = $end;
    }

    usort(
        $pathEdits,
        static fn (array $left, array $right): int => ((int) $right['start_file_pos']) <=> ((int) $left['start_file_pos']),
    );
    foreach ($pathEdits as $edit) {
        $start = (int) $edit['start_file_pos'];
        $end = (int) $edit['end_file_pos'];
        $source = substr($source, 0, $start)
            . (string) $edit['replacement']
            . substr($source, $end + 1);
    }

    if (PhpCodeParser::getAstFromString($source) === []) {
        throw new RuntimeException('Rewritten PHP did not produce an AST for ' . $path . '.');
    }
    if (file_put_contents($absolutePath, $source) === false) {
        throw new RuntimeException('Unable to publish rewritten dogfood source: ' . $path);
    }
    $rewrittenPaths[] = $path;
}

sort($rewrittenPaths, SORT_STRING);

echo json_encode([
    'type' => $type,
    'contract_version' => '1.0',
    'status' => 'passed',
    'backend' => $map->backend,
    'map_digest' => $map->mapDigest(),
    'edit_count' => count($edits),
    'rewritten_paths' => $rewrittenPaths,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
