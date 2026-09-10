<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use RuntimeException;
use Throwable;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\SemanticScope;
use voku\AgentMap\IO\PhpFileFinder;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Search\ChunkExtractor;
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Search\Embedding\CorpusEmbeddingProvider;
use voku\AgentMap\Search\Embedding\EmbeddingVector;
use voku\AgentMap\Search\SearchBenchmark;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentMap\Inspect\ScopeInspector;
use voku\AgentMap\Inspect\ScopeSelector;

final readonly class AgentMapApplication
{
    public function __construct(
        private OutputFormatter $formatter = new OutputFormatter(),
        private ?MapArtifactPaths $artifacts = null,
        private ?string $defaultRoot = null,
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        array_shift($argv);

        try {
            $options = CliOptions::parse($argv, $this->artifacts, $this->defaultRoot);
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

        $structural = $options->backend === 'structural';
        $index = $this->builder($options)->build(
            $options->root,
            $options->paths,
            $options->excludes,
            $structural ? null : $options->phpStanConfig,
            $structural ? null : $options->phpStanMemoryLimit,
            $previous,
            $structural ? [] : $options->scanPaths,
        );
        (new IndexWriter())->write($index, $options->out, $options->format);
        echo 'Wrote ' . count($index->files) . ' file(s), ' . count($index->relations) . ' relation(s), and ' . count($index->diagnostics) . ' diagnostic(s) to ' . $options->out . "\n";

        return 0;
    }

    /**
     * Rebuilds what the index no longer matches.
     *
     * Structural-only refresh can safely patch changed files because its facts are source-local.
     * PHPStan-backed refresh instead rebuilds the complete current indexed scope through the
     * structural cache and lets PHPStan's result cache decide which changed files and transitive
     * dependents require semantic re-analysis. Carrying untouched semantic relations would make a
     * declaration change leave stale facts in otherwise unchanged callers.
     */
    private function refresh(CliOptions $options): int
    {
        $index = (new IndexReader())->read($options->index);

        $structural = $options->backend === 'structural';
        $phpStanRefresh = !$structural
            && PhpStanSemanticAnalyzer::isAvailable()
            && str_ends_with($index->backend, '+phpstan');
        $semanticScope = $this->semanticScope($index, $options);

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
        $searchPaths = $phpStanRefresh
            ? $semanticScope->paths
            : ($options->paths === ['.'] ? $this->indexedDirectories($index->files) : $options->paths);
        $searchExcludes = $phpStanRefresh ? $semanticScope->excludes : $options->excludes;
        foreach ((new PhpFileFinder())->find($options->root, $searchPaths, $searchExcludes) as $relative) {
            if (!isset($indexed[$relative])) {
                $changed[$relative] = true;
            }
        }

        $semanticInputsChanged = $phpStanRefresh && $this->semanticInputsChanged($index, $options, $semanticScope);
        if ($changed === [] && $removed === 0 && !$semanticInputsChanged) {
            echo 'Index is up to date: ' . $options->index . "\n";

            return 0;
        }

        if ($changed === [] && !$phpStanRefresh) {
            // Only removals, and no semantic facts to invalidate. Passing an empty
            // path list to build() makes the file finder fall back to walking the
            // root and re-analysing everything, so deleting one file cost a full
            // rebuild. Structural facts are source-local, so dropping the missing
            // entries is the whole job.
            //
            // A PHPStan-backed index deliberately does not take this shortcut: a
            // deleted declaration also invalidates its dependents, and those live
            // in files whose own hash never moved.
            $missing = [];
            foreach ($index->staleEntries() as $entry) {
                if ($entry['reason'] === 'missing') {
                    $missing[$entry['path']] = true;
                }
            }
            $pruned = new AgentMapIndex(
                schemaVersion: $index->schemaVersion,
                root: $index->root,
                backend: $index->backend,
                files: array_values(array_filter(
                    $index->files,
                    static fn ($file): bool => !isset($missing[$file->path]),
                )),
                relations: array_values(array_filter(
                    $index->relations,
                    static fn ($relation): bool => !isset($missing[$relation->file]),
                )),
                diagnostics: array_values(array_filter(
                    $index->diagnostics,
                    static fn ($diagnostic): bool => $diagnostic->file === null || !isset($missing[$diagnostic->file]),
                )),
                fingerprint: $index->fingerprint,
            );
            (new IndexWriter())->write($pruned, $options->out, $options->format);
            echo 'Refreshed 0 changed and dropped ' . $removed . ' removed file(s); ' . count($pruned->files) . ' file(s) indexed in ' . $options->out . "\n";

            return 0;
        }

        $builder = $this->builder($options);
        if (!$phpStanRefresh && $index->backend !== $builder->backend()) {
            // The merge below would refuse, and its message names the remedy in
            // prose while this method holds every argument the remedy needs.
            // A host that follows the prescribed command literally otherwise
            // loops: refresh -> refusal -> refresh.
            // The command has to rebuild *this* index, not something adjacent to
            // it. The refresh search scope widens a nested coverage such as
            // `src/Feature` to its first segment, so the repair takes the scope
            // the index recorded; and a build with no --backend resolves `auto`,
            // which would quietly move a structural-only index onto PHPStan.
            $structuralOnly = str_ends_with($index->backend, '+structural-only');
            throw new RuntimeException(
                'Cannot refresh ' . $options->index . ': it carries backend "' . $index->backend
                . '" and this run resolves "' . $builder->backend()
                . '". An incremental refresh cannot merge two semantic backends. Run a full build:'
                . "\n  agent-map build"
                . ' --root=' . self::shellArgument($options->root)
                . ' --paths=' . self::shellArgument(implode(',', $semanticScope->paths))
                . ' --out=' . self::shellArgument($options->out)
                . ($structuralOnly ? ' --backend=structural' : '')
                . ($structuralOnly
                    ? ''
                    : "\nThe rebuilt index will carry \"" . $builder->backend() . '", not "' . $index->backend . '".'),
            );
        }

        $rebuilt = $builder->build(
            $options->root,
            $phpStanRefresh ? $semanticScope->paths : array_keys($changed),
            $phpStanRefresh ? $semanticScope->excludes : $options->excludes,
            $structural ? null : $options->phpStanConfig,
            $structural ? null : $options->phpStanMemoryLimit,
            $phpStanRefresh ? null : $index,
            $structural ? [] : ($phpStanRefresh ? $semanticScope->scanDirectories : $options->scanPaths),
        );
        (new IndexWriter())->write($rebuilt, $options->out, $options->format);
        echo 'Refreshed ' . count($changed) . ' changed and dropped ' . $removed . ' removed file(s); ' . count($rebuilt->files) . ' file(s) indexed in ' . $options->out . "\n";

        return 0;
    }

    private function semanticScope(AgentMapIndex $index, CliOptions $options): SemanticScope
    {
        $stored = $index->fingerprint?->semanticScope;
        if ($stored === null) {
            return new SemanticScope(
                paths: $options->pathsProvided ? $options->paths : $this->indexedDirectories($index->files),
                excludes: $options->excludes,
                scanDirectories: $options->scanPaths,
            );
        }

        return new SemanticScope(
            paths: $options->pathsProvided ? $options->paths : $stored->paths,
            excludes: $options->excludesProvided ? $options->excludes : $stored->excludes,
            scanDirectories: $options->scanPathsProvided ? $options->scanPaths : $stored->scanDirectories,
        );
    }

    private function semanticInputsChanged(AgentMapIndex $index, CliOptions $options, SemanticScope $scope): bool
    {
        $fingerprint = $index->fingerprint;
        if ($fingerprint === null || $fingerprint->semanticScope === null) {
            return true;
        }
        if ($fingerprint->semanticScope->identitySha256() !== $scope->identitySha256()) {
            return true;
        }

        $configuration = AgentMapBuilder::resolvePhpStanConfiguration($index->root, $options->phpStanConfig);
        $configurationHash = $configuration === null
            ? 'sha256:' . hash('sha256', 'default-level-0')
            : 'sha256:' . (string) hash_file('sha256', $configuration);
        if ($fingerprint->phpStanConfigSha256 !== $configurationHash) {
            return true;
        }

        $composerLockHash = is_file($index->root . '/composer.lock') ? hash_file('sha256', $index->root . '/composer.lock') : false;

        return $fingerprint->composerLockSha256 !== (is_string($composerLockHash) ? 'sha256:' . $composerLockHash : 'sha256:none');
    }

    /**
     * One shell argument, quoted only when it would not survive being copied.
     *
     * A remedy that has to be re-quoted by hand before it runs is the same
     * defect as a remedy written in prose, and a project path containing a
     * space is not exotic. Values that need no quoting are printed bare so the
     * ordinary command stays readable.
     */
    private static function shellArgument(string $value): string
    {
        return preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $value) === 1 ? $value : escapeshellarg($value);
    }

    private function builder(CliOptions $options): AgentMapBuilder
    {
        $semanticAnalyzer = match ($options->backend) {
            'auto' => null,
            'structural' => new StructuralOnlySemanticAnalyzer(),
            'phpstan' => new PhpStanSemanticAnalyzer($options->artifacts),
        };

        return new AgentMapBuilder(
            semanticAnalyzer: $semanticAnalyzer,
            artifacts: $options->artifacts,
        );
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
            $separator = strpos($file->path, '/');
            $directories[$separator === false ? '.' : substr($file->path, 0, $separator)] = true;
        }

        if (isset($directories['.'])) {
            return ['.'];
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
                // No chunk needs re-extraction, but the map identity can still have
                // moved: a rebuild produces a new source digest, and files can leave
                // the scope without any surviving file changing. Returning here
                // without reconciling left the recorded snapshot stale forever, so
                // `search doctor` reported a mismatch that no later refresh could
                // clear, and chunks of dropped files stayed searchable.
                $mapPaths = [];
                foreach ($index->files as $file) {
                    $mapPaths[] = $file->path;
                }
                $pruned = $store->pruneMissingPaths($mapPaths);
                $store->setMeta('map_snapshot', $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest);
                $store->setMeta('chunk_policy_version', (string)\voku\AgentMap\Search\ChunkPolicy::VERSION);

                echo 'Search index is up to date: ' . $options->database . "\n";
                if ($pruned > 0) {
                    echo '- ' . $pruned . " file(s) pruned: no longer part of the map\n";
                }

                return 0;
            }
        }

        $extractor = new ChunkExtractor();
        $chunks = $extractor->extract($index, $changedPaths);
        $skipped = $store->replaceChunks($chunks, $changedPaths);

        // A refresh names the files it rebuilt; a deleted file is not among them and would otherwise
        // keep its chunks. The map is the authority on what still exists.
        $mapPaths = [];
        foreach ($index->files as $file) {
            $mapPaths[] = $file->path;
        }
        $pruned = $store->pruneMissingPaths($mapPaths);
        $store->setMeta('map_snapshot', $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest);
        $store->setMeta('chunk_policy_version', (string)\voku\AgentMap\Search\ChunkPolicy::VERSION);

        $vectorNote = $this->embedChunks($store, $changedPaths);

        echo 'Indexed ' . (count($chunks) - $skipped) . ' chunk(s) from ' . count($index->files) . ' file(s) into ' . $options->database . "\n";
        echo '- ' . $vectorNote . "\n";
        if ($extractor->skippedPaths() !== []) {
            echo '- ' . count($extractor->skippedPaths()) . " file(s) not indexed: their source changed after the map was built\n";
        }
        if ($pruned > 0) {
            echo '- ' . $pruned . " file(s) pruned: no longer part of the map\n";
        }
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
    /** @param list<string>|null $changedPaths */
    private function embedChunks(SearchIndexStore $store, ?array $changedPaths): string
    {
        if (!$store->enableVectorSupport()) {
            return 'vector channel unavailable (sqlite-vec not loadable); lexical only';
        }

        $provider = $changedPaths === null ? null : $this->corpusProvider($store);
        if ($provider === null) {
            // A full build, or a weighting that no longer matches: refit and start the vector table
            // over. Refitting is what changes the model fingerprint, so it stays a build decision.
            $all = $store->allChunkContents();
            if ($all === []) {
                return 'vector channel ready, nothing to embed';
            }

            $provider = new CorpusEmbeddingProvider();
            $provider->fit(array_map(static fn (array $row): string => $row['content'], $all));
            $changedPaths = null;
        }

        $model = $provider->model();
        $store->prepareVectorTable($model);
        $store->storeEmbeddingState($provider);

        $documents = $store->chunkContentsForPaths($changedPaths);
        if ($documents === []) {
            return 'vector channel ready, nothing to embed';
        }

        $cached = $store->cachedEmbeddings(array_map(static fn (array $row): string => $row['content_sha256'], $documents), $model);

        $blobs = [];
        $fresh = [];
        foreach ($documents as $row) {
            $blob = $cached[$row['content_sha256']] ?? null;
            if ($blob === null) {
                $blob = EmbeddingVector::encode($provider->embedQuery($row['content']), $model->dimensions);
                $fresh[$row['content_sha256']] = $blob;
            }

            $blobs[$row['chunk_id']] = $blob;
        }

        $store->cacheEmbeddings($fresh, $model);
        $store->storeVectorBlobs($blobs);

        return sprintf(
            'embedded %d chunk(s), %d reused from cache, with %s/%s (%dd, sqlite-vec %s)',
            count($fresh),
            count($blobs) - count($fresh),
            $model->provider,
            $model->model,
            $model->dimensions,
            $store->vectorVersion() ?? 'unknown',
        );
    }

    /**
     * Rebuilds the same provider the index was written with. The corpus weighting is derived from
     * the stored chunks, so a query embeds into the same space without any model file on disk.
     *
     * The restoration itself belongs to the store, which owns the metadata it reads; the CLI is
     * one caller of it rather than the definition of it.
     */
    private function corpusProvider(SearchIndexStore $store): ?CorpusEmbeddingProvider
    {
        return $store->semanticProvider();
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
            $platform = \voku\AgentGraph\Sqlite\SqliteVecBinary::platform();
            echo '[SKIP] vector channel: no sqlite-vec for ' . ($platform ?? PHP_OS_FAMILY . '/' . php_uname('m'))
                . '; search stays lexical and reports degraded (set '
                . \voku\AgentGraph\Sqlite\SqliteVecBinary::ENVIRONMENT_OVERRIDE . " to point at your own build)\n";
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

        // Per file, not per map digest: one edited file changes the map digest, and re-extracting
        // every file because of it turns a refresh into a rebuild.
        $indexed = $store->sourceHashesByPath();
        $paths = [];
        foreach ($index->files as $file) {
            if (($indexed[$file->path] ?? null) !== $file->sha256) {
                $paths[] = $file->path;
            }
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
        $index = (new IndexReader())->readSections($options->index, ['files']);
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
        $index = (new IndexReader())->readSections($options->index, ['files']);
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

    /**
     * Read the index an exact answer needs, bringing it current when that is safe.
     *
     * A stale index used to end the conversation: `scope` threw "Agent map is
     * stale. Rebuild it before inspecting a scope." with no index path, no stale
     * count and no command, so the host had to remember `stale` -> `refresh` ->
     * re-issue. Editing an unrelated Markdown file was enough to trigger it, and a
     * tool that answers a maintenance errand instead of the question loses to `rg`,
     * which has no freshness concept at all.
     *
     * Repair here is deliberately narrow. It runs only when the index and this run
     * resolve the same backend, which is the same guard `refresh` applies before
     * merging, so PHPStan evidence is never quietly downgraded to structural-only.
     * It reuses the scope the index recorded rather than the wider search scope, so
     * indexed coverage cannot widen. Anything else keeps failing closed, and now
     * says which index it judged and exactly what to run.
     */
    /**
     * The observation scope the index itself recorded, ignoring this command's flags.
     *
     * `semanticScope()` deliberately honours `--paths`, `--exclude` and `--scan`,
     * which is right for `build` and `refresh`: those commands exist to change what
     * is observed. `CliOptions` accepts the same flags on read commands, so reusing
     * that method here would let `scope Foo::bar --paths=other/place` persist a
     * different observation scope as a side effect of asking a question. A read
     * answers from the configured envelope; it never reconfigures it.
     */
    private function recordedSemanticScope(AgentMapIndex $index): SemanticScope
    {
        $stored = $index->fingerprint?->semanticScope;
        if ($stored === null) {
            return new SemanticScope(
                paths: $this->indexedDirectories($index->files),
                excludes: [],
                scanDirectories: [],
            );
        }

        return new SemanticScope(
            paths: $stored->paths,
            excludes: $stored->excludes,
            scanDirectories: $stored->scanDirectories,
        );
    }

    /**
     * The same incremental merge `refresh` performs, for one read.
     *
     * Returns the refreshed index, or null plus the reason it could not be produced.
     * A parse failure, a PHPStan failure and an I/O failure are different problems
     * and the host needs to know which one it has; collapsing them all into "refresh
     * it explicitly" turns a diagnosable fault into a maintenance errand.
     *
     * The note goes to STDERR: a repair that changed the index on disk is never
     * silent, and it stays out of the machine-readable answer on STDOUT.
     *
     * @return array{0: AgentMapIndex|null, 1: Throwable|null}
     */
    private function refreshForRead(
        CliOptions $options,
        AgentMapIndex $index,
        AgentMapBuilder $builder,
        SemanticScope $semanticScope,
        string $format,
    ): array {
        $structural = $options->backend === 'structural';
        $phpStanRefresh = !$structural
            && PhpStanSemanticAnalyzer::isAvailable()
            && str_ends_with($index->backend, '+phpstan');

        $stale = $index->staleEntries();
        $changed = [];
        foreach ($stale as $entry) {
            if ($entry['reason'] !== 'missing') {
                $changed[$entry['path']] = true;
            }
        }

        // Announced before the work, not after: a PHPStan-backed repair re-analyses the
        // recorded semantic scope and can take tens of seconds, which is the same work a
        // manual `refresh` would do but arrives unannounced in the middle of a read that
        // is normally instant. The host should see why it is waiting while it waits.
            // Deleted files are stale too, and they are not in `$changed`: counting that
        // list alone announced "Repairing 0 stale file(s)" for a removal.
        fwrite(
            \STDERR,
            'Repairing ' . count($stale) . ' stale file(s) in ' . $options->index . " before answering.\n",
        );

        try {
            // Build against the scope the index recorded, never the list of files that
            // happened to change: the builder writes the requested paths into the new
            // fingerprint, so passing the changed files would shrink the recorded scope
            // to whatever was edited last and a later refresh would stop looking
            // anywhere else.
            // Every observation input comes from the index, not from this command.
            // `$options->root` is the working directory the question was asked from, so
            // a stale read run from elsewhere would rebuild the index against the wrong
            // project; and taking excludes or scan directories from the read would drop
            // or add coverage the index recorded.
            $rebuilt = $builder->build(
                $index->root,
                $semanticScope->paths,
                $semanticScope->excludes,
                $structural ? null : $options->phpStanConfig,
                $structural ? null : $options->phpStanMemoryLimit,
                $phpStanRefresh ? null : $index,
                $structural ? [] : $semanticScope->scanDirectories,
            );
            (new IndexWriter())->write($rebuilt, $options->index, $format);
        } catch (Throwable $exception) {
            return [null, $exception];
        }

        return [$rebuilt, null];
    }

    private function currentIndexForRead(CliOptions $options): AgentMapIndex
    {
        $index = (new IndexReader())->read($options->index);
        $builder = $this->builder($options);
        $semanticScope = $this->recordedSemanticScope($index);

        // Source hashes are not the whole definition of "current". A PHPStan-backed
        // index also depends on its configuration and on composer.lock, and those can
        // move while every indexed file's hash stays identical. `refresh` already knows
        // this; a read that answered from stale semantic evidence would be reporting
        // callers and types from the previous observation envelope, which is exactly
        // what the PHPStan backend is being paid for. The `+phpstan` gate mirrors
        // refresh: without a stored semantic scope the check always reports a change,
        // which would repair a structural index on every single read.
        $semanticInputsChanged = str_ends_with($index->backend, '+phpstan')
            && PhpStanSemanticAnalyzer::isAvailable()
            && $this->semanticInputsChanged($index, $options, $semanticScope);

        if ($index->staleEntries() === [] && !$semanticInputsChanged) {
            return $index;
        }
        if ($index->backend !== $builder->backend()) {
            $structuralOnly = str_ends_with($index->backend, '+structural-only');

            throw new RuntimeException(
                'Cannot use ' . $options->index . ': it is stale in ' . count($index->staleEntries())
                . ' file(s), it carries backend "' . $index->backend . '" and this run resolves "'
                . $builder->backend() . '". An incremental repair cannot merge two semantic backends.'
                . ' Run a full build:'
                . "\n  agent-map build"
                . ' --root=' . self::shellArgument($index->root)
                . ' --paths=' . self::shellArgument(implode(',', $semanticScope->paths))
                . ' --out=' . self::shellArgument($options->index)
                . ($structuralOnly ? ' --backend=structural' : '')
                . ($structuralOnly
                    ? ''
                    : "\nThe rebuilt index will carry \"" . $builder->backend() . '", not "' . $index->backend . '".'),
            );
        }

        $format = (new IndexReader())->detectFormat($options->index);
        [$refreshed, $failure] = $this->refreshForRead($options, $index, $builder, $semanticScope, $format);
        if ($refreshed === null) {
            throw new RuntimeException(
                'Cannot use ' . $options->index . ': the repair failed with: '
                . ($failure?->getMessage() ?? 'no reported reason')
                . "\nThe index was left unchanged. Refresh it explicitly:"
                . "\n  agent-map refresh --index=" . self::shellArgument($options->index)
                // Without the recorded root the remedy targets whatever directory it is
                // pasted into, and without the backend a structural index resolves `auto`
                // and refuses on the mismatch this repair already avoided.
                . ' --root=' . self::shellArgument($index->root)
                . ' --backend=' . (str_ends_with($index->backend, '+phpstan') ? 'phpstan' : 'structural'),
                previous: $failure,
            );
        }

        return $refreshed;
    }

    private function scope(CliOptions $options): int
    {
        $index = $this->currentIndexForRead($options);

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
        $index = $this->currentIndexForRead($options);
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

        $relPath = MapArtifactPaths::relationsFileFor($path);
        if (is_file($relPath)) {
            $relBytes = filesize($relPath);
            if (is_int($relBytes)) {
                $bytes += $relBytes;
            }
        }

        return $bytes < 1024 ? $bytes . ' B' : round($bytes / 1024, 1) . ' KB';
    }

    private function help(string $command): string
    {
        if ($command === 'build' || $command === 'refresh') {
            return <<<'TXT'
            Usage:
              agent-map build [--root=.] [--paths=src,tests] [--scan=vendor/acme] [--out=.agent-map/php-symbols.json] [--format=json|toon] [--backend=auto|structural|phpstan] [--phpstan-config=phpstan.neon] [--phpstan-memory-limit=512M] [--exclude=REGEX] [--merge]
              agent-map refresh [--root=.] [--index=.agent-map/php-symbols.json] [--out=.agent-map/php-symbols.json] [--backend=auto|structural|phpstan]

            Build a repository map. auto uses PHPStan when available and otherwise structural analysis; structural never executes PHPStan; phpstan explicitly requires the semantic backend. JSON is the default; TOON is optional. --exclude is repeatable.

            --scan lists directories that only have to resolve symbols (never indexed): use it when the
            analysed scope references classes living outside it, otherwise their types stay unresolved.

            --merge patches the existing --out index instead of replacing it, so a narrow --paths scope
            keeps everything it did not cover. Structural refresh does the same for changed files.
            PHPStan-backed refresh rebuilds the complete current indexed scope through the structural
            cache so PHPStan can invalidate semantic dependents through its own result cache.

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
          agent-map search-index build|refresh|doctor --index=.agent-map/php-symbols.json
          agent-map search 'why are trailing commas dropped' --limit=8
          agent-map help

        Options:
          --format=text|json|markdown|toon
          --database=.agent-map/search.sqlite --semantic
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
