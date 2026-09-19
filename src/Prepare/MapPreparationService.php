<?php

declare(strict_types=1);

namespace voku\AgentMap\Prepare;

use RuntimeException;
use Throwable;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\SemanticScope;
use voku\AgentMap\IO\PhpFileFinder;

final readonly class MapPreparationService
{
    public function __construct(
        private IndexReader $reader = new IndexReader(),
        private IndexWriter $writer = new IndexWriter(),
        private PhpFileFinder $finder = new PhpFileFinder(),
    ) {
    }

    /**
     * Prepare the bounded map requested by a consumer.
     *
     * Missing maps are built from the request scope. Existing maps are refreshed
     * with the backend they already carry when the caller leaves backend choice
     * at `auto`. A refusal never replaces the existing artifact.
     */
    public function prepare(MapPreparationRequest $request): MapPreparationResult
    {
        if (!is_file($request->indexPath)) {
            return $this->buildMissing($request);
        }

        try {
            $index = $this->reader->read($request->indexPath);
        } catch (Throwable $exception) {
            throw new MapPreparationException(
                reason: 'invalid_index',
                recoveryCommand: $this->fullBuildCommand($request),
                message: 'Cannot prepare ' . $request->indexPath . ': the existing map is unreadable. '
                    . $exception->getMessage(),
                previous: $exception,
            );
        }

        $normalized = $this->requestForExistingBackend($request, $index);
        try {
            return $this->refresh($normalized);
        } catch (MapPreparationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MapPreparationException(
                reason: 'refresh_failed',
                recoveryCommand: $this->fullBuildCommand($normalized),
                message: 'Cannot prepare ' . $request->indexPath . ': ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Refresh an existing map using the same deterministic currentness rules as the CLI.
     *
     * Search maintenance is intentionally outside this operation. The map is the
     * authoritative navigation artifact; Search remains an optional derived capability.
     */
    public function refresh(MapPreparationRequest $request): MapPreparationResult
    {
        $index = $this->reader->read($request->indexPath);

        $structural = $request->backend === 'structural';
        $phpStanRefresh = !$structural
            && PhpStanSemanticAnalyzer::isAvailable()
            && str_ends_with($index->backend, '+phpstan');
        $semanticScope = $this->semanticScope($index, $request);

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

        $searchPaths = $phpStanRefresh
            ? $semanticScope->paths
            : ($request->paths === ['.'] ? $this->indexedDirectories($index->files) : $request->paths);
        $searchExcludes = $phpStanRefresh ? $semanticScope->excludes : $request->excludes;
        foreach ($this->finder->find($request->root, $searchPaths, $searchExcludes) as $relative) {
            if (!isset($indexed[$relative])) {
                $changed[$relative] = true;
            }
        }

        $semanticInputsChanged = $phpStanRefresh
            && $this->semanticInputsChanged($index, $request, $semanticScope);
        if ($changed === [] && $removed === 0 && !$semanticInputsChanged) {
            return new MapPreparationResult(
                index: $index,
                mutated: false,
                changedFiles: 0,
                removedFiles: 0,
                message: 'Index is up to date: ' . $request->indexPath,
            );
        }

        if ($changed === [] && !$phpStanRefresh) {
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
            $this->writer->write($pruned, $request->outputPath, $request->format);

            return new MapPreparationResult(
                index: $pruned,
                mutated: true,
                changedFiles: 0,
                removedFiles: $removed,
                message: 'Refreshed 0 changed and dropped ' . $removed . ' removed file(s); '
                    . count($pruned->files) . ' file(s) indexed in ' . $request->outputPath,
            );
        }

        $builder = $this->builder($request);
        if (!$phpStanRefresh && $index->backend !== $builder->backend()) {
            $structuralOnly = str_ends_with($index->backend, '+structural-only');
            throw new RuntimeException(
                'Cannot refresh ' . $request->indexPath . ': it carries backend "' . $index->backend
                . '" and this run resolves "' . $builder->backend()
                . '". An incremental refresh cannot merge two semantic backends. Run a full build:'
                . "\n  agent-map build"
                . ' --root=' . self::shellArgument($request->root)
                . ' --paths=' . self::shellArgument(implode(',', $semanticScope->paths))
                . ' --out=' . self::shellArgument($request->outputPath)
                . ($structuralOnly ? ' --backend=structural' : '')
                . ($structuralOnly
                    ? ''
                    : "\nThe rebuilt index will carry \"" . $builder->backend() . '\", not \"' . $index->backend . '\".'),
            );
        }

        $rebuilt = $builder->build(
            $request->root,
            $phpStanRefresh ? $semanticScope->paths : array_keys($changed),
            $phpStanRefresh ? $semanticScope->excludes : $request->excludes,
            $structural ? null : $request->phpStanConfig,
            $structural ? null : $request->phpStanMemoryLimit,
            $phpStanRefresh ? null : $index,
            $structural ? [] : ($phpStanRefresh ? $semanticScope->scanDirectories : $request->scanPaths),
        );
        $this->writer->write($rebuilt, $request->outputPath, $request->format);

        return new MapPreparationResult(
            index: $rebuilt,
            mutated: true,
            changedFiles: count($changed),
            removedFiles: $removed,
            message: 'Refreshed ' . count($changed) . ' changed and dropped ' . $removed . ' removed file(s); '
                . count($rebuilt->files) . ' file(s) indexed in ' . $request->outputPath,
        );
    }

    private function buildMissing(MapPreparationRequest $request): MapPreparationResult
    {
        try {
            $structural = $request->backend === 'structural';
            $builder = $this->builder($request);
            $index = $builder->build(
                $request->root,
                $request->paths,
                $request->excludes,
                $structural ? null : $request->phpStanConfig,
                $structural ? null : $request->phpStanMemoryLimit,
                null,
                $structural ? [] : $request->scanPaths,
            );
            $this->writer->write($index, $request->outputPath, $request->format);
        } catch (Throwable $exception) {
            throw new MapPreparationException(
                reason: 'build_failed',
                recoveryCommand: $this->fullBuildCommand($request),
                message: 'Cannot prepare missing map ' . $request->outputPath . ': ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        return new MapPreparationResult(
            index: $index,
            mutated: true,
            changedFiles: count($index->files),
            removedFiles: 0,
            message: 'Built ' . count($index->files) . ' file(s) in ' . $request->outputPath,
        );
    }

    private function requestForExistingBackend(
        MapPreparationRequest $request,
        AgentMapIndex $index,
    ): MapPreparationRequest {
        $backend = $request->backend;
        if ($backend === 'auto') {
            if (str_ends_with($index->backend, '+structural-only')) {
                $backend = 'structural';
            } elseif (str_ends_with($index->backend, '+phpstan')) {
                if (!PhpStanSemanticAnalyzer::isAvailable()) {
                    throw new MapPreparationException(
                        reason: 'backend_unavailable',
                        recoveryCommand: $this->fullBuildCommand($request, 'phpstan'),
                        message: 'Cannot prepare ' . $request->indexPath . ': the recorded PHPStan backend is unavailable.',
                    );
                }
                $backend = 'phpstan';
            } else {
                throw new MapPreparationException(
                    reason: 'backend_unavailable',
                    recoveryCommand: $this->fullBuildCommand($request),
                    message: 'Cannot prepare ' . $request->indexPath . ': its backend "' . $index->backend
                        . '" cannot be reproduced automatically.',
                );
            }
        }

        if ($backend === 'phpstan' && !PhpStanSemanticAnalyzer::isAvailable()) {
            throw new MapPreparationException(
                reason: 'backend_unavailable',
                recoveryCommand: $this->fullBuildCommand($request),
                message: 'Cannot prepare ' . $request->indexPath . ': PHPStan semantic capability is unavailable.',
            );
        }

        $builder = $this->builderForBackend($backend, $request);
        if ($builder->backend() !== $index->backend) {
            throw new MapPreparationException(
                reason: 'backend_mismatch',
                recoveryCommand: $this->fullBuildCommand($request),
                message: 'Cannot refresh ' . $request->indexPath . ': it carries backend "' . $index->backend
                    . '" and the requested run resolves "' . $builder->backend() . '".',
            );
        }

        return new MapPreparationRequest(
            root: $request->root,
            indexPath: $request->indexPath,
            outputPath: $request->outputPath,
            format: $request->format,
            paths: $request->paths,
            pathsProvided: $request->pathsProvided,
            scanPaths: $request->scanPaths,
            scanPathsProvided: $request->scanPathsProvided,
            excludes: $request->excludes,
            excludesProvided: $request->excludesProvided,
            backend: $backend,
            phpStanConfig: $request->phpStanConfig,
            phpStanMemoryLimit: $request->phpStanMemoryLimit,
            artifacts: $request->artifacts,
        );
    }

    private function builderForBackend(string $backend, MapPreparationRequest $request): AgentMapBuilder
    {
        return match ($backend) {
            'structural' => new AgentMapBuilder(
                semanticAnalyzer: new StructuralOnlySemanticAnalyzer(),
                artifacts: $request->artifacts,
            ),
            'phpstan' => new AgentMapBuilder(
                semanticAnalyzer: new PhpStanSemanticAnalyzer($request->artifacts),
                artifacts: $request->artifacts,
            ),
            default => $this->builder($request),
        };
    }

    private function fullBuildCommand(MapPreparationRequest $request, ?string $backend = null): string
    {
        $command = 'agent-map build'
            . ' --root=' . self::shellArgument($request->root)
            . ' --paths=' . self::shellArgument(implode(',', $request->paths))
            . ' --out=' . self::shellArgument($request->outputPath);

        foreach ($request->excludes as $exclude) {
            $command .= ' --exclude=' . self::shellArgument($exclude);
        }
        if ($request->scanPaths !== []) {
            $command .= ' --scan=' . self::shellArgument(implode(',', $request->scanPaths));
        }

        $command .= ' --format=' . $request->format;

        $backend ??= $request->backend;
        if ($backend !== 'auto') {
            $command .= ' --backend=' . $backend;
        }
        if ($request->phpStanConfig !== null) {
            $command .= ' --phpstan-config=' . self::shellArgument($request->phpStanConfig);
        }
        if ($request->phpStanMemoryLimit !== null) {
            $command .= ' --phpstan-memory-limit=' . self::shellArgument($request->phpStanMemoryLimit);
        }

        return $command;
    }

    private function semanticScope(AgentMapIndex $index, MapPreparationRequest $request): SemanticScope
    {
        $stored = $index->fingerprint?->semanticScope;
        if ($stored === null) {
            return new SemanticScope(
                paths: $request->pathsProvided ? $request->paths : $this->indexedDirectories($index->files),
                excludes: $request->excludes,
                scanDirectories: $request->scanPaths,
            );
        }

        return new SemanticScope(
            paths: $request->pathsProvided ? $request->paths : $stored->paths,
            excludes: $request->excludesProvided ? $request->excludes : $stored->excludes,
            scanDirectories: $request->scanPathsProvided ? $request->scanPaths : $stored->scanDirectories,
        );
    }

    private function semanticInputsChanged(
        AgentMapIndex $index,
        MapPreparationRequest $request,
        SemanticScope $scope,
    ): bool {
        $fingerprint = $index->fingerprint;
        if ($fingerprint === null || $fingerprint->semanticScope === null) {
            return true;
        }
        if ($fingerprint->semanticScope->identitySha256() !== $scope->identitySha256()) {
            return true;
        }

        $configuration = AgentMapBuilder::resolvePhpStanConfiguration($index->root, $request->phpStanConfig);
        $configurationHash = $configuration === null
            ? 'sha256:' . hash('sha256', 'default-level-0')
            : 'sha256:' . (string) hash_file('sha256', $configuration);
        if ($fingerprint->phpStanConfigSha256 !== $configurationHash) {
            return true;
        }

        $composerLockHash = is_file($index->root . '/composer.lock')
            ? hash_file('sha256', $index->root . '/composer.lock')
            : false;

        return $fingerprint->composerLockSha256 !== (
            is_string($composerLockHash) ? 'sha256:' . $composerLockHash : 'sha256:none'
        );
    }

    private function builder(MapPreparationRequest $request): AgentMapBuilder
    {
        $semanticAnalyzer = match ($request->backend) {
            'auto' => null,
            'structural' => new StructuralOnlySemanticAnalyzer(),
            'phpstan' => new PhpStanSemanticAnalyzer($request->artifacts),
        };

        return new AgentMapBuilder(
            semanticAnalyzer: $semanticAnalyzer,
            artifacts: $request->artifacts,
        );
    }

    /**
     * @param list<FileEntry> $files
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

    private static function shellArgument(string $value): string
    {
        return preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#', $value) === 1
            ? $value
            : escapeshellarg($value);
    }
}
