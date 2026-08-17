<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\SemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Extract\SymbolExtractor;
use voku\AgentMap\IO\PhpFileFinder;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Reconcile\MapReconciler;

final readonly class AgentMapBuilder
{
    private const STRUCTURAL_BACKEND = 'simple-php-code-parser';

    /** Raise this whenever the cached structural payload changes shape. */
    private const STRUCTURAL_CACHE_VERSION = 1;

    private SemanticAnalyzer $semanticAnalyzer;

    public function __construct(
        private PhpFileFinder $finder = new PhpFileFinder(),
        private SymbolExtractor $extractor = new SimplePhpParserSymbolExtractor(),
        ?SemanticAnalyzer $semanticAnalyzer = null,
        private MapReconciler $reconciler = new MapReconciler(),
        private ?MapArtifactPaths $artifacts = null,
    ) {
        $this->semanticAnalyzer = $semanticAnalyzer
            ?? (PhpStanSemanticAnalyzer::isAvailable()
                ? new PhpStanSemanticAnalyzer($artifacts)
                : new StructuralOnlySemanticAnalyzer());
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     * @param list<string> $scanPaths directories that only need to resolve symbols, not be indexed
     * @param AgentMapIndex|null $previous already built index to patch instead of replace; only the
     *                                     files covered by `$paths` are re-analysed, everything else
     *                                     is carried over unchanged
     */
    public function build(
        string $root,
        array $paths,
        array $excludes,
        ?string $phpStanConfiguration = null,
        ?string $phpStanMemoryLimit = null,
        ?AgentMapIndex $previous = null,
        array $scanPaths = [],
    ): AgentMapIndex
    {
        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Root directory not found: ' . $root);
        }

        $realRoot = str_replace('\\', '/', $realRoot);
        $semanticBackend = $this->semanticAnalyzer->backend();
        if (
            $semanticBackend === 'structural-only'
            && ($phpStanConfiguration !== null || $phpStanMemoryLimit !== null || $scanPaths !== [])
        ) {
            throw new RuntimeException(
                'PHPStan semantic capability is unavailable; PHPStan-specific build options require phpstan/phpstan.',
            );
        }

        $backend = self::STRUCTURAL_BACKEND . '+' . $semanticBackend;
        if ($previous !== null && $previous->backend !== $backend) {
            throw new RuntimeException(
                'Cannot incrementally merge map backend "' . $previous->backend . '" with "' . $backend
                . '". Run a full build so every file uses one semantic backend.',
            );
        }

        $relatives = $this->finder->find($realRoot, $paths, $excludes);

        $cache = $this->loadStructuralCache($realRoot);
        // Entries outside the current build scope stay in the cache: alternating between a narrow
        // and a wide scope should not force a full re-parse each time.
        $nextCache = $cache;

        $structuralFiles = [];
        $sourceHashes = [];
        foreach ($relatives as $relative) {
            $absolute = $realRoot . '/' . $relative;
            $sha256 = hash_file('sha256', $absolute);
            if (!is_string($sha256)) {
                throw new RuntimeException('Unable to hash PHP file: ' . $relative);
            }

            // Parsing dominates the non-PHPStan build time, so unchanged files come from the
            // structural cache. Only the raw extractor output is cached; reconciled entries would
            // carry semantic data forward that the current analysis may no longer confirm.
            $symbols = $this->cachedSymbols($cache, $relative, $sha256);
            if ($symbols === null) {
                $result = $this->extractor->extract($absolute);
                if (!$result->ok) {
                    throw new RuntimeException('Parsing failed for ' . $relative . '.' . ($result->error === null ? '' : ' ' . $result->error));
                }
                $symbols = $result->symbols;
            }

            $nextCache[$relative] = [
                'sha256' => $sha256,
                'symbols' => array_map(static fn (SymbolEntry $symbol): array => $symbol->toArray(), $symbols),
            ];

            $sourceHashes[$relative] = $sha256;
            $structuralFiles[] = new FileEntry(
                path: $relative,
                sha256: 'sha256:' . $sha256,
                namespace: $this->namespaceFromSymbols($symbols),
                symbols: $symbols,
                semanticStatus: 'pending',
            );
        }

        $this->saveStructuralCache($realRoot, $nextCache);

        $configuration = $this->resolvePhpStanConfiguration($realRoot, $phpStanConfiguration);
        $semantic = $this->semanticAnalyzer->analyse(
            $realRoot,
            $relatives,
            $configuration,
            $phpStanMemoryLimit,
            $this->analyseDirectories($realRoot, $paths, $excludes),
            $scanPaths,
        );
        $reconciled = $this->reconciler->reconcile($realRoot, $structuralFiles, $semantic);
        if ($previous !== null) {
            $reconciled = $this->merge($realRoot, $previous, $reconciled, $relatives, $sourceHashes);
        }

        ksort($sourceHashes, SORT_STRING);
        $sourceDigestParts = [];
        foreach ($sourceHashes as $relative => $hash) {
            $sourceDigestParts[] = $relative . "\0" . $hash;
        }
        $composerLockHash = is_file($realRoot . '/composer.lock') ? hash_file('sha256', $realRoot . '/composer.lock') : false;

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: $realRoot,
            backend: $backend,
            files: $reconciled['files'],
            relations: $reconciled['relations'],
            diagnostics: $reconciled['diagnostics'],
            fingerprint: new AnalysisFingerprint(
                phpStanVersion: $semantic->phpStanVersion,
                phpStanConfigSha256: $semantic->configurationSha256,
                composerLockSha256: is_string($composerLockHash) ? 'sha256:' . $composerLockHash : 'sha256:none',
                sourceDigest: 'sha256:' . hash('sha256', implode("\n", $sourceDigestParts)),
            ),
        );
    }

    /**
     * Directories PHPStan may analyse as directories instead of as an expanded file list.
     *
     * PHPStan turns its result cache off completely as soon as individual files are passed
     * ("Result cache not used because only files were passed as analysed paths"), which makes every
     * rebuild a cold rebuild. Handing over the scope directories keeps the cache alive. Excludes are
     * the one case where that would widen the analysis, so those scopes keep the file list; extra
     * semantic records for unindexed files are dropped during reconciliation either way.
     *
     * @param list<string> $paths
     * @param list<string> $excludes
     *
     * @return list<string>
     */
    private function analyseDirectories(string $root, array $paths, array $excludes): array
    {
        if ($excludes !== []) {
            return [];
        }

        $directories = [];
        foreach ($paths as $path) {
            if (!is_dir($path === '.' ? $root : $root . '/' . $path)) {
                return [];
            }

            $directories[] = $path;
        }

        return $directories;
    }

    /**
     * Patches an existing index with the freshly analysed scope.
     *
     * A full semantic build of a large project costs minutes, so day-to-day refreshes rebuild only
     * the touched files. Everything the current scope did not cover is carried over verbatim, minus
     * entries whose file has meanwhile disappeared. Relations are keyed by their *source* file, so
     * carried-over edges pointing into a rebuilt file keep the shape they had at their own last
     * analysis - a periodic full rebuild is still what makes incoming edges exact.
     *
     * @param array{files: list<FileEntry>, relations: list<RelationEntry>, diagnostics: list<DiagnosticEntry>} $fresh
     * @param list<string> $rebuilt
     * @param array<string, string> $sourceHashes
     *
     * @return array{files: list<FileEntry>, relations: list<RelationEntry>, diagnostics: list<DiagnosticEntry>}
     */
    private function merge(string $root, AgentMapIndex $previous, array $fresh, array $rebuilt, array &$sourceHashes): array
    {
        $rebuiltPaths = array_fill_keys($rebuilt, true);

        $carriedPaths = [];
        $files = $fresh['files'];
        foreach ($previous->files as $file) {
            if (isset($rebuiltPaths[$file->path]) || !is_file($root . '/' . $file->path)) {
                continue;
            }

            $carriedPaths[$file->path] = true;
            $files[] = $file;
            $sourceHashes[$file->path] = str_starts_with($file->sha256, 'sha256:') ? substr($file->sha256, 7) : $file->sha256;
        }

        $relations = $fresh['relations'];
        $relationIds = [];
        foreach ($relations as $relation) {
            $relationIds[$relation->id] = true;
        }
        foreach ($previous->relations as $relation) {
            if (!isset($carriedPaths[$relation->file]) || isset($relationIds[$relation->id])) {
                continue;
            }

            $relationIds[$relation->id] = true;
            $relations[] = $relation;
        }

        $diagnostics = $fresh['diagnostics'];
        $diagnosticIds = [];
        foreach ($diagnostics as $diagnostic) {
            $diagnosticIds[$diagnostic->id] = true;
        }
        foreach ($previous->diagnostics as $diagnostic) {
            // File-less diagnostics are run-scoped PHPStan findings; the fresh run reports its own.
            if ($diagnostic->file === null || !isset($carriedPaths[$diagnostic->file]) || isset($diagnosticIds[$diagnostic->id])) {
                continue;
            }

            $diagnosticIds[$diagnostic->id] = true;
            $diagnostics[] = $diagnostic;
        }

        usort($files, static fn (FileEntry $left, FileEntry $right): int => strcmp($left->path, $right->path));
        usort($relations, static fn (RelationEntry $left, RelationEntry $right): int => strcmp($left->file, $right->file) ?: $left->lineStart <=> $right->lineStart ?: strcmp($left->id, $right->id));
        usort($diagnostics, static fn (DiagnosticEntry $left, DiagnosticEntry $right): int => strcmp($left->file ?? '', $right->file ?? '') ?: ($left->line ?? 0) <=> ($right->line ?? 0) ?: strcmp($left->id, $right->id));

        return ['files' => $files, 'relations' => $relations, 'diagnostics' => $diagnostics];
    }

    /**
     * @param array<string, array{sha256: string, symbols: list<array<string, mixed>>}> $cache
     * @return list<SymbolEntry>|null
     */
    private function cachedSymbols(array $cache, string $relative, string $sha256): ?array
    {
        $entry = $cache[$relative] ?? null;
        if ($entry === null || $entry['sha256'] !== $sha256) {
            return null;
        }

        $symbols = [];
        foreach ($entry['symbols'] as $symbol) {
            $symbols[] = SymbolEntry::fromArray($symbol);
        }

        return $symbols;
    }

    /**
     * @return array<string, array{sha256: string, symbols: list<array<string, mixed>>}>
     */
    private function loadStructuralCache(string $root): array
    {
        $file = $this->artifactPaths($root)->structuralCache();
        if (!is_file($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if (!is_string($content)) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data) || ($data['cache_version'] ?? null) !== self::STRUCTURAL_CACHE_VERSION || !is_array($data['files'] ?? null)) {
            return [];
        }

        $cache = [];
        foreach ($data['files'] as $path => $entry) {
            if (is_string($path) && is_array($entry) && is_string($entry['sha256'] ?? null) && is_array($entry['symbols'] ?? null)) {
                /** @var array{sha256: string, symbols: list<array<string, mixed>>} $entry */
                $cache[$path] = $entry;
            }
        }

        return $cache;
    }

    /**
     * @param array<string, array{sha256: string, symbols: list<array<string, mixed>>}> $cache
     */
    private function saveStructuralCache(string $root, array $cache): void
    {
        $file = $this->artifactPaths($root)->structuralCache();
        $directory = dirname($file);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        $json = json_encode(['cache_version' => self::STRUCTURAL_CACHE_VERSION, 'files' => $cache], JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        $temporary = $file . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $json) === false) {
            return;
        }
        if (!rename($temporary, $file)) {
            @unlink($temporary);
        }
    }

    private function artifactPaths(string $root): MapArtifactPaths
    {
        return $this->artifacts ?? MapArtifactPaths::forProject($root);
    }

    private function resolvePhpStanConfiguration(string $root, ?string $configuration): ?string
    {
        if ($configuration !== null) {
            $candidate = $this->isAbsolutePath($configuration) ? $configuration : $root . '/' . $configuration;
            if (!is_file($candidate)) {
                throw new RuntimeException('PHPStan configuration not found: ' . $configuration);
            }

            return $candidate;
        }

        foreach (['phpstan.neon', 'phpstan.neon.dist'] as $name) {
            if (is_file($root . '/' . $name)) {
                return $root . '/' . $name;
            }
        }

        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    /**
     * @param list<SymbolEntry> $symbols
     */
    private function namespaceFromSymbols(array $symbols): string
    {
        foreach ($symbols as $symbol) {
            if (!str_contains($symbol->fqn, '\\')) {
                continue;
            }

            return substr($symbol->fqn, 0, (int) strrpos($symbol->fqn, '\\'));
        }

        return '';
    }
}
