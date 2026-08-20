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

use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Discovery\ImpactAnalyzer;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Search\Embedding\CorpusEmbeddingProvider;
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Dogfood\ReplayBackendContract;
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
 * Parse the replay invocation, defaulting the backend request rather than inferring it.
 *
 * An unknown flag is a mistake, not something to ignore: a typo in `--backend` that silently fell
 * back to the default would produce evidence labelled as something it is not.
 *
 * @param list<string> $argv
 *
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

    $values['backend'] ??= ReplayBackendContract::REQUEST_STRUCTURAL;
    if (!in_array($values['backend'], ReplayBackendContract::REQUESTS, true)) {
        fwrite(STDERR, '--backend must be ' . implode(' or ', ReplayBackendContract::REQUESTS) . "\n");
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

/**
 * Run one setup command and keep both halves of its result.
 *
 * stderr is folded into the output on purpose: when a build fails, the reason is the thing worth
 * printing, and callers decide what a non-zero status means for the evidence.
 *
 * @return array{status: int, output: string}
 */
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
    /** Append a term the first time it appears, keeping the reader's own order of attention. */
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
            // Identifier-shaped only: snake_case or an inner capital. Prose words are not searches.
            if (str_contains($match, '_') || preg_match('/[a-z][A-Z]/', $match) === 1) {
                $push($match, 'identifier_word');
            }
        }
    }

    return array_slice($terms, 0, BASELINE_MAXIMUM_TERMS);
}

/**
 * Every PHP file inside the replay's declared source scope, in a stable order.
 *
 * The order is part of the measurement: candidate ranking breaks ties by path, so a run must not
 * depend on directory iteration order.
 *
 * @param list<string> $sourcePaths
 *
 * @return list<string> repository-relative paths
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

/**
 * Is this one of the files the eventual fix actually touched?
 *
 * @param array<string, mixed> $replay
 */
function isVerifiedFile(array $replay, string $path): bool
{
    return in_array($path, $replay['verified']['edit_files'], true);
}

/**
 * The file a strategy has to reach to count as a hit.
 *
 * A replay whose issue text already names one of its verified files declares the *other* one as
 * `primary_location`; finding the location the reporter handed over measures nothing. Without that
 * declaration every verified file counts.
 *
 * @param array<string, mixed> $replay
 */
function isPrimaryLocation(array $replay, string $path): bool
{
    $primary = $replay['primary_location'] ?? null;

    return is_string($primary) ? $path === $primary : isVerifiedFile($replay, $path);
}

/**
 * Did this exact range land on what the fix actually changed?
 *
 * File granularity is not enough for a map strategy: a slice that names lines 12-37 of the right
 * file has not pointed at an edit location in lines 38-130, and the model that reads only the named
 * range never sees it. The baseline, which opens whole files, is graded at file granularity by
 * construction - that asymmetry favours the baseline on purpose.
 *
 * @param array<string, mixed> $replay
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

/**
 * Is this the validator the fix was proven with, where one existed before the fix?
 *
 * @param array<string, mixed> $replay
 */
function isVerifiedTestFile(array $replay, string $path): bool
{
    return in_array($path, $replay['verified']['test_files'], true);
}

/**
 * Strategy A - repository-native discovery.
 *
 * grep for each term in the fixed order, rank the files it matched by match count, open them in
 * that order, stop at the first verified edit file. Both reading models are recorded; the windowed
 * one is the fairer upper bound on what a careful agent reads.
 *
 * @param array<string, mixed> $replay
 *
 * @return array<string, mixed>
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
        // Opening the file is credited as reaching the location: the baseline reader has the whole
        // file in front of them, so refusing that credit would only inflate the map's advantage.
        'correct_edit_range_found' => $found !== null,
        'ranges_read_before_correct_range' => $found,
        'correct_test_file_found' => $testFound !== null,
        'false_candidates_opened' => $found === null ? count($opened) : $found - 1,
        'opened_files' => $opened,
        'terms' => $termReport,
    ];
}

/**
 * What a careful reader consumes from one opened file: BASELINE_WINDOW_LINES lines on each side of
 * the first match, plus the matching line - at most 81 lines while the constant is 40.
 *
 * A file opened because its *path* was named has no match line, so the reader pays for the whole
 * file. That fallback is deliberate rather than a zero-cost read.
 */
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

    // A file reference has no match line; the reader opens it whole.
    return strlen($content);
}

