<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\SemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Extract\LanguageRoutingSymbolExtractor;
use voku\AgentMap\Extract\ProjectRootAware;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Extract\SymbolExtractor;
use voku\AgentMap\Extract\TypeScriptSymbolExtractor;
use voku\AgentMap\IO\PhpFileFinder;
use voku\AgentMap\IO\SourceLanguage;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Reconcile\MapReconciler;

final readonly class AgentMapBuilder
{
    /**
     * Structural backend identity per indexed language.
     *
     * @var array<string, string>
     */
    private const STRUCTURAL_BACKENDS = [
        SourceLanguage::PHP => 'simple-php-code-parser',
        SourceLanguage::TYPESCRIPT => 'typescript-compiler',
    ];

    /** Raise this whenever the cached structural payload changes shape. */
    private const STRUCTURAL_CACHE_VERSION = 1;

    private SemanticAnalyzer $semanticAnalyzer;

    /** @var list<string> */
    private array $languages;

    /**
     * @param list<string> $languages the languages `$finder` and `$extractor` cover; part of the
     *                                backend identity. Use `forLanguages()` to get all three wired
     *                                consistently.
     */
    public function __construct(
        private PhpFileFinder $finder = new PhpFileFinder(),
        private SymbolExtractor $extractor = new SimplePhpParserSymbolExtractor(),
        ?SemanticAnalyzer $semanticAnalyzer = null,
        private MapReconciler $reconciler = new MapReconciler(),
        private ?MapArtifactPaths $artifacts = null,
        array $languages = [SourceLanguage::PHP],
    ) {
        $this->semanticAnalyzer = $semanticAnalyzer
            ?? (PhpStanSemanticAnalyzer::isAvailable()
                ? new PhpStanSemanticAnalyzer($artifacts)
                : new StructuralOnlySemanticAnalyzer());
        $this->languages = SourceLanguage::normalize($languages);
    }

    /**
     * A builder that indexes exactly the given languages.
     *
     * `['php']` is the plain default builder. Enabling `typescript` adds the TypeScript/JavaScript
     * suffixes to the finder and routes those files to the TypeScript compiler extractor.
     *
     * @param list<string> $languages
     * @param string|null $typeScriptPath explicit `typescript` package directory, used when the
     *                                    analysed project has none in its own `node_modules`
     */
    public static function forLanguages(
        array $languages,
        ?SemanticAnalyzer $semanticAnalyzer = null,
        ?MapArtifactPaths $artifacts = null,
        ?string $typeScriptPath = null,
    ): self {
        $languages = SourceLanguage::normalize($languages);
        if ($languages === [SourceLanguage::PHP]) {
            return new self(semanticAnalyzer: $semanticAnalyzer, artifacts: $artifacts);
        }

        return new self(
            finder: new PhpFileFinder(SourceLanguage::extensions($languages)),
            extractor: new LanguageRoutingSymbolExtractor(
                typeScript: new TypeScriptSymbolExtractor(typeScriptPath: $typeScriptPath),
            ),
            semanticAnalyzer: $semanticAnalyzer,
            artifacts: $artifacts,
            languages: $languages,
        );
    }

    /**
     * The backend identity a build by this instance would write.
     *
     * An incremental refresh can only merge into an index carrying this exact
     * identity. Callers that know how to repair a mismatch - a CLI holding the
     * root, paths and output path - need the answer before attempting the
     * merge, not as the exception that follows it.
     */
    public function backend(): string
    {
        $structural = array_map(static fn (string $language): string => self::STRUCTURAL_BACKENDS[$language], $this->languages);

        return implode('+', $structural) . '+' . $this->semanticAnalyzer->backend();
    }

    /**
     * The ` --languages=...` build option that reproduces a recorded backend identity, or an empty
     * string for a PHP-only map. Recovery commands need it: rebuilding a map that indexed
     * TypeScript without the option would silently drop every TypeScript file.
     */
    public static function languagesOptionForBackend(string $backend): string
    {
        $parts = explode('+', $backend);
        $languages = array_keys(array_filter(
            self::STRUCTURAL_BACKENDS,
            static fn (string $structural): bool => in_array($structural, $parts, true),
        ));

        return $languages === [] || $languages === [SourceLanguage::PHP] ? '' : ' --languages=' . implode(',', $languages);
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

        $backend = $this->backend();
        if ($previous !== null && $previous->backend !== $backend) {
            throw new RuntimeException(
                'Cannot incrementally merge map backend "' . $previous->backend . '" with "' . $backend
                . '". Run a full build so every file uses one semantic backend.',
            );
        }

        $relatives = $this->finder->find($realRoot, $paths, $excludes);
        $extractor = $this->extractor instanceof ProjectRootAware
            ? $this->extractor->withProjectRoot($realRoot)
            : $this->extractor;

        $cache = $this->loadStructuralCache($realRoot);
        // Entries outside the current build scope stay in the cache: alternating between a narrow
        // and a wide scope should not force a full re-parse each time.
        $nextCache = $cache;

        $sourceHashes = [];
        $cachedSymbols = [];
        $uncachedRelatives = [];
        $uncachedAbsolutes = [];

        foreach ($relatives as $relative) {
            $absolute = $realRoot . '/' . $relative;
            $sha256 = hash_file('sha256', $absolute);
            if (!is_string($sha256)) {
                throw new RuntimeException('Unable to hash PHP file: ' . $relative);
            }

            $sourceHashes[$relative] = $sha256;
            // Only PHP symbols are a function of the file's own bytes. A TypeScript symbol's
            // extends/implements identity also depends on the files its imports resolve to, so a
            // hash-keyed cache entry could go stale without the file changing.
            $symbols = self::languageOf($relative) === SourceLanguage::PHP
                ? $this->cachedSymbols($cache, $relative, $sha256)
                : null;
            if ($symbols === null) {
                $uncachedRelatives[] = $relative;
                $uncachedAbsolutes[] = $absolute;
            } else {
                $cachedSymbols[$relative] = $symbols;
            }
        }

        if ($uncachedRelatives !== []) {
            $extracted = $extractor->extractMany($uncachedAbsolutes);
            foreach ($uncachedRelatives as $index => $relative) {
                $absolute = $uncachedAbsolutes[$index];
                $result = $extracted[$absolute] ?? null;
                if ($result === null || !$result->ok) {
                    throw new RuntimeException('Parsing failed for ' . $relative . '.' . ($result?->error === null ? '' : ' ' . $result->error));
                }
                $cachedSymbols[$relative] = $result->symbols;
            }
        }

        $structuralFiles = [];
        // The semantic backend is PHP-only: it is never handed a file of another language.
        $phpRelatives = [];
        foreach ($relatives as $relative) {
            $sha256 = $sourceHashes[$relative];
            $symbols = $cachedSymbols[$relative];
            $language = self::languageOf($relative);

            if ($language !== SourceLanguage::PHP) {
                $directory = dirname($relative);
                $structuralFiles[] = new FileEntry(
                    path: $relative,
                    sha256: 'sha256:' . $sha256,
                    // A module has no declared namespace; its directory is the nearest equivalent.
                    namespace: $directory === '.' ? '' : $directory,
                    symbols: $symbols,
                    semanticStatus: 'pending',
                    language: $language,
                );
                continue;
            }

            $phpRelatives[] = $relative;
            $nextCache[$relative] = [
                'sha256' => $sha256,
                'symbols' => array_map(static fn (SymbolEntry $symbol): array => $symbol->toArray(), $symbols),
            ];

            $structuralFiles[] = new FileEntry(
                path: $relative,
                sha256: 'sha256:' . $sha256,
                namespace: $this->namespaceFromSymbols($symbols),
                symbols: $symbols,
                semanticStatus: 'pending',
            );
        }

        $this->saveStructuralCache($realRoot, $nextCache);

        $configuration = self::resolvePhpStanConfiguration($realRoot, $phpStanConfiguration);
        $semantic = $this->semanticAnalyzer->analyse(
            $realRoot,
            $phpRelatives,
            $configuration,
            $phpStanMemoryLimit,
            $this->analyseDirectories($realRoot, $paths),
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
            schemaVersion: $this->languages === [SourceLanguage::PHP]
                ? AgentMapIndex::SCHEMA_VERSION
                : AgentMapIndex::POLYGLOT_SCHEMA_VERSION,
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
                semanticScope: new SemanticScope($paths, $excludes, $scanPaths),
            ),
            localBindings: $reconciled['local_bindings'],
            localExits: $reconciled['local_exits'],
        );
    }

    /**
     * Directories PHPStan may analyse as directories instead of as an expanded file list.
     *
     * PHPStan turns its result cache off completely as soon as individual files are passed
     * ("Result cache not used because only files were passed as analysed paths"), which makes every
     * rebuild a cold rebuild. Handing over the scope directories keeps the cache alive; the semantic
     * analyser translates the files removed from the map scope into PHPStan `excludePaths` instead of
     * widening the analysed scope or falling back to the file list.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function analyseDirectories(string $root, array $paths): array
    {
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
     * @param array{files: list<FileEntry>, relations: list<RelationEntry>, diagnostics: list<DiagnosticEntry>, local_bindings: list<LocalBindingEntry>, local_exits: list<LocalExitEntry>} $fresh
     * @param list<string> $rebuilt
     * @param array<string, string> $sourceHashes
     *
     * @return array{files: list<FileEntry>, relations: list<RelationEntry>, diagnostics: list<DiagnosticEntry>, local_bindings: list<LocalBindingEntry>, local_exits: list<LocalExitEntry>}
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

        $localBindings = $fresh['local_bindings'];
        $bindingIds = [];
        foreach ($localBindings as $binding) {
            $bindingIds[$binding->id] = true;
        }
        foreach ($previous->localBindings as $binding) {
            if (!isset($carriedPaths[$binding->file]) || isset($bindingIds[$binding->id])) {
                continue;
            }

            $bindingIds[$binding->id] = true;
            $localBindings[] = $binding;
        }

        $localExits = $fresh['local_exits'];
        $exitIds = [];
        foreach ($localExits as $exit) {
            $exitIds[$exit->id] = true;
        }
        foreach ($previous->localExits as $exit) {
            if (!isset($carriedPaths[$exit->file]) || isset($exitIds[$exit->id])) {
                continue;
            }

            $exitIds[$exit->id] = true;
            $localExits[] = $exit;
        }

        usort($files, static fn (FileEntry $left, FileEntry $right): int => strcmp($left->path, $right->path));
        usort($relations, static fn (RelationEntry $left, RelationEntry $right): int => strcmp($left->file, $right->file) ?: $left->lineStart <=> $right->lineStart ?: strcmp($left->id, $right->id));
        usort($diagnostics, static fn (DiagnosticEntry $left, DiagnosticEntry $right): int => strcmp($left->file ?? '', $right->file ?? '') ?: ($left->line ?? 0) <=> ($right->line ?? 0) ?: strcmp($left->id, $right->id));
        usort($localBindings, static fn (LocalBindingEntry $left, LocalBindingEntry $right): int => strcmp($left->file, $right->file) ?: $left->lineStart <=> $right->lineStart ?: strcmp($left->id, $right->id));
        usort($localExits, static fn (LocalExitEntry $left, LocalExitEntry $right): int => strcmp($left->file, $right->file) ?: $left->lineStart <=> $right->lineStart ?: strcmp($left->id, $right->id));

        return [
            'files' => $files,
            'relations' => $relations,
            'diagnostics' => $diagnostics,
            'local_bindings' => $localBindings,
            'local_exits' => $localExits,
        ];
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

    public static function resolvePhpStanConfiguration(string $root, ?string $configuration): ?string
    {
        if ($configuration !== null) {
            $candidate = self::isAbsolutePath($configuration) ? $configuration : $root . '/' . $configuration;
            if (!is_file($candidate)) {
                throw new RuntimeException('PHPStan configuration not found: ' . $configuration);
            }

            return $candidate;
        }

        // PHPStan configuration is executable project input: it can load
        // bootstrap files and extensions. Do not execute repository config
        // during an ordinary map build unless the caller selected it.
        return null;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    /** Files no language claims are treated as PHP, as every finder result was before languages existed. */
    private static function languageOf(string $relative): string
    {
        return SourceLanguage::ofPath($relative) ?? SourceLanguage::PHP;
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
