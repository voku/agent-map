<?php

declare(strict_types=1);

use voku\AgentMap\Index\IndexReader;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php tools/dogfood/rename-plan.php PROJECT_ROOT MAP_JSON PLAN_JSON CAPABILITIES_JSON\n");
    exit(2);
}

$projectRoot = realpath($argv[1]);
if ($projectRoot === false || !is_dir($projectRoot)) {
    throw new \RuntimeException('Dogfood project root does not exist: ' . $argv[1]);
}

$map = (new IndexReader())->read($argv[2]);
$planJson = file_get_contents($argv[3]);
if (!is_string($planJson)) {
    throw new \RuntimeException('Unable to read rename plan: ' . $argv[3]);
}
$plan = json_decode($planJson, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($plan)) {
    throw new \RuntimeException('Rename plan must decode to an object.');
}

$capabilitiesJson = file_get_contents($argv[4]);
if (!is_string($capabilitiesJson)) {
    throw new \RuntimeException('Unable to read rename capabilities: ' . $argv[4]);
}
$registry = json_decode($capabilitiesJson, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($registry) || ($registry['type'] ?? null) !== 'rename_capabilities' || !is_array($registry['capabilities'] ?? null)) {
    throw new \RuntimeException('Rename capability registry has an unsupported shape.');
}

/** @var array<string, array{kind: string, command: string, plan_type: string, contract_version: string, semantic_backend: string}> $capabilitiesByPlanType */
$capabilitiesByPlanType = [];
foreach ($registry['capabilities'] as $capability) {
    if (!is_array($capability)) {
        throw new \RuntimeException('Rename capability must be an object.');
    }
    $kind = $capability['kind'] ?? null;
    $command = $capability['command'] ?? null;
    $planType = $capability['plan_type'] ?? null;
    $contractVersion = $capability['contract_version'] ?? null;
    $semanticBackend = $capability['semantic_backend'] ?? null;
    if (!is_string($kind) || $kind === ''
        || !is_string($command) || $command === ''
        || !is_string($planType) || $planType === ''
        || !is_string($contractVersion) || $contractVersion === ''
        || !is_string($semanticBackend) || $semanticBackend === '') {
        throw new \RuntimeException('Rename capability contains incomplete identity.');
    }
    if (isset($capabilitiesByPlanType[$planType])) {
        throw new \RuntimeException('Rename capability registry contains duplicate plan type: ' . $planType);
    }
    $capabilitiesByPlanType[$planType] = [
        'kind' => $kind,
        'command' => $command,
        'plan_type' => $planType,
        'contract_version' => $contractVersion,
        'semantic_backend' => $semanticBackend,
    ];
}

$type = $plan['type'] ?? null;
if (!is_string($type) || !isset($capabilitiesByPlanType[$type])) {
    throw new \RuntimeException('Rename plan type is not registered by agent-map: ' . (is_scalar($type) ? (string) $type : get_debug_type($type)));
}
$capability = $capabilitiesByPlanType[$type];
if (($plan['contract_version'] ?? null) !== $capability['contract_version']) {
    throw new \RuntimeException(sprintf(
        'Rename plan contract does not match registered %s capability version %s.',
        $capability['kind'],
        $capability['contract_version'],
    ));
}
if (($plan['status'] ?? null) !== 'safe') {
    throw new \RuntimeException('Dogfood requires a safe rename plan.');
}
foreach (['blockers', 'blind_spots', 'stale_evidence'] as $field) {
    if (($plan[$field] ?? null) !== []) {
        throw new \RuntimeException('Safe dogfood plan unexpectedly contains ' . $field . '.');
    }
}
$provenance = $plan['provenance'] ?? null;
if (!is_array($provenance)) {
    throw new \RuntimeException('Rename plan has no provenance object.');
}
if (($provenance['map_digest'] ?? null) !== $map->mapDigest()) {
    throw new \RuntimeException('Rename plan map digest does not match the dogfood map.');
}
if (($provenance['backend'] ?? null) !== $map->backend) {
    throw new \RuntimeException('Rename plan backend does not match the dogfood map.');
}
$semanticBackend = $capability['semantic_backend'];
if ($semanticBackend !== 'none'
    && $map->backend !== $semanticBackend
    && !str_ends_with($map->backend, '+' . $semanticBackend)) {
    throw new \RuntimeException(sprintf(
        'Rename capability %s requires semantic backend %s, map reports %s.',
        $capability['kind'],
        $semanticBackend,
        $map->backend,
    ));
}

$edits = $plan['edits'] ?? null;
if (!is_array($edits) || $edits === []) {
    throw new \RuntimeException('Safe dogfood plan must publish at least one exact edit.');
}

/** @var array<string, list<array<string, mixed>>> $editsByPath */
$editsByPath = [];
foreach ($edits as $edit) {
    if (!is_array($edit)) {
        throw new \RuntimeException('Rename plan edit must be an object.');
    }
    $path = $edit['path'] ?? null;
    if (!is_string($path) || $path === '') {
        throw new \RuntimeException('Rename plan edit requires a relative path.');
    }
    $editsByPath[$path][] = $edit;
}