/**
 * Restore the embedding provider the search index was written with, or none at all.
 *
 * Mirrors agent-recall-compiler's own rule, because refitting here would build a different vector
 * space and quietly compare query vectors against neighbours that were never in it. A null provider
 * degrades the search to structural+lexical, which the report records as `degraded`.
 */
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

/**
 * The Loop's own test-path rule, copied so the replay branches exactly where agent-loop branches.
 */
function looksLikeTestPath(string $path): bool
{
    $normalized = strtolower(str_replace('\\', '/', $path));

    return str_starts_with($normalized, 'tests/')
        || str_starts_with($normalized, 'test/')
        || str_contains($normalized, '/tests/')
        || preg_match('#(?:^|/)[^/]*test\.php$#', $normalized) === 1;
}


/**
 * Strategy B - the projection agent-loop builds today.
 *
 * Ranked leads, then bounded EditContextPlanner expansion of at most three non-test method seeds
 * with the Loop policy. The Loop puts slice *headers* in the prompt, not slice source, so the model
 * pays map_output_bytes for the projection and then reads the named ranges itself.
 */
function strategyLoopMap(
    array $replay,
    AgentMapIndex $index,
    SearchIndexStore $store,
    string $repository,
    string $label = 'B_loop_ranked_map_context',
    ?string $query = null,
    ?EditContextPolicy $policyOverride = null,
): array
{
    $query ??= $replay['task']['text'];
    $search = new HybridSearch(embeddings: corpusProvider($store));
    $result = $search->search($index, $store, $query, LOOP_SEARCH_LIMIT);

    // How far down the same ranking the verified location actually sits. A lead the Loop never sees
    // because it stops at eight is a ranking result, not a coverage result, and the difference
    // decides whether anything about agent-map would have to change.
    $deep = $search->search($index, $store, $query, DEEP_RANK_LIMIT);
    $deepRank = null;
    foreach ($deep['results'] as $offset => $hit) {
        if (overlapsVerifiedRange($replay, (string) $hit['file_path'], (int) $hit['start_line'], (int) $hit['end_line'])) {
            $deepRank = $offset + 1;
            break;
        }
    }

    $lines = ['Candidate map leads (ranked, unverified):'];
    $candidateRank = null;
    foreach ($result['results'] as $offset => $hit) {
        $symbol = preg_replace('/^[^:]+:/', '', (string) $hit['symbol_id']) ?: (string) $hit['symbol_id'];
        $lines[] = sprintf(
            '  rank %d %s — %s:%d-%d',
            $offset + 1,
            $symbol,
            $hit['file_path'],
            $hit['start_line'],
            $hit['end_line'],
        );
        if ($candidateRank === null && isPrimaryLocation($replay, (string) $hit['file_path'])) {
            $candidateRank = $offset + 1;
        }
    }

    $planner = new EditContextPlanner();
    $policy = $policyOverride ?? new EditContextPolicy(maximumSourceBytes: LOOP_MAXIMUM_SOURCE_BYTES, maximumFiles: LOOP_MAXIMUM_FILES);
    $contextLines = [];
    $ranges = [];
    $expanded = 0;
    $seeds = [];
    foreach ($result['results'] as $offset => $hit) {
        $symbolId = (string) $hit['symbol_id'];
        if (!str_starts_with($symbolId, 'method:')) {
            continue;
        }
        $method = $index->resolvedMethodById($symbolId);
        if ($method === null) {
            continue;
        }

        // A ranked test method is evidence about production code, not a place to edit: the Loop
        // projects the production callees it names instead of expanding the test itself.
        if (looksLikeTestPath($method->file->path)) {
            $callees = [];
            foreach ($index->outgoing($method->id, 'calls') as $relation) {
                foreach ($relation->targetIds as $targetId) {
                    $callee = $index->resolvedMethodById($targetId);
                    if ($callee === null || looksLikeTestPath($callee->file->path)) {
                        continue;
                    }
                    $callees[$callee->id] = $callee;
                }
            }
            foreach ($callees as $callee) {
                $contextLines[] = sprintf(
                    '  rank %d test evidence calls %s — %s:%d-%d',
                    $offset + 1,
                    $callee->owner->fqn . '::' . $callee->method->name,
                    $callee->file->path,
                    $callee->method->lineStart,
                    $callee->method->lineEnd,
                );
                $ranges[] = [
                    'path' => $callee->file->path,
                    'start' => $callee->method->lineStart,
                    'end' => $callee->method->lineEnd,
                    'bytes' => methodBytes($repository, $callee->file->path, $callee->method->lineStart, $callee->method->lineEnd),
                    'origin' => 'test_evidence',
                ];
            }

            continue;
        }

        if ($expanded >= LOOP_MAXIMUM_METHOD_SEEDS) {
            continue;
        }
        $target = $method->owner->fqn . '::' . $method->method->name;
        $plan = $planner->plan($index, $target, $policy);
        $seeds[] = $target;
        $contextLines[] = sprintf('  rank %d seed %s', $offset + 1, $target);
        foreach ($plan->slices as $slice) {
            $contextLines[] = sprintf(
                '    %s:%d-%d [%s] %s',
                $slice->path,
                $slice->lineStart,
                $slice->lineEnd,
                implode(',', $slice->roles),
                implode('; ', $slice->reasons),
            );
            $ranges[] = [
                'path' => $slice->path,
                'start' => $slice->lineStart,
                'end' => $slice->lineEnd,
                'bytes' => strlen($slice->content),
                'origin' => 'edit_context',
            ];
        }
        foreach ($plan->blindSpots as $blindSpot) {
            $contextLines[] = '    blind spot: ' . $blindSpot->message;
        }
        foreach ($plan->omitted as $omitted) {
            $contextLines[] = sprintf('    omitted %s [%s]: %s', $omitted->symbolId, $omitted->role, $omitted->reason);
        }
        ++$expanded;
    }

    $projection = implode("\n", $lines) . "\n"
        . ($contextLines === [] ? '' : "Candidate structural context (unverified):\n" . implode("\n", $contextLines) . "\n");

    return measureProjection($replay, $label, $projection, $result['results'], $ranges, [
        'query' => $query,
        'deep_rank_of_verified_range' => $deepRank,
        'deep_rank_limit' => DEEP_RANK_LIMIT,
        'search_effective_mode' => $result['effective_mode'],
        'search_degraded' => $result['degraded'],
        'search_degraded_reason' => $result['degraded_reason'],
        'structural_terms' => $result['structural_terms'],
        'candidate_rank_of_verified_file' => $candidateRank,
        'expanded_seeds' => $seeds,
        'policy' => ['maximum_files' => $policy->maximumFiles, 'maximum_source_bytes' => $policy->maximumSourceBytes],
    ]);
}

