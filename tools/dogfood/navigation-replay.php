<?php

declare(strict_types=1);

/**
 * Bounded navigation-cost replay for one frozen PHP task (agent-map issue #25, M1/M2).
 *
 * The question is not "does Search return something". It is whether agent-map helps a coding model
 * reach the small set of locations a verified fix actually touched, for less reading than a
 * repository-native grep/read workflow - while staying explicit about what the observation channel
 * did and did not see.
 *
 * Three strategies run against the same frozen checkout and the same task text:
 *
 *   A  repository-native grep/read only, no agent-map result injected;
 *   B  the shape agent-loop consumes today: ranked hybrid search leads, current-map check, then
 *      EditContextPlanner with the Loop projection budget (6 files / 16000 source bytes);
 *   C  agent-map's existing exact surfaces only: context (owner default policy), callers, callees,
 *      impact - seeded from the task text, with no new command invented for the experiment.
 *
 * Every strategy is a fixed policy, not a model. That is the point: a replay has to be reproducible,
 * so the discovery rules are written down here instead of being improvised per task. Both baseline
 * reading models are reported (whole-file, and a window of BASELINE_WINDOW_LINES lines on each side
 * of the match) so the comparison cannot be won by charging the baseline for reads a careful agent
 * would not make.
 *
 * Usage:
 *   php tools/dogfood/navigation-replay.php --replay=tools/dogfood/replays/<id>.json \
 *       --repo=/path/to/frozen/checkout --artifacts=/path/to/scratch \
 *       [--backend=structural|phpstan] [--json=out.json]
 *
 * The backend is part of the observation envelope, not a detail: without phpstan/phpstan installed
 * agent-map builds structural-only maps, which carry no `calls` relations at all. Run both.
 */

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}
require $autoload;

use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Discovery\ImpactAnalyzer;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Search\Embedding\CorpusEmbeddingProvider;
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Search\SearchIndexStore;

/** The Loop's own projection budget, copied from agent-loop WorkflowRankedMapContextExpander. */
const LOOP_MAXIMUM_FILES = 6;
const LOOP_MAXIMUM_SOURCE_BYTES = 16000;
const LOOP_MAXIMUM_METHOD_SEEDS = 3;
const LOOP_SEARCH_LIMIT = 8;

/** Only used to locate the verified range inside the same ranking; never presented to a model. */
const DEEP_RANK_LIMIT = 200;

/** A baseline agent gives up eventually; without a cap a miss is indistinguishable from a slow hit. */
const BASELINE_MAXIMUM_OPENED_FILES = 25;
const BASELINE_WINDOW_LINES = 40;
const BASELINE_MAXIMUM_TERMS = 12;

/**
 * @return array<string, string>
 */
function parseArguments(array $argv): array
{
    $values = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $matches) === 1) {
            $values[$matches[1]] = $matches[2];
            continue;
        }
        fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
        exit(1);
    }

    $values['backend'] ??= 'structural';
    if (!in_array($values['backend'], ['structural', 'phpstan'], true)) {
        fwrite(STDERR, "--backend must be structural or phpstan\n");
        exit(1);
    }

    foreach (['replay', 'repo', 'artifacts'] as $required) {
        if (($values[$required] ?? '') === '') {
            fwrite(STDERR, 'Missing --' . $required . "\n");
            exit(1);
        }
    }

    return $values;
}

function run(string $command): array
{
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);

    return ['status' => $status, 'output' => implode("\n", $output)];
}

/**
 * Terms a reader would actually search for, in the order a reader would try them.
 *
 * File references first (an issue that names a file is telling you where to look), then code spans
 * the reporter marked as code, then identifier-shaped words. Ordinary prose is not a search term.
 *
 * @return list<array{term: string, kind: string}>
 */
