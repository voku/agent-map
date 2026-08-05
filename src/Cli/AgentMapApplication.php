<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use RuntimeException;
use Throwable;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\IO\PhpFileFinder;
use voku\AgentMap\Search\ChunkExtractor;
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Search\Embedding\CorpusEmbeddingProvider;
use voku\AgentMap\Search\SearchBenchmark;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentMap\Inspect\ScopeInspector;
use voku\AgentMap\Inspect\ScopeSelector;

final readonly class AgentMapApplication
{
    public function __construct(
        private OutputFormatter $formatter = new OutputFormatter(),
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        array_shift($argv);

        try {
            $options = CliOptions::parse($argv);
            if ($options->command === 'help' || $options->help) {
                echo $this->help($options->command);
                return 0;
            }

            return match ($options->command) {
                'build' => $this->build($options),
                'refresh' => $this->refresh($options),
                'search-index' => $this->searchIndex($options),
                'search' => $this->search($options),
                'query' => $this->query($options),
                'file' => $this->file($options),
                'stale' => $this->stale($options),
                'summary' => $this->summary($options),
                'changed' => $this->changed($options),
                'related' => $this->related($options),
                'stats' => $this->stats($options),
                'scope' => $this->scope($options),
                'callers' => $this->relations($options, true),
                'callees' => $this->relations($options, false),
                'context' => $this->context($options),
                default => 1,
            };
        } catch (Throwable $throwable) {
            fwrite(STDERR, $throwable->getMessage() . "\n");
            return 1;
        }
    }

    private function build(CliOptions $options): int
    {
        $previous = null;
        if ($options->merge && is_file($options->out)) {
            $previous = (new IndexReader())->read($options->out);
        }

        $index = (new AgentMapBuilder())->build(
            $options->root,
            $options->paths,
            $options->excludes,
            $options->phpStanConfig,
            $options->phpStanMemoryLimit,
            $previous,
            $options->scanPaths,
        );
        (new IndexWriter())->write($index, $options->out, $options->format);
        echo 'Wrote ' . count($index->files) . ' file(s), ' . count($index->relations) . ' relation(s), and ' . count($index->diagnostics) . ' diagnostic(s) to ' . $options->out . "\n";

        return 0;
    }

    /**
     * Rebuilds only what the index no longer matches.
     *
     * A full semantic build of a real code base runs for minutes, so keeping the map current has to
     * cost about as much as the change that invalidated it: this re-analyses the files whose hash
     * moved plus the ones that appeared since the last build, and patches them into the existing
     * index. Deleted files drop out through the same merge.
     */
    private function refresh(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);

        $indexed = [];
        foreach ($index->files as $file) {
            $indexed[$file->path] = true;
        }

        $changed = [];
        $removed = 0;
        foreach ($index->staleEntries() as $entry) {
            if ($entry['reason'] === 'missing') {
                ++$removed;
                continue;
            }

            $changed[$entry['path']] = true;
        }

        // Without an explicit scope, look for new files exactly where the index already reaches:
        // walking the whole root would drag vendor directories into every refresh, and widening to
        // the top-level directory would pull in siblings the original build deliberately left out.
        $searchPaths = $options->paths === ['.'] ? $this->indexedDirectories($index->files) : $options->paths;
        foreach ((new PhpFileFinder())->find($options->root, $searchPaths, $options->excludes) as $relative) {
            if (!isset($indexed[$relative])) {
                $changed[$relative] = true;
            }
        }

        if ($changed === [] && $removed === 0) {
            echo 'Index is up to date: ' . $options->index . "\n";

            return 0;
        }

        $rebuilt = (new AgentMapBuilder())->build(
            $options->root,
            array_keys($changed),
            $options->excludes,
            $options->phpStanConfig,
            $options->phpStanMemoryLimit,
            $index,
            $options->scanPaths,
        );
        (new IndexWriter())->write($rebuilt, $options->out, $options->format);
        echo 'Refreshed ' . count($changed) . ' changed and dropped ' . $removed . ' removed file(s); ' . count($rebuilt->files) . ' file(s) indexed in ' . $options->out . "\n";

        return 0;
    }

    /**
     * @param list<FileEntry> $files
     *
     * @return list<string>
     */
    private function indexedDirectories(array $files): array
    {
        $directories = [];
        foreach ($files as $file) {
            $separator = strrpos($file->path, '/');
            $directories[$separator === false ? '.' : substr($file->path, 0, $separator)] = true;
        }

        return array_keys($directories);
    }

    /**
     * `search-index build|refresh|doctor` - the derived index is a cache, so every subcommand can be
     * re-run at any time; none of them is required for the structural commands to work.
     */
    private function searchIndex(CliOptions $options): int
    {
        $subcommand = $options->argument ?? 'build';
        if (!in_array($subcommand, ['build', 'refresh', 'doctor'], true)) {
            fwrite(STDERR, 'Unknown search-index subcommand: ' . $subcommand . "\n");

            return 1;
        }

        if (!SearchIndexStore::supportsFts5()) {
            fwrite(STDERR, "[FAIL] SQLite FTS5 is not available in this PHP build; the search index cannot be created.\n");

            return 1;
        }

        $index = (new IndexReader())->read($options->index);
        $store = new SearchIndexStore($this->absolute($options->root, $options->database));

        if ($subcommand === 'doctor') {
            return $this->searchIndexDoctor($index, $store);
        }

        $stale = [];
        foreach ($index->staleEntries() as $entry) {
            $stale[] = $entry['path'];
        }
        if ($stale !== []) {
            fwrite(STDERR, '[FAIL] The map is stale for ' . count($stale) . " file(s); refresh it before indexing.\n");

            return 1;
        }

        $changedPaths = null;
        if ($subcommand === 'refresh') {
            $changedPaths = $this->changedSincePaths($index, $store);
            if ($changedPaths === []) {
                echo 'Search index is up to date: ' . $options->database . "\n";

                return 0;
            }
        }

        $chunks = (new ChunkExtractor())->extract($index, $changedPaths);
        $skipped = $store->replaceChunks($chunks, $changedPaths);
        $store->setMeta('map_snapshot', $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest);
        $store->setMeta('chunk_policy_version', (string)\voku\AgentMap\Search\ChunkPolicy::VERSION);

        $vectorNote = $this->embedChunks($store);

        echo 'Indexed ' . (count($chunks) - $skipped) . ' chunk(s) from ' . count($index->files) . ' file(s) into ' . $options->database . "\n";
        echo '- ' . $vectorNote . "\n";
        if ($skipped > 0) {
            echo '- ' . $skipped . " chunk(s) skipped: their canonical symbol id is declared more than once in this repository\n";
        }

        return 0;
    }

    /**
     * Embeds every chunk with the local corpus provider when sqlite-vec is loadable.
     *
     * Not attempted when the extension is missing: the index stays lexical and says so, which is the
     * whole point of probing by calling vec_version() rather than trusting a loader that only warns.
     */
    private function embedChunks(SearchIndexStore $store): string
    {
        if (!$store->enableVectorSupport()) {
            return 'vector channel unavailable (sqlite-vec not loadable); lexical only';
        }

        $documents = $store->allChunkContents();
        if ($documents === []) {
            return 'vector channel ready, nothing to embed';
        }

        $provider = new CorpusEmbeddingProvider();
        $provider->fit(array_map(static fn (array $row): string => $row['content'], $documents));
        $store->prepareVectorTable($provider->model());

        $vectors = [];
        foreach ($documents as $row) {
            $vectors[$row['chunk_id']] = $provider->embedQuery($row['content']);
        }
        $store->storeVectors($vectors, $provider->model());

        return 'embedded ' . count($vectors) . ' chunk(s) with ' . $provider->model()->provider . '/' . $provider->model()->model
            . ' (' . $provider->model()->dimensions . 'd, sqlite-vec ' . ($store->vectorVersion() ?? 'unknown') . ')';
    }

    /**
     * Rebuilds the same provider the index was written with. The corpus weighting is derived from
     * the stored chunks, so a query embeds into the same space without any model file on disk.
     */
    private function corpusProvider(SearchIndexStore $store): ?CorpusEmbeddingProvider
    {
        if (!$store->enableVectorSupport() || $store->vectorCount() === 0) {
            return null;
        }

        $documents = $store->allChunkContents();
        if ($documents === []) {
            return null;
        }

        $provider = new CorpusEmbeddingProvider();
        $provider->fit(array_map(static fn (array $row): string => $row['content'], $documents));

        return $provider->model()->fingerprint() === $store->meta('embedding_fingerprint') ? $provider : null;
    }

    private function searchIndexDoctor(AgentMapIndex $index, SearchIndexStore $store): int
    {
        $mapSnapshot = $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest;
        $indexSnapshot = $store->meta('map_snapshot') ?? 'sha256:none';
        $failures = $store->integrityFailures();

        echo "[OK] SQLite available\n";
        echo "[OK] FTS5 available\n";
        echo '[' . (SearchIndexStore::supportsFts5() ? 'OK' : 'FAIL') . "] lexical channel\n";
        if ($store->enableVectorSupport()) {
            echo '[OK] vector channel: sqlite-vec ' . ($store->vectorVersion() ?? 'unknown') . ', ' . $store->vectorCount() . " vector(s)\n";
        } else {
            $platform = \voku\AgentMap\Search\Embedding\SqliteVecBinary::platform();
            echo '[SKIP] vector channel: no sqlite-vec for ' . ($platform ?? PHP_OS_FAMILY . '/' . php_uname('m'))
                . '; search stays lexical and reports degraded (set '
                . \voku\AgentMap\Search\Embedding\SqliteVecBinary::ENVIRONMENT_OVERRIDE . " to point at your own build)\n";
        }
        echo '[' . ($mapSnapshot === $indexSnapshot ? 'OK' : 'FAIL') . '] map snapshot ' . ($mapSnapshot === $indexSnapshot ? 'matches' : 'differs from') . " search index\n";
        echo '[OK] indexed chunks: ' . $store->chunkCount() . "\n";
        foreach ($failures as $failure) {
            echo '[FAIL] ' . $failure . "\n";
        }

        return $failures === [] && $mapSnapshot === $indexSnapshot ? 0 : 1;
    }

    private function search(CliOptions $options): int
    {
        if ($options->argument === 'benchmark') {
            return $this->searchBenchmark($options);
        }

        if (!SearchIndexStore::supportsFts5()) {
            fwrite(STDERR, "[FAIL] SQLite FTS5 is not available in this PHP build.\n");

            return 1;
        }

        $index = (new IndexReader())->read($options->index);
        $store = new SearchIndexStore($this->absolute($options->root, $options->database));
        $result = (new HybridSearch(embeddings: $options->semantic ? $this->corpusProvider($store) : null))
            ->search($index, $store, (string)$options->argument, $options->limit);

        if ($options->format === 'json') {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

            return 0;
        }

        echo 'Search: ' . $result['query'] . "\n";
        echo '- mode: ' . $result['effective_mode'] . ($result['degraded'] ? ' (degraded: ' . $result['degraded_reason'] . ')' : '') . "\n";
        if ($result['structural_terms'] !== []) {
            echo '- structural terms: ' . implode(', ', $result['structural_terms']) . "\n";
        }
        if ($result['results'] === []) {
            echo "- no matching chunk; the structural commands remain the exact path\n";
        }
        foreach ($result['results'] as $hit) {
            echo sprintf(
                "  %-8.6f %s:%d-%d  %s [%s]\n",
                $hit['rrf_score'],
                $hit['file_path'],
                $hit['start_line'],
                $hit['end_line'],
                $hit['symbol_id'],
                implode(' ', $hit['reasons']),
            );
        }

        return 0;
    }

    /**
     * `search benchmark` - the gate for every later ranking change. It reports each channel
     * separately, because an aggregate number would hide a hybrid ranking that wins on conceptual
     * queries by losing on exact ones.
     */
    private function searchBenchmark(CliOptions $options): int
    {
        if (!SearchIndexStore::supportsFts5()) {
            fwrite(STDERR, "[FAIL] SQLite FTS5 is not available in this PHP build.\n");

            return 1;
        }

        $index = (new IndexReader())->read($options->index);
        $store = new SearchIndexStore($this->absolute($options->root, $options->database));
        $provider = $options->semantic ? $this->corpusProvider($store) : null;
        $report = (new SearchBenchmark(new HybridSearch(embeddings: $provider), $provider))
            ->run($index, $store, $this->absolute($options->root, $options->cases));

        if ($options->format === 'json') {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

            return 0;
        }

        echo 'Benchmark: ' . $report['case_count'] . " case(s)\n";
        /** @var array<string, array<string, array{cases: int, recall_at_5: float, mrr_at_10: float}>> $categories */
        $categories = $report['categories'];
        printf("%-22s %-12s %6s %10s %10s\n", 'category', 'channel', 'cases', 'recall@5', 'mrr@10');
        foreach ($categories as $category => $channels) {
            foreach ($channels as $channel => $metrics) {
                printf(
                    "%-22s %-12s %6d %10.3f %10.3f\n",
                    $category,
                    $channel,
                    $metrics['cases'],
                    $metrics['recall_at_5'],
                    $metrics['mrr_at_10'],
                );
            }
        }

        return 0;
    }

    /**
     * Files whose indexed source hash no longer matches the map. The map itself is verified fresh
     * before this runs, so a difference here means the chunks are behind, not the map.
     *
     * @return list<string>
     */
    private function changedSincePaths(AgentMapIndex $index, SearchIndexStore $store): array
    {
        $indexSnapshot = $store->meta('map_snapshot');
        $mapSnapshot = $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest;
        if ($indexSnapshot === $mapSnapshot && $store->chunkCount() > 0) {
            return [];
        }

        $paths = [];
        foreach ($index->files as $file) {
            $paths[] = $file->path;
        }

        return $paths;
    }

    private function absolute(string $root, string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($root, '/') . '/' . $path;
    }

    private function query(CliOptions $options): int
    {
        $index = (new IndexReader())->readSections($options->index, ['files']);
        $this->warnIfStale($index->staleEntries());
        $result = $index->query((string) $options->argument);
        $files = array_slice($result->files, 0, $options->limit);
        echo $this->formatter->render([
            'type' => 'query',
            'title' => (string) $options->argument,
            'query' => (string) $options->argument,
            'match_type' => $result->matchType,
            'files' => $this->formatter->filesPayload($files, $options->symbolLimit, $options->methodLimit),
            'include_namespace' => false,
        ], $options->format);

        return 0;
    }

    private function file(CliOptions $options): int
    {
        $index = (new IndexReader())->readSections($options->index, ['files']);
        $file = $index->file((string) $options->argument);
        if ($file === null) {
            fwrite(STDERR, 'File not found in index: ' . $options->argument . "\n");
            return 1;
        }

        $this->warnIfStale($index->staleEntries());
        echo $this->formatter->render([
            'type' => 'file',
            'title' => $file->path,
            'files' => [$this->formatter->filePayload($file, $options->symbolLimit, $options->methodLimit)],
            'include_namespace' => true,
        ], $options->format);

        return 0;
    }

    private function stale(CliOptions $options): int
    {
        $index = (new IndexReader())->readSections($options->index, ['files']);
        $stale = $index->staleEntries();
        if ($stale === []) {
            echo "OK\n";
            return 0;
        }

        foreach ($stale as $entry) {
            echo 'STALE ' . $entry['path'] . ' ' . $entry['reason'] . "\n";
        }

        return 1;
    }

    private function summary(CliOptions $options): int
    {
        $index = (new IndexReader())->readSections($options->index, ['files']);
        $this->warnIfStale($index->staleEntries());
        echo $this->formatter->render([
            'type' => 'summary',
            'title' => 'Agent Map Summary',
            ...$index->summaryCounts(),
            'top_namespaces' => $index->topNamespaces(5),
            'top_directories' => $index->topDirectories(5),
            'entrypoints' => $this->entrypoints($index->root),
        ], $options->format);

        return 0;
    }

    private function stats(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $this->warnIfStale($index->staleEntries());
        echo $this->formatter->render([
            'type' => 'stats',
            'title' => 'Agent Map Stats',
            'files' => count($index->files),
            'symbols' => $index->summaryCounts()['symbols'],
            'methods' => $index->methodCount(),
            'index_size' => $this->humanSize($options->index),
            'largest_files' => $index->largestFiles($options->limit),
        ], $options->format);

        return 0;
    }

    private function changed(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $this->warnIfStale($index->staleEntries());
        $changed = $this->changedPhpFiles($index->root, $options->base);
        $files = [];
        $unindexed = [];
        foreach ($changed as $path) {
            $file = $index->file($path);
            if ($file === null) {
                $unindexed[] = $path;
            } else {
                $files[] = $file;
            }
        }

        echo $this->formatter->render([
            'type' => 'changed',
            'title' => 'Changed PHP files',
            'base' => $options->base,
            'files' => $this->formatter->filesPayload(array_slice($files, 0, $options->limit), $options->symbolLimit, $options->methodLimit),
            'unindexed' => array_slice($unindexed, 0, $options->limit),
        ], $options->format);

        return $changed === [] ? 1 : 0;
    }

    private function related(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $this->warnIfStale($index->staleEntries());
        $result = $index->query((string) $options->argument);
        $sourceMatches = array_values(array_filter($result->files, fn (FileEntry $file): bool => !$this->looksLikeTestPath($file->path)));
        if ($sourceMatches === []) {
            $sourceMatches = $result->files;
        }

        $primary = array_slice($sourceMatches, 0, $options->limit);
        $contextSources = array_slice($sourceMatches, 0, max(10, $options->limit * 3));
        $likelyTests = $index->likelyTestFilesFor($contextSources, $options->limit);
        $sameNamespace = $index->sameNamespaceFilesFor($contextSources, $options->limit);
        $mentions = $this->mentionFiles($index, (string) $options->argument, [...$primary, ...$likelyTests], $options->limit);

        // likely_tests/same_namespace/mentions are context, not the answer to
        // the query: a full per-file symbol/method dump on all three (on top
        // of primary) is what made a default `related` call ~15x bigger than
        // a focused query for the same term. They keep the requested --limit
        // file count but render as bare paths; primary keeps full detail.
        echo $this->formatter->render([
            'type' => 'related',
            'title' => 'Related: ' . (string) $options->argument,
            'query' => (string) $options->argument,
            'match_type' => $result->matchType,
            'primary' => $this->formatter->filesPayload($primary, $options->symbolLimit, $options->methodLimit),
            'likely_tests' => $this->formatter->filesPayload($likelyTests, 0, 0),
            'same_namespace' => $this->formatter->filesPayload($sameNamespace, 0, 0),
            'mentions' => $this->formatter->filesPayload($mentions, 0, 0),
        ], $options->format);

        return $primary === [] ? 1 : 0;
    }

    private function scope(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        if ($index->staleEntries() !== []) {
            throw new RuntimeException('Agent map is stale. Rebuild it before inspecting a scope.');
        }

        $selection = (new ScopeSelector())->select($index, (string) $options->argument);
        if ($selection->status === 'not_found') {
            fwrite(STDERR, 'No indexed class, method, or function matches: ' . $options->argument . "\n");

            return 1;
        }

        if ($selection->status === 'ambiguous') {
            echo $this->formatter->render([
                'type' => 'scope_ambiguous',
                'title' => 'Ambiguous: ' . (string) $options->argument,
                'query' => (string) $options->argument,
                'candidates' => $selection->candidates,
            ], $options->format);

            return 1;
        }

        $target = $selection->target;
        if ($target === null) {
            return 1;
        }

        $inspection = (new ScopeInspector())->inspect($index, $target, $options->limit);
        echo $this->formatter->render([
            'type' => 'scope',
            'title' => $target->label,
            ...$inspection->toArray(),
        ], $options->format);

        return 0;
    }

    private function relations(CliOptions $options, bool $incoming): int
    {
        $index = (new IndexReader())->read($options->index);
        $this->warnIfStale($index->staleEntries());
        $method = $index->resolveMethod((string) $options->argument);
        if ($incoming) {
            $targetIds = [$method->id => true];
            foreach ($index->outgoing($method->id, 'overrides') as $contractRelation) {
                foreach ($contractRelation->targetIds as $targetId) {
                    $targetIds[$targetId] = true;
                }
            }
            foreach ($index->incoming($method->id, 'overrides') as $overrideRelation) {
                $targetIds[$overrideRelation->sourceId] = true;
            }
            $relationById = [];
            foreach (array_keys($targetIds) as $targetId) {
                foreach ($index->incoming($targetId, 'calls') as $relation) {
                    $relationById[$relation->id] = $relation;
                }
            }
            ksort($relationById, SORT_STRING);
            $relations = array_values($relationById);
        } else {
            $relations = $index->outgoing($method->id, 'calls');
        }
        $relations = array_slice($relations, 0, $options->limit);
        echo $this->formatter->render([
            'type' => 'relations',
            'title' => ($incoming ? 'Callers of ' : 'Callees of ') . $method->owner->fqn . '::' . $method->method->name,
            'target' => $method->id,
            'relations' => array_map(static fn ($relation): array => $relation->toArray(), $relations),
        ], $options->format);

        return $relations === [] ? 1 : 0;
    }

    private function context(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);
        $plan = (new EditContextPlanner())->plan(
            $index,
            (string) $options->argument,
            new EditContextPolicy(
                maximumSourceBytes: $options->contextBudget,
                maximumFiles: $options->maxFiles,
                maximumCallers: $options->maxCallers,
                maximumCallees: $options->maxCallees,
                maximumTests: $options->maxTests,
                maximumTypeDefinitions: $options->maxTypeDefinitions,
            ),
        );
        echo $this->formatter->render($plan->toArray(), $options->format);

        return 0;
    }

    /**
     * @param list<array{path: string, reason: string}> $stale
     */
    private function warnIfStale(array $stale): void
    {
        if ($stale !== []) {
            fwrite(STDERR, "WARNING: index is stale. Rebuild it with agent-map build.\n");
        }
    }

    /**
     * @return list<string>
     */
    private function entrypoints(string $root): array
    {
        $entrypoints = [];
        foreach (glob($root . '/bin/*') ?: [] as $file) {
            if (is_file($file)) {
                $entrypoints[] = 'bin/' . basename($file);
            }
        }

        foreach (glob($root . '/src/*Cli*.php') ?: [] as $file) {
            if (is_file($file)) {
                $entrypoints[] = 'src/' . basename($file);
            }
        }

        foreach (glob($root . '/scripts/private/*cli*.php') ?: [] as $file) {
            if (is_file($file)) {
                $entrypoints[] = 'scripts/private/' . basename($file);
            }
        }

        foreach (glob($root . '/scripts/private/*_cli.php') ?: [] as $file) {
            if (is_file($file)) {
                $entrypoints[] = 'scripts/private/' . basename($file);
            }
        }

        $entrypoints = array_values(array_unique($entrypoints));
        sort($entrypoints);

        return array_slice($entrypoints, 0, 10);
    }

    /**
     * @return list<string>
     */
    private function changedPhpFiles(string $root, string $base): array
    {
        if (!is_dir($root . '/.git')) {
            throw new RuntimeException('Changed requires a Git repository at index root: ' . $root);
        }

        $files = [
            ...$this->gitChangedPhpFiles($root, ['diff', '--name-only', $base . '...HEAD', '--', '*.php'], true),
            ...$this->gitChangedPhpFiles($root, ['diff', '--name-only', '--', '*.php']),
            ...$this->gitChangedPhpFiles($root, ['diff', '--cached', '--name-only', '--', '*.php']),
            ...$this->gitChangedPhpFiles($root, ['ls-files', '--others', '--exclude-standard', '--', '*.php']),
        ];

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function gitChangedPhpFiles(string $root, array $args, bool $allowFailure = false): array
    {
        $process = proc_open(
            ['git', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start git diff.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            if ($allowFailure) {
                fwrite(STDERR, 'WARNING: git diff failed for base comparison; continuing with working-tree changes.' . "\n");

                return [];
            }

            throw new RuntimeException(trim((string) $stderr) ?: 'git diff failed');
        }

        $files = [];
        foreach (explode("\n", trim((string) $stdout)) as $line) {
            $line = trim($line);
            if ($line !== '' && str_ends_with($line, '.php')) {
                $files[] = str_replace('\\', '/', $line);
            }
        }

        return $files;
    }

    /**
     * @param list<FileEntry> $primary
     * @return list<FileEntry>
     */
    private function mentionFiles(AgentMapIndex $index, string $term, array $primary, int $limit): array
    {
        $primaryPaths = array_map(static fn (FileEntry $file): string => $file->path, $primary);
        $matches = [];
        foreach ($index->files as $file) {
            if ($file->symbols === [] || in_array($file->path, $primaryPaths, true)) {
                continue;
            }

            $absolute = $index->root . '/' . $file->path;
            if (is_file($absolute) && str_contains((string) file_get_contents($absolute), $term)) {
                $matches[] = $file;
            }

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    private function looksLikeTestPath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        $segments = explode('/', $normalized);
        $fileName = array_pop($segments);

        return in_array('tests', $segments, true)
            || in_array('test', $segments, true)
            || str_ends_with($fileName, 'test.php')
            || str_ends_with($fileName, 'cest.php');
    }

    private function humanSize(string $path): string
    {
        if (!is_file($path)) {
            return 'unknown';
        }

        $bytes = filesize($path);
        if (!is_int($bytes)) {
            return 'unknown';
        }

        return $bytes < 1024 ? $bytes . ' B' : round($bytes / 1024, 1) . ' KB';
    }

    private function help(string $command): string
    {
        if ($command === 'build' || $command === 'refresh') {
            return <<<'TXT'
            Usage:
              agent-map build [--root=.] [--paths=src,tests] [--scan=vendor/acme] [--out=.agent-map/php-symbols.json] [--format=json|toon] [--phpstan-config=phpstan.neon] [--phpstan-memory-limit=512M] [--exclude=REGEX] [--merge]
              agent-map refresh [--root=.] [--index=.agent-map/php-symbols.json] [--out=.agent-map/php-symbols.json]

            Build a PHPStan-enriched repository map. JSON is the default; TOON is optional. --exclude is repeatable.

            --scan lists directories that only have to resolve symbols (never indexed): use it when the
            analysed scope references classes living outside it, otherwise their types stay unresolved.

            --merge patches the existing --out index instead of replacing it, so a narrow --paths scope
            keeps everything it did not cover. refresh does the same automatically for the files whose
            hash moved, plus new files, minus deleted ones - the cheap way to keep a large map current.

            Keeping --paths on directories (no --exclude) lets PHPStan reuse its result cache; passing
            individual files disables that cache and re-analyses everything from scratch.
            TXT;
        }

        return <<<'TXT'
        agent-map - compact PHP symbol maps for coding agents

        Usage:
          agent-map build --root=. --paths=src,tests --out=.agent-map/php-symbols.json
          agent-map refresh --root=. --index=.agent-map/php-symbols.json
          agent-map query EvidenceValidator --index=.agent-map/php-symbols.json
          agent-map file src/EvidenceValidator.php --index=.agent-map/php-symbols.json
          agent-map stale --index=.agent-map/php-symbols.json
          agent-map summary --index=.agent-map/php-symbols.json
          agent-map changed --index=.agent-map/php-symbols.json --base=main
          agent-map related EvidenceValidator --index=.agent-map/php-symbols.json
          agent-map stats --index=.agent-map/php-symbols.json
          agent-map scope 'App\Service\UserService::save' --index=.agent-map/php-symbols.json
          agent-map callers 'Foo::bar' --index=.agent-map/php-symbols.json
          agent-map callees 'Foo::bar' --index=.agent-map/php-symbols.json
          agent-map context 'Foo::bar' --index=.agent-map/php-symbols.json --format=toon
          agent-map help

        Options:
          --format=text|json|markdown|toon
          --scan=lib,vendor/acme --merge
          --limit=20
          --symbol-limit=10
          --method-limit=10
          --context-budget=60000
          --max-files=20 --max-callers=10 --max-callees=10 --max-tests=10
          --max-type-definitions=10

        TXT;
    }
}