/**
 * Strategy C - existing exact surfaces, seeded from the task text.
 *
 * Seed rule, applied in order and recorded with the run: a `File.php:NN` reference resolves to the
 * method that contains that line; otherwise the first extracted term that the map resolves to
 * exactly one method wins. Nothing here is a new command - context, callers, callees and impact all
 * exist today.
 */
function strategyExactNeighbours(array $replay, AgentMapIndex $index, string $repository): array
{
    $terms = extractTerms($replay['task']['text']);
    $seed = null;
    $seedReason = null;

    foreach ($terms as $term) {
        if ($term['kind'] !== 'file_reference') {
            continue;
        }
        $pattern = '#' . preg_quote($term['term'], '#') . ':(\d+)#';
        if (preg_match($pattern, $replay['task']['text'], $matches) !== 1) {
            continue;
        }
        $line = (int) $matches[1];
        $needle = ltrim(str_replace('\\', '/', $term['term']), './');
        foreach ($index->files as $file) {
            if (!str_ends_with($file->path, $needle)) {
                continue;
            }
            foreach ($file->symbols as $symbol) {
                foreach ($symbol->methods as $method) {
                    if ($line >= $method->lineStart && $line <= $method->lineEnd) {
                        $seed = $symbol->fqn . '::' . $method->name;
                        $seedReason = 'task text names ' . $file->path . ':' . $line . ', which is inside ' . $seed;
                        break 4;
                    }
                }
            }
        }
    }

    if ($seed === null) {
        foreach ($terms as $term) {
            $bare = preg_replace('/^.*(?:::|->)/', '', $term['term']) ?: $term['term'];
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $bare)) {
                continue;
            }
            $candidates = [];
            foreach ($index->query($bare)->files as $file) {
                foreach ($file->symbols as $symbol) {
                    foreach ($symbol->methods as $method) {
                        if (strcasecmp($method->name, $bare) === 0) {
                            $candidates[$symbol->fqn . '::' . $method->name] = true;
                        }
                    }
                }
            }
            if (count($candidates) === 1) {
                $seed = array_key_first($candidates);
                $seedReason = 'first task term resolving to exactly one indexed method: ' . $term['term'];
                break;
            }
        }
    }

    if ($seed === null) {
        return [
            'strategy' => 'C_exact_neighbour_surfaces',
            'applicable' => false,
            'seed' => null,
            'seed_reason' => 'no task term resolves to an indexed method; the existing exact surfaces cannot be seeded from this task text',
            'commands' => 0,
            'map_output_bytes' => 0,
            'candidate_files_presented' => 0,
            'candidate_symbols_presented' => 0,
            'exact_source_ranges_presented' => 0,
            'files_opened_before_correct_location' => null,
            'source_bytes_read_before_correct_location' => 0,
            'correct_edit_file_found' => false,
            'correct_edit_range_found' => false,
            'correct_test_file_found' => false,
            'false_candidates_opened' => 0,
        ];
    }

    $plan = (new EditContextPlanner())->plan($index, $seed, new EditContextPolicy());
    $lines = ['agent-map context ' . $seed . ' (owner default policy):'];
    foreach ($plan->slices as $slice) {
        $lines[] = sprintf(
            '  %s:%d-%d [%s] %s',
            $slice->path,
            $slice->lineStart,
            $slice->lineEnd,
            implode(',', $slice->roles),
            implode('; ', $slice->reasons),
        );
    }
    foreach ($plan->blindSpots as $blindSpot) {
        $lines[] = '  blind spot: ' . $blindSpot->message;
    }
    foreach ($plan->omitted as $omitted) {
        $lines[] = sprintf('  omitted %s [%s]: %s', $omitted->symbolId, $omitted->role, $omitted->reason);
    }

    $resolved = $index->resolveMethod($seed);
    $relationLines = [];
    $relationFiles = [];
    foreach (['callers' => $index->incoming($resolved->id, 'calls'), 'callees' => $index->outgoing($resolved->id, 'calls')] as $label => $relations) {
        $relationLines[] = 'agent-map ' . $label . ' ' . $seed . ':';
        foreach ($relations as $relation) {
            $ids = $label === 'callers' ? [$relation->sourceId] : $relation->targetIds;
            foreach ($ids as $id) {
                $other = $index->resolvedMethodById($id);
                if ($other === null) {
                    $relationLines[] = '  ' . $id . ' (unresolved)';
                    continue;
                }
                $relationLines[] = sprintf(
                    '  %s::%s — %s:%d-%d',
                    $other->owner->fqn,
                    $other->method->name,
                    $other->file->path,
                    $other->method->lineStart,
                    $other->method->lineEnd,
                );
                $relationFiles[] = [
                    'path' => $other->file->path,
                    'start' => $other->method->lineStart,
                    'end' => $other->method->lineEnd,
                    'bytes' => methodBytes($repository, $other->file->path, $other->method->lineStart, $other->method->lineEnd),
                ];
            }
        }
    }

    $impact = (new ImpactAnalyzer())->forMethod($index, $seed);
    $impactLines = ['agent-map impact ' . $seed . ':'];
    foreach ($impact->impacts as $node) {
        $impactLines[] = sprintf('  depth %d %s — %s', $node->depth, $node->node->id, $node->node->file);
        $relationFiles[] = ['path' => $node->node->file, 'start' => 0, 'end' => 0, 'bytes' => 0];
    }

    $projection = implode("\n", $lines) . "\n" . implode("\n", $relationLines) . "\n" . implode("\n", $impactLines) . "\n";

    $contextRanges = [];
    foreach ($plan->slices as $slice) {
        $contextRanges[] = [
            'path' => $slice->path,
            'start' => $slice->lineStart,
            'end' => $slice->lineEnd,
            'bytes' => strlen($slice->content),
            'origin' => 'edit_context',
        ];
    }

    $measured = measureProjection($replay, 'C_exact_neighbour_surfaces', $projection, [], $contextRanges, [
        'seed' => $seed,
        'seed_reason' => $seedReason,
        'policy' => ['maximum_files' => 20, 'maximum_source_bytes' => 60000],
        'callers_and_callees_presented' => count($relationFiles),
        'impact_nodes_presented' => count($impact->impacts),
        'impact_truncated' => $impact->truncated,
    ]);
    $measured['applicable'] = true;
    $measured['commands'] = 4;

    foreach ($relationFiles as $offset => $entry) {
        if (!isPrimaryLocation($replay, $entry['path'])) {
            continue;
        }
        if (!$measured['correct_edit_file_found']) {
            $measured['correct_edit_file_found'] = true;
            $measured['files_opened_before_correct_location'] = count($measured['read_files']) + $offset + 1;
            $measured['found_via'] = 'callers/callees/impact';
        }
        if (!$measured['correct_edit_range_found'] && overlapsVerifiedRange($replay, $entry['path'], $entry['start'], $entry['end'])) {
            $measured['correct_edit_range_found'] = true;
            $measured['ranges_read_before_correct_range'] = $measured['exact_source_ranges_presented'] + $offset + 1;
            $measured['source_bytes_read_before_correct_range'] += $entry['bytes'];
            $measured['found_via'] = 'callers/callees/impact';
        }
        break;
    }

    return $measured;
}