$rewrittenPaths = [];
$stagedSources = [];
foreach ($editsByPath as $path => $pathEdits) {
    $absolutePath = realpath($projectRoot . DIRECTORY_SEPARATOR . $path);
    if ($absolutePath === false || !is_file($absolutePath)) {
        throw new \RuntimeException('Rename plan edit source does not exist: ' . $path);
    }
    if ($absolutePath !== $projectRoot && !str_starts_with($absolutePath, $projectRoot . DIRECTORY_SEPARATOR)) {
        throw new \RuntimeException('Rename plan edit escapes the dogfood project root: ' . $path);
    }

    $source = file_get_contents($absolutePath);
    if (!is_string($source)) {
        throw new \RuntimeException('Unable to read rename source: ' . $path);
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
            throw new \RuntimeException('Rename plan edit contains an invalid byte range for ' . $path . '.');
        }
        if (!is_string($expected) || !is_string($replacement) || !is_string($editHash)) {
            throw new \RuntimeException('Rename plan edit contains invalid token evidence for ' . $path . '.');
        }
        if ($editHash !== $sourceHash) {
            throw new \RuntimeException('Rename plan edit source hash is stale for ' . $path . '.');
        }
        if ($start <= $previousEnd) {
            throw new \RuntimeException('Rename plan contains overlapping edits for ' . $path . '.');
        }
        if (substr($source, $start, $end - $start + 1) !== $expected) {
            throw new \RuntimeException('Rename plan expected token no longer matches ' . $path . '.');
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
        throw new \RuntimeException('Rewritten PHP did not produce an AST for ' . $path . '.');
    }
    $stagedSources[$absolutePath] = $source;
    $rewrittenPaths[] = $path;
}

sort($rewrittenPaths, SORT_STRING);

$moves = $plan['moves'] ?? [];
if (!is_array($moves)) {
    throw new \RuntimeException('Rename plan moves must be a list.');
}
/** @var list<array{from: string, to: string, from_path: string, to_path: string, destination_must_be_absent: bool}> $stagedMoves */
$stagedMoves = [];
foreach ($moves as $move) {
    if (!is_array($move)) {
        throw new \RuntimeException('Rename plan move must be an object.');
    }
    $fromPath = $move['from_path'] ?? null;
    $toPath = $move['to_path'] ?? null;
    $sourceHash = $move['source_sha256'] ?? null;
    $destinationMustBeAbsent = $move['destination_must_be_absent'] ?? null;
    foreach ([$fromPath, $toPath] as $relative) {
        if (!is_string($relative) || $relative === '' || str_contains($relative, "\0")
            || str_starts_with($relative, '/') || preg_match('/(?:^|[\\\\\/])\.\.(?:[\\\\\/]|$)/', $relative) === 1) {
            throw new \RuntimeException('Rename plan move contains an unsafe path.');
        }
    }
    if ($destinationMustBeAbsent !== true) {
        throw new \RuntimeException('Rename plan move must require the destination to remain absent before publication.');
    }
    $from = realpath($projectRoot . DIRECTORY_SEPARATOR . $fromPath);
    $to = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $toPath);
    if ($from === false || !is_file($from) || !str_starts_with($from, $projectRoot . DIRECTORY_SEPARATOR)) {
        throw new \RuntimeException('Rename plan move source is missing or escapes the project root: ' . $fromPath);
    }
    $toDirectory = realpath(dirname($to));
    if ($toDirectory === false || ($toDirectory !== $projectRoot && !str_starts_with($toDirectory, $projectRoot . DIRECTORY_SEPARATOR))) {
        throw new \RuntimeException('Rename plan move destination directory escapes the project root: ' . $toPath);
    }
    if (file_exists($to) || is_link($to)) {
        throw new \RuntimeException('Rename plan move destination already exists: ' . $toPath);
    }
    $current = file_get_contents($from);
    if (!is_string($current) || !is_string($sourceHash) || $sourceHash !== 'sha256:' . hash('sha256', $current)) {
        throw new \RuntimeException('Rename plan move source hash is stale: ' . $fromPath);
    }
    $stagedMoves[] = [
        'from' => $from,
        'to' => $to,
        'from_path' => $fromPath,
        'to_path' => $toPath,
        'destination_must_be_absent' => true,
    ];
}

// All edit and move evidence is validated before this test-only publisher mutates its temporary fixture.
foreach ($stagedSources as $absolutePath => $source) {
    if (file_put_contents($absolutePath, $source) === false) {
        throw new \RuntimeException('Unable to publish rewritten dogfood source: ' . $absolutePath);
    }
}
foreach ($stagedMoves as $move) {
    if ($move['destination_must_be_absent'] && (file_exists($move['to']) || is_link($move['to']))) {
        throw new \RuntimeException('Rename plan move destination changed before publication: ' . $move['to_path']);
    }
    if (!rename($move['from'], $move['to'])) {
        throw new \RuntimeException('Unable to publish dogfood file move: ' . $move['from_path'] . ' -> ' . $move['to_path']);
    }
}

echo json_encode([
    'type' => $type,
    'kind' => $capability['kind'],
    'command' => $capability['command'],
    'contract_version' => $capability['contract_version'],
    'status' => 'passed',
    'backend' => $map->backend,
    'map_digest' => $map->mapDigest(),
    'edit_count' => count($edits),
    'move_count' => count($stagedMoves),
    'moves' => array_map(static fn (array $move): array => [
        'from_path' => $move['from_path'],
        'to_path' => $move['to_path'],
    ], $stagedMoves),
    'rewritten_paths' => $rewrittenPaths,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
