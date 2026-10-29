<?php

declare(strict_types=1);

/**
 * Benchmark-only wrapper measuring max resident set of direct child processes.
 *
 * Usage: php tools/real-phpstan-child-memory-ab.php <install-dir>
 *   <fixture-root> <output-index>
 *
 * getrusage(RUSAGE_CHILDREN) on Linux reports a maximum child-process RSS,
 * not an accumulated RSS of an entire process tree. This isolates the PHPStan
 * subprocess better than measuring the PHP wrapper process, but does not
 * measure sum-of-simultaneous-workers memory.
 */

if (count($argv) !== 4) {
    fwrite(STDERR, "Expected install dir, fixture root, and output index.\n");
    exit(2);
}
[, $install, $root, $out] = $argv;
require $install . '/vendor/autoload.php';

$app = new \voku\AgentMap\Cli\AgentMapApplication();
$start = hrtime(true);
ob_start();
$exitCode = $app->run([
    $install . '/bin/agent-map',
    'build',
    '--root=' . $root,
    '--paths=src',
    '--out=' . $out,
    '--backend=phpstan',
    '--phpstan-memory-limit=512M',
]);
$messages = ob_get_clean();
if ($exitCode !== 0) {
    fwrite(STDERR, (string) $messages . "\nBuild failed.\n");
    exit($exitCode);
}
$resources = getrusage(1);
$elapsedMs = round((hrtime(true) - $start) / 1_000_000, 3);
$childRssKiB = $resources['ru_maxrss'] ?? null;
if (!is_int($childRssKiB) || $childRssKiB <= 0) {
    fwrite(STDERR, "No child-process maximum RSS available.\n");
    exit(1);
}
echo json_encode([
    'php' => PHP_VERSION,
    'elapsed_ms' => $elapsedMs,
    'max_child_rss_kib' => $childRssKiB,
    'parent_php_peak_allocated_bytes' => memory_get_peak_usage(true),
    'map_sha256' => hash_file('sha256', $out),
    'relations_sha256' => hash_file('sha256', \voku\AgentMap\MapArtifactPaths::relationsFileFor($out)),
    'graph_revision' => (new \voku\AgentMap\Index\MapGraphIndex())->verifyCurrent($out)->sourceRevision(),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";