/**
 * Publish a validated report atomically.
 *
 * The temporary file lives beside the target so the rename cannot cross a filesystem boundary and
 * degrade into a copy. A reader therefore sees either the previous state or a complete report from a
 * run that passed every check, never a half-written or half-validated one.
 */
function publishReport(string $target, string $contents): bool
{
    $directory = dirname($target);
    if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
        return false;
    }

    $temporary = $target . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $contents) === false) {
        return false;
    }
    if (!rename($temporary, $target)) {
        unlink($temporary);

        return false;
    }

    return true;
}

/**
 * Source cost of reading one named method range from the frozen checkout.
 *
 * A range the map names but the checkout no longer contains costs nothing rather than throwing: the
 * strategy is being measured on what it presented, and a missing file is already visible as a miss.
 */
function methodBytes(string $repository, string $path, int $start, int $end): int
{
    $file = $repository . '/' . $path;
    if (!is_file($file)) {
        return 0;
    }
    $lines = explode("\n", (string) file_get_contents($file));

    return strlen(implode("\n", array_slice($lines, max(0, $start - 1), max(1, $end - $start + 1))));
}

/**
 * Shared accounting for a map-produced projection: what it cost to read, what it presented, and how
 * far the reader got before the verified location appeared.
 */
/**
 * @param list<array{path: string, start: int, end: int, bytes: int, origin: string}> $ranges
 */