function extractTerms(string $text): array
{
    $terms = [];
    $push = static function (string $term, string $kind) use (&$terms): void {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        foreach ($terms as $existing) {
            if ($existing['term'] === $term) {
                return;
            }
        }
        $terms[] = ['term' => $term, 'kind' => $kind];
    };

    if (preg_match_all('#[A-Za-z0-9_./\\\\-]+\.php#', $text, $matches) > 0) {
        foreach ($matches[0] as $match) {
            $push($match, 'file_reference');
        }
    }
    if (preg_match_all('/`([^`]{2,80})`/', $text, $matches) > 0) {
        foreach ($matches[1] as $match) {
            $push($match, 'quoted_code');
        }
    }
    if (preg_match_all('/[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:::|->)[A-Za-z_][A-Za-z0-9_]*/', $text, $matches) > 0) {
        foreach ($matches[0] as $match) {
            $push($match, 'member_reference');
        }
    }
    if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]{3,})\s*\(/', $text, $matches) > 0) {
        foreach ($matches[1] as $match) {
            $push($match, 'call_reference');
        }
    }
    if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]{3,}/', $text, $matches) > 0) {
        foreach ($matches[0] as $match) {
            if (str_contains($match, '_') || preg_match('/[a-z][A-Z]/', $match) === 1) {
                $push($match, 'identifier_word');
            }
        }
    }

    return array_slice($terms, 0, BASELINE_MAXIMUM_TERMS);
}

/**
 * @return list<string>
 */
function collectPhpFiles(string $repository, array $sourcePaths): array
{
    $files = [];
    foreach ($sourcePaths as $relative) {
        $root = rtrim($repository, '/') . '/' . $relative;
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = substr($file->getPathname(), strlen(rtrim($repository, '/')) + 1);
            }
        }
    }
    sort($files, SORT_STRING);

    return $files;
}

function isVerifiedFile(array $replay, string $path): bool
{
    return in_array($path, $replay['verified']['edit_files'], true);
}

function isPrimaryLocation(array $replay, string $path): bool
{
    $primary = $replay['primary_location'] ?? null;

    return is_string($primary) ? $path === $primary : isVerifiedFile($replay, $path);
}

/**
 * Did this exact range land on what the fix actually changed?
 */
function overlapsVerifiedRange(array $replay, string $path, int $start, int $end): bool
{
    foreach ($replay['verified']['edit_ranges'] as $range) {
        if ($range['file'] !== $path) {
            continue;
        }
        if (!isPrimaryLocation($replay, $path)) {
            continue;
        }
        if ($start <= $range['end'] && $end >= $range['start']) {
            return true;
        }
    }

    return false;
}

function isVerifiedTestFile(array $replay, string $path): bool
{
    return in_array($path, $replay['verified']['test_files'], true);
}

/**
 * Strategy A - repository-native discovery.
 */