function measureProjection(array $replay, string $strategy, string $projection, array $candidates, array $ranges, array $extra): array
{
    $candidateFiles = [];
    $testFromCandidates = null;
    foreach ($candidates as $offset => $hit) {
        $candidateFiles[(string) $hit['file_path']] = true;
        if ($testFromCandidates === null && isVerifiedTestFile($replay, (string) $hit['file_path'])) {
            $testFromCandidates = $offset + 1;
        }
    }

    $readFiles = [];
    $fileBytes = 0;
    $fileHit = null;
    $testHit = null;
    $falseCandidates = 0;
    foreach ($ranges as $range) {
        $fileBytes += $range['bytes'];
        if (!in_array($range['path'], $readFiles, true)) {
            $readFiles[] = $range['path'];
            if (!isVerifiedFile($replay, $range['path'])) {
                ++$falseCandidates;
            }
        }
        if ($testHit === null && isVerifiedTestFile($replay, $range['path'])) {
            $testHit = count($readFiles);
        }
        if ($fileHit === null && isPrimaryLocation($replay, $range['path'])) {
            $fileHit = count($readFiles);
            break;
        }
    }

    $rangeBytes = 0;
    $rangeHit = null;
    $rangesRead = 0;
    foreach ($ranges as $range) {
        ++$rangesRead;
        $rangeBytes += $range['bytes'];
        if (overlapsVerifiedRange($replay, $range['path'], $range['start'], $range['end'])) {
            $rangeHit = $rangesRead;
            break;
        }
    }

    return array_merge([
        'strategy' => $strategy,
        'commands' => 3,
        'map_output_bytes' => strlen($projection),
        'tool_output_bytes' => strlen($projection),
        'candidate_files_presented' => count($candidateFiles),
        'candidate_symbols_presented' => count($candidates),
        'exact_source_ranges_presented' => count($ranges),
        'files_opened_before_correct_location' => $fileHit,
        'source_bytes_read_before_correct_location' => $fileBytes,
        'ranges_read_before_correct_range' => $rangeHit,
        'source_bytes_read_before_correct_range' => $rangeBytes,
        'correct_edit_file_found' => $fileHit !== null,
        'correct_edit_range_found' => $rangeHit !== null,
        // A lead is presented to the model too: naming the right validator in the ranked list counts,
        // the same way opening the file counts for the baseline.
        'correct_test_file_found' => $testHit !== null || $testFromCandidates !== null,
        'correct_test_file_channel' => $testHit !== null ? 'presented_range' : ($testFromCandidates === null ? null : 'ranked_lead'),
        'false_candidates_opened' => $fileHit === null ? $falseCandidates : max(0, $fileHit - 1),
        'read_files' => $readFiles,
        'projection' => $projection,
    ], $extra);
}

/** What the channel itself says it observed. Absence outside this envelope is not evidence. */
function observationEnvelope(array $replay, AgentMapIndex $index, SearchIndexStore $store, string $requestedBackend): array
{
    $vectorSupport = $store->enableVectorSupport();
    $symbolLess = 0;
    foreach ($index->files as $file) {
        if ($file->symbols === []) {
            ++$symbolLess;
        }
    }

    $stale = [];
    foreach ($index->staleEntries() as $entry) {
        $stale[] = $entry['path'] . ' (' . $entry['reason'] . ')';
    }

    return [
        'source_scope' => $replay['source_paths'],
        'indexed_files' => count($index->files),
        'symbol_less_indexed_files' => $symbolLess,
        'requested_backend' => $requestedBackend,
        'backend' => $index->backend,
        'call_relations' => count(array_filter($index->relations, static fn ($relation): bool => $relation->kind === 'calls')),
        'relations' => count($index->relations),
        'diagnostics' => count($index->diagnostics),
        'map_snapshot' => $index->fingerprint === null ? 'none' : $index->fingerprint->sourceDigest,
        // Provenance agent-map already owns: PHPStan version and composer.lock digest of the run
        // that produced this map. Recorded so a future report does not have to be reconstructed.
        'analysis_fingerprint' => $index->fingerprint?->toArray(),
        'map_stale_entries' => $stale,
        'search_fts5' => SearchIndexStore::supportsFts5(),
        'search_vector_support' => $vectorSupport,
        'search_vectors' => $vectorSupport ? $store->vectorCount() : 0,
        'search_index_snapshot' => $store->meta('map_snapshot') ?? 'none',
        'search_index_is_current' => ($store->meta('map_snapshot') ?? 'none') === ($index->fingerprint === null ? 'none' : $index->fingerprint->sourceDigest),
        'php_version' => PHP_VERSION,
        // The owner's own availability rule, not a guess at a vendor path.
        'phpstan_available' => PhpStanSemanticAnalyzer::isAvailable(),
        'not_observable' => [
            'per-file coverage precision beyond indexed/stale/symbol-less counts',
            'whether an unindexed path contains the answer (out-of-scope silence is not absence)',
        ],
    ];
}

$arguments = parseArguments($argv);

// Claim the output before anything can fail. From here on this invocation owns that exact path, so
// no later failure - not the preflight, not a bad fixture, not a mismatched backend - can leave an
// earlier report behind for the summary boundary to read as this run's evidence. Only the exact
// target is touched, and only when it is a regular file; the cost is that a mistyped argument also
// clears the previous report, which is the right direction to fail.
$reportPath = $arguments['json'] ?? null;
if ($reportPath !== null && is_file($reportPath) && !unlink($reportPath)) {
    fwrite(STDERR, 'Cannot replace previous report: ' . $reportPath . "\n");
    exit(1);
}

$replay = json_decode((string) file_get_contents($arguments['replay']), true);
if (!is_array($replay)) {
    fwrite(STDERR, 'Cannot read replay fixture: ' . $arguments['replay'] . "\n");
    exit(1);
}

// Fail before the expensive setup when the requested backend cannot possibly be produced. This is a
// convenience, not the correctness boundary - the effective backend of the built map decides.
if ($arguments['backend'] === ReplayBackendContract::REQUEST_PHPSTAN && !PhpStanSemanticAnalyzer::isAvailable()) {
    fwrite(
        STDERR,
        "PHPStan-backed replay requested, but phpstan/phpstan is unavailable.\n"
        . "Install development dependencies (composer install) before generating PHPStan evidence.\n",
    );
    exit(1);
}

$repository = rtrim($arguments['repo'], '/');
$head = run('git -C ' . escapeshellarg($repository) . ' rev-parse HEAD');
if ($head['status'] !== 0 || trim($head['output']) !== $replay['base_commit']) {
    fwrite(STDERR, 'Frozen checkout mismatch: expected ' . $replay['base_commit'] . ', found ' . trim($head['output']) . "\n");
    exit(1);
}