function strategyBaseline(array $replay, string $repository): array
{
    $files = collectPhpFiles($repository, $replay['source_paths']);
    $contents = [];
    foreach ($files as $path) {
        $contents[$path] = (string) file_get_contents($repository . '/' . $path);
    }

    $terms = extractTerms($replay['task']['text']);
    $termReport = [];
    $candidateOrder = [];
    $toolOutputBytes = 0;
    $commands = 0;
    $seen = [];

    foreach ($terms as $term) {
        ++$commands;
        $termOutputBytes = 0;
        $matches = [];
        if ($term['kind'] === 'file_reference') {
            $needle = ltrim(str_replace('\\', '/', $term['term']), './');
            foreach ($files as $path) {
                if (str_ends_with($path, $needle) || str_ends_with($path, '/' . basename($needle))) {
                    $matches[$path] = 1;
                }
            }
        } else {
            foreach ($files as $path) {
                $count = 0;
                $lineNumber = 0;
                foreach (explode("\n", $contents[$path]) as $line) {
                    ++$lineNumber;
                    if (str_contains($line, $term['term'])) {
                        ++$count;
                        $termOutputBytes += strlen($path . ':' . $lineNumber . ':' . $line . "\n");
                    }
                }
                if ($count > 0) {
                    $matches[$path] = $count;
                }
            }
        }

        $ranked = array_keys($matches);
        usort($ranked, static fn (string $left, string $right): int => $matches[$right] <=> $matches[$left] ?: strcmp($left, $right));

        $verifiedRank = null;
        foreach ($ranked as $offset => $path) {
            if (isPrimaryLocation($replay, $path)) {
                $verifiedRank = $offset + 1;
                break;
            }
        }

        $termReport[] = [
            'term' => $term['term'],
            'kind' => $term['kind'],
            'matched_files' => count($ranked),
            'grep_output_bytes' => $termOutputBytes,
            'primary_file_rank' => $verifiedRank,
        ];
        $toolOutputBytes += $termOutputBytes;

        foreach ($ranked as $path) {
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $candidateOrder[] = ['path' => $path, 'term' => $term['term'], 'matches' => $matches[$path]];
        }
    }

    $opened = [];
    $wholeFileBytes = 0;
    $windowBytes = 0;
    $found = null;
    $testFound = null;
    foreach ($candidateOrder as $candidate) {
        if (count($opened) >= BASELINE_MAXIMUM_OPENED_FILES) {
            break;
        }
        $path = $candidate['path'];
        $opened[] = $path;
        $wholeFileBytes += strlen($contents[$path]);
        $windowBytes += windowBytes($contents[$path], $candidate['term']);
        if ($testFound === null && isVerifiedTestFile($replay, $path)) {
            $testFound = count($opened);
        }
        if (isPrimaryLocation($replay, $path)) {
            $found = count($opened);
            break;
        }
    }

    return [
        'strategy' => 'A_baseline_grep_read',
        'commands' => $commands + count($opened),
        'map_output_bytes' => 0,
        'tool_output_bytes' => $toolOutputBytes,
        'candidate_files_presented' => count($candidateOrder),
        'candidate_symbols_presented' => 0,
        'exact_source_ranges_presented' => 0,
        'files_opened_before_correct_location' => $found,
        'source_bytes_read_whole_file_model' => $wholeFileBytes,
        'source_bytes_read_window_model' => $windowBytes,
        'correct_edit_file_found' => $found !== null,
        'correct_edit_range_found' => $found !== null,
        'ranges_read_before_correct_range' => $found,
        'correct_test_file_found' => $testFound !== null,
        'false_candidates_opened' => $found === null ? count($opened) : $found - 1,
        'opened_files' => $opened,
        'terms' => $termReport,
    ];
}

function windowBytes(string $content, string $term): int
{
    $lines = explode("\n", $content);
    foreach ($lines as $offset => $line) {
        if (str_contains($line, $term)) {
            $start = max(0, $offset - BASELINE_WINDOW_LINES);
            $slice = array_slice($lines, $start, BASELINE_WINDOW_LINES * 2 + 1);

            return strlen(implode("\n", $slice));
        }
    }

    return strlen($content);
}

function corpusProvider(SearchIndexStore $store): ?CorpusEmbeddingProvider
{
    if (!$store->enableVectorSupport() || $store->vectorCount() === 0) {
        return null;
    }
    $state = json_decode((string) $store->meta('embedding_state'), true);
    if (!is_array($state) || !is_string($state['revision'] ?? null) || !is_array($state['weights'] ?? null)) {
        return null;
    }
    $provider = new CorpusEmbeddingProvider();
    /** @var array{revision: string, weights: array<string, float>} $state */
    $provider->restore($state);

    return $provider->model()->fingerprint() === $store->meta('embedding_fingerprint') ? $provider : null;
}

function looksLikeTestPath(string $path): bool
{
    $normalized = strtolower(str_replace('\\', '/', $path));

    return str_starts_with($normalized, 'tests/')
        || str_starts_with($normalized, 'test/')
        || str_contains($normalized, '/tests/')
        || preg_match('#(?:^|/)[^/]*test\.php$#', $normalized) === 1;
}