$artifacts = rtrim($arguments['artifacts'], '/');
if (!is_dir($artifacts) && !mkdir($artifacts, 0o777, true) && !is_dir($artifacts)) {
    fwrite(STDERR, 'Cannot create artifact directory: ' . $artifacts . "\n");
    exit(1);
}

$binary = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/agent-map');
$mapPath = $artifacts . '/map.json';
$databasePath = $artifacts . '/search.sqlite';

$build = run($binary . ' build --root=' . escapeshellarg($repository)
    . ' --paths=' . escapeshellarg(implode(',', $replay['source_paths']))
    . ' --backend=' . escapeshellarg($arguments['backend'])
    . ' --out=' . escapeshellarg($mapPath));
if ($build['status'] !== 0) {
    fwrite(STDERR, "map build failed:\n" . $build['output'] . "\n");
    exit(1);
}
$searchBuild = run($binary . ' search-index build --root=' . escapeshellarg($repository)
    . ' --index=' . escapeshellarg($mapPath) . ' --database=' . escapeshellarg($databasePath));
if ($searchBuild['status'] !== 0) {
    fwrite(STDERR, "search index build failed:\n" . $searchBuild['output'] . "\n");
    exit(1);
}

$index = (new IndexReader())->read($mapPath);

// The authoritative check: what the map says it is, not what was asked for and not what the report
// will be called. Everything below this line is only worth publishing because it passed.
try {
    ReplayBackendContract::assertSatisfiedBy($arguments['backend'], $index->backend, 'Replay ' . $replay['id']);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$store = new SearchIndexStore($databasePath);

$report = [
    'schema_version' => '1.0',
    'replay' => [
        'id' => $replay['id'],
        'shape' => $replay['shape'],
        'repository' => $replay['repository'],
        'base_commit' => $replay['base_commit'],
        'fix_commit' => $replay['fix_commit'],
        'verified' => $replay['verified'],
        'primary_location' => $replay['primary_location'] ?? $replay['verified']['edit_files'][0],
    ],
    'agent_map' => [
        'version' => trim(run('git -C ' . escapeshellarg(__DIR__ . '/../..') . ' rev-parse HEAD')['output']),
        'backend_requested' => $arguments['backend'],
        'setup_output' => ['build' => $build['output'], 'search_index' => $searchBuild['output']],
    ],
    'observation_envelope' => observationEnvelope($replay, $index, $store, $arguments['backend']),
    'strategies' => [
        strategyBaseline($replay, $repository),
        strategyLoopMap($replay, $index, $store, $repository),
        strategyExactNeighbours($replay, $index, $repository),
        // Diagnostic, not a proposal: the same Loop shape with the task's code vocabulary instead of
        // its prose, to separate "the channel cannot see it" from "the query buried it".
        strategyLoopMap(
            $replay,
            $index,
            $store,
            $repository,
            'D_probe_code_vocabulary_query',
            implode(' ', array_map(static fn (array $term): string => $term['term'], extractTerms($replay['task']['text']))),
        ),
        // Diagnostic, not a proposal: the Loop shape with agent-map's own default projection budget,
        // to test whether the tighter 6-file / 16 KB Loop policy is what loses a location.
        strategyLoopMap($replay, $index, $store, $repository, 'E_probe_owner_default_policy', null, new EditContextPolicy()),
    ],
];

// Machine-local paths are provenance for one run, not evidence; a committed report has to read the
// same on someone else's disk.
$json = str_replace(
    [$artifacts, $repository, dirname(__DIR__, 2)],
    ['<artifacts>', '<frozen-checkout>', '<agent-map>'],
    (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
);
// Re-read what is actually about to be written: the envelope, not the run's variables, is what a
// later reader will trust.
$published = json_decode($json, true);
if (!is_array($published)) {
    fwrite(STDERR, "Report could not be encoded as readable JSON; nothing was published.\n");
    exit(1);
}
try {
    ReplayBackendContract::assertReportIsConsistent($published, 'Replay report for ' . $replay['id']);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

if ($reportPath !== null && !publishReport($reportPath, $json . "\n")) {
    fwrite(STDERR, 'Cannot publish report: ' . $reportPath . "\n");
    exit(1);
}
echo $json . "\n";
