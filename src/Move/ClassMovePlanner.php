<?php

declare(strict_types=1);

namespace voku\AgentMap\Move;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanMove;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Plan\ProjectRelativePath;
use voku\AgentMap\Rename\SourceClassNameLocator;

/**
 * Builds a read-only, fail-closed plan for relocating one PHP class into another namespace.
 *
 * Renaming a class is a token change; moving it is a change of resolution context. The plan is
 * therefore only mechanical when the destination file path can be derived from declared PSR-4
 * evidence rather than guessed, when the file carries exactly one declaration under exactly one
 * unbraced namespace, and when every affected reference maps to an exact byte range.
 */
final readonly class ClassMovePlanner
{
    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'Reflection and container/configuration class strings beyond exact observed literals are not automatically rewritten.',
        'Non-PHP artifacts such as YAML, XML, templates and generated metadata are outside the indexed source envelope.',
        'Composer autoload configuration is read as evidence and is never rewritten by this plan.',
        'PHP outside the indexed map scope, including vendor and generated code, is not searched for references.',
    ];

    public function plan(AgentMapIndex $map, string $target, string $destination): ClassMovePlan
    {
        $resolved = $this->resolveClass($map, $target);
        $file = $resolved['file'];
        $symbol = $resolved['symbol'];
        $sourceFqn = ltrim($symbol->fqn, '\\');
        $sourceNamespace = $this->namespace($sourceFqn);
        $destinationFqn = $this->destination($sourceFqn, $destination);
        $destinationNamespace = $this->namespace($destinationFqn);

        $staleEvidence = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        $blockers = [];
        $blindSpots = [];
        if ($symbol->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot move a class whose structural and semantic identity conflict: ' . $sourceFqn;
        }
        if (count($file->symbols) > 1) {
            $blockers[] = sprintf(
                'Cannot move %s: %s declares %d symbols and one namespace edit would relocate all of them.',
                $sourceFqn,
                $file->path,
                count($file->symbols),
            );
        }
        foreach ($this->identityCollisions($map, $symbol, $destinationFqn) as $collision) {
            $blockers[] = $collision;
        }

        $autoload = null;
        try {
            [$autoload, $autoloadBlindSpots] = $this->autoloadEvidence($map, $file, $sourceFqn, $destinationFqn);
            $blindSpots = [...$blindSpots, ...$autoloadBlindSpots];
            $absoluteDestination = rtrim($map->root, '/\\') . '/' . $autoload->destinationPath;
            if (file_exists($absoluteDestination) || is_link($absoluteDestination)) {
                $blockers[] = 'Destination class path already exists: ' . $autoload->destinationPath;
            }
        } catch (RuntimeException $exception) {
            $blockers[] = $exception->getMessage();
        }

        if ($staleEvidence !== [] || $blockers !== []) {
            return $this->result($map, $symbol, $sourceFqn, $destinationFqn, $autoload, [], [], $blindSpots, $staleEvidence, $blockers);
        }

        $nameLocator = new SourceClassNameLocator($map->root);
        $moveLocator = new SourceClassMoveLocator($nameLocator);
        $edits = [];
        try {
            $declaration = $moveLocator->namespaceDeclaration($file->path, $sourceNamespace);
            $edits[] = new PlanEdit(
                path: $file->path,
                sourceSha256: $file->sha256,
                startFilePos: $declaration['start_file_pos'],
                endFilePos: $declaration['end_file_pos'],
                lineStart: $declaration['line_start'],
                lineEnd: $declaration['line_end'],
                expected: $declaration['expected'],
                replacement: $destinationNamespace,
                role: 'namespace_declaration',
                symbolId: $symbol->id(),
                resolution: 'parser_resolved',
            );
        } catch (RuntimeException $exception) {
            $blockers[] = $exception->getMessage();
        }

        $functions = $this->indexedFunctions($map);
        foreach ($map->files as $candidateFile) {
            try {
                $collected = $moveLocator->collect(
                    path: $candidateFile->path,
                    sourceSha256: $candidateFile->sha256,
                    sourceFqn: $sourceFqn,
                    destinationFqn: $destinationFqn,
                    symbolId: $symbol->id(),
                    imports: $moveLocator->classImports($candidateFile->path),
                    isMovedFile: $candidateFile->path === $file->path,
                );
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
                continue;
            }

            $edits = [...$edits, ...$collected['edits']];
            $blindSpots = [...$blindSpots, ...$collected['blind_spots']];
            $blockers = [...$blockers, ...$collected['blockers']];
            foreach ($collected['fallback_names'] as $fallback) {
                [$fallbackBlocker, $fallbackBlindSpot] = $this->fallbackEvidence(
                    $fallback['name'],
                    $fallback['line'],
                    $candidateFile->path,
                    $sourceNamespace,
                    $destinationNamespace,
                    $functions,
                );
                if (is_string($fallbackBlocker)) {
                    $blockers[] = $fallbackBlocker;
                }
                if ($fallbackBlindSpot instanceof PlanBlindSpot) {
                    $blindSpots[] = $fallbackBlindSpot;
                }
            }
        }

        $moves = [];
        if ($autoload instanceof ClassMoveAutoloadEvidence) {
            $moves[] = new PlanMove(
                fromPath: $file->path,
                toPath: $autoload->destinationPath,
                sourceSha256: $file->sha256,
                reason: sprintf(
                    'Declared PSR-4 mapping %s => %s prescribes the destination path for %s.',
                    $autoload->destinationPrefix === '' ? '""' : $autoload->destinationPrefix,
                    $autoload->destinationDirectory === '' ? '.' : $autoload->destinationDirectory,
                    $destinationFqn,
                ),
                destinationMustBeAbsent: true,
            );
        }

        $edits = $this->uniqueSortedEdits($edits);
        $blockers = [...$blockers, ...$this->overlapBlockers($edits)];

        return $this->result($map, $symbol, $sourceFqn, $destinationFqn, $autoload, $edits, $moves, $blindSpots, $staleEvidence, $blockers);
    }

    /** @return array{file: FileEntry, symbol: SymbolEntry} */
    private function resolveClass(AgentMapIndex $map, string $target): array
    {
        $target = ltrim(trim($target), '\\');
        if ($target === '') {
            throw new InvalidArgumentException('Class move target cannot be empty.');
        }

        $qualified = str_contains($target, '\\');
        /** @var list<array{file: FileEntry, symbol: SymbolEntry}> $matches */
        $matches = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->kind !== 'class') {
                    continue;
                }
                $matchesTarget = $qualified
                    ? strcasecmp(ltrim($symbol->fqn, '\\'), $target) === 0
                    : strcasecmp($symbol->name, $target) === 0;
                if ($matchesTarget) {
                    $matches[] = ['file' => $file, 'symbol' => $symbol];
                }
            }
        }

        if ($matches === []) {
            throw new RuntimeException('Class target not found: ' . $target);
        }
        if (count($matches) > 1) {
            $candidates = array_map(static fn (array $match): string => $match['symbol']->fqn, $matches);
            sort($candidates, SORT_STRING);
            throw new RuntimeException('Class target is ambiguous: ' . $target . "\nUse a fully-qualified class name:\n- " . implode("\n- ", $candidates));
        }

        return $matches[0];
    }

    private function destination(string $sourceFqn, string $destination): string
    {
        $destination = ltrim(trim($destination), '\\');
        if ($destination === '') {
            throw new InvalidArgumentException('Destination class name cannot be empty.');
        }
        if (!str_contains($destination, '\\')) {
            throw new InvalidArgumentException('Class move requires a namespaced destination; moving a class into the global namespace is not part of contract 1.0.');
        }

        $sourceShort = $this->shortName($sourceFqn);
        if ($this->shortName($destination) !== $sourceShort) {
            throw new InvalidArgumentException('Class move keeps the class name; use class-rename-plan to change it, then move the result.');
        }
        if (strcasecmp($this->namespace($destination), $this->namespace($sourceFqn)) === 0) {
            throw new InvalidArgumentException('Destination namespace is identical to the current namespace; use class-rename-plan for same-namespace changes.');
        }

        foreach (explode('\\', $this->namespace($destination)) as $segment) {
            if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $segment) !== 1) {
                throw new InvalidArgumentException('Invalid PHP namespace segment in destination: ' . $segment);
            }
        }

        return $destination;
    }

    /** @return list<string> */
    private function identityCollisions(AgentMapIndex $map, SymbolEntry $symbol, string $destinationFqn): array
    {
        $collisions = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $candidate) {
                if (!in_array($candidate->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                    continue;
                }
                if ($candidate->id() === $symbol->id()) {
                    continue;
                }
                if (strcasecmp(ltrim($candidate->fqn, '\\'), $destinationFqn) === 0) {
                    $collisions[] = sprintf('Destination class identity collides with indexed %s %s.', $candidate->kind, $candidate->fqn);
                }
            }
        }

        return $collisions;
    }

    /** @return array{0: ClassMoveAutoloadEvidence, 1: list<PlanBlindSpot>} */
    private function autoloadEvidence(AgentMapIndex $map, FileEntry $file, string $sourceFqn, string $destinationFqn): array
    {
        $autoload = Psr4AutoloadMap::forProject($map->root);
        if ($autoload->isCoveredByClassmap($file->path)) {
            throw new RuntimeException('Source file is covered by a Composer classmap/files entry; the destination path is not deterministic.');
        }

        $sourceMappings = $autoload->declarationCandidates($sourceFqn, $file->path);
        if ($sourceMappings === []) {
            throw new RuntimeException(sprintf('No declared PSR-4 mapping explains the current location of %s at %s.', $sourceFqn, $file->path));
        }
        // Nested prefixes that derive the same path are redundant, not ambiguous: the candidates are
        // already filtered to the file's actual location, so the most specific one is the evidence.
        $sourceMapping = $sourceMappings[0];
        $this->assertMappingInsideProject($sourceMapping, 'Source');

        $destinationCandidates = $autoload->candidates($destinationFqn);
        if ($destinationCandidates === []) {
            throw new RuntimeException('No declared PSR-4 mapping covers the destination identity ' . $destinationFqn . '.');
        }
        $winner = $destinationCandidates[0];
        $ties = array_values(array_filter(
            $destinationCandidates,
            static fn (Psr4Mapping $mapping): bool => strlen($mapping->prefix) === strlen($winner->prefix),
        ));
        if (count($ties) > 1) {
            throw new RuntimeException(sprintf(
                'Destination identity %s is covered by %d equally specific PSR-4 mappings: %s.',
                $destinationFqn,
                count($ties),
                implode(', ', array_map(static fn (Psr4Mapping $mapping): string => $mapping->label(), $ties)),
            ));
        }

        $this->assertMappingInsideProject($winner, 'Destination');

        $destinationPath = $winner->pathFor($destinationFqn);
        if (!ProjectRelativePath::isSafe($destinationPath)) {
            throw new RuntimeException(sprintf(
                'Destination path %s derived from PSR-4 mapping %s leaves the project root; the move is not this project\'s to make.',
                $destinationPath,
                $winner->label(),
            ));
        }
        if ($autoload->isCoveredByClassmap($destinationPath)) {
            throw new RuntimeException('Destination path is covered by a Composer classmap/files entry; the move is not deterministic.');
        }

        $blindSpots = [];
        if (count($destinationCandidates) > 1) {
            $blindSpots[] = new PlanBlindSpot(
                kind: 'shadowed_autoload_prefix',
                message: sprintf(
                    'Destination %s is also covered by less specific PSR-4 mappings (%s); the plan uses the most specific one.',
                    $destinationFqn,
                    implode(', ', array_map(
                        static fn (Psr4Mapping $mapping): string => $mapping->label(),
                        array_slice($destinationCandidates, 1),
                    )),
                ),
                path: 'composer.json',
            );
        }
        if ($winner->section !== $sourceMapping->section) {
            $blindSpots[] = new PlanBlindSpot(
                kind: 'autoload_section_change',
                message: sprintf(
                    'The move crosses Composer autoload sections (%s -> %s), which changes where the class is available.',
                    $sourceMapping->section,
                    $winner->section,
                ),
                path: 'composer.json',
            );
        }

        return [
            new ClassMoveAutoloadEvidence(
                manifestPath: $autoload->manifestPath,
                manifestSha256: $autoload->manifestSha256,
                sourcePrefix: $sourceMapping->prefix,
                sourceDirectory: $sourceMapping->directory,
                sourceSection: $sourceMapping->section,
                destinationPrefix: $winner->prefix,
                destinationDirectory: $winner->directory,
                destinationSection: $winner->section,
                destinationPath: $destinationPath,
            ),
            $blindSpots,
        ];
    }

    /**
     * A PSR-4 directory outside the project root is a real autoload answer and an unusable move
     * destination: agent-map maps one repository, and it cannot prove anything about what already
     * lives beside or above it.
     */
    private function assertMappingInsideProject(Psr4Mapping $mapping, string $role): void
    {
        if ($mapping->insideProject) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s PSR-4 mapping %s points outside the project root; contract 1.0 does not plan moves across it.',
            $role,
            $mapping->label(),
        ));
    }

    /**
     * @param array<string, true> $functions
     * @return array{0: ?string, 1: ?PlanBlindSpot}
     */
    private function fallbackEvidence(
        string $name,
        int $line,
        string $path,
        string $sourceNamespace,
        string $destinationNamespace,
        array $functions,
    ): array {
        $current = strtolower($sourceNamespace . '\\' . $name);
        $future = strtolower($destinationNamespace . '\\' . $name);
        if (isset($functions[$current])) {
            return [
                sprintf('Moved source calls %s(), which currently binds to the namespaced function %s\\%s and would rebind after the move.', $name, $sourceNamespace, $name),
                null,
            ];
        }
        if (isset($functions[$future])) {
            return [
                sprintf('Moved source calls %s(), which the destination namespace would newly capture as %s\\%s.', $name, $destinationNamespace, $name),
                null,
            ];
        }
        if (function_exists($name) || defined($name)) {
            return [null, null];
        }

        return [
            null,
            new PlanBlindSpot(
                kind: 'namespace_fallback_reference',
                message: sprintf('Unqualified %s resolves through namespace fallback; a namespaced declaration outside the indexed map could change its meaning after the move.', $name),
                path: $path,
                lineStart: $line,
                lineEnd: $line,
            ),
        ];
    }

    /** @return array<string, true> */
    private function indexedFunctions(AgentMapIndex $map): array
    {
        $functions = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->kind === 'function') {
                    $functions[strtolower(ltrim($symbol->fqn, '\\'))] = true;
                }
            }
        }

        return $functions;
    }

    /**
     * @param list<PlanEdit> $edits
     * @return list<PlanEdit>
     */
    private function uniqueSortedEdits(array $edits): array
    {
        $unique = [];
        foreach ($edits as $edit) {
            $unique[$edit->path . ':' . $edit->startFilePos . ':' . $edit->endFilePos] = $edit;
        }
        $edits = array_values($unique);
        usort($edits, static fn (PlanEdit $left, PlanEdit $right): int => $left->path <=> $right->path ?: $left->startFilePos <=> $right->startFilePos);

        return $edits;
    }

    /**
     * @param list<PlanEdit> $edits
     * @return list<string>
     */
    private function overlapBlockers(array $edits): array
    {
        $blockers = [];
        $currentPath = null;
        $maxEnd = null;
        $coveringEdit = null;
        foreach ($edits as $edit) {
            if ($currentPath !== $edit->path) {
                $currentPath = $edit->path;
                $maxEnd = null;
                $coveringEdit = null;
            }
            if ($maxEnd !== null && $edit->startFilePos <= $maxEnd) {
                $blockers[] = sprintf(
                    'Class move edits overlap in %s at byte ranges %d-%d and %d-%d.',
                    $edit->path,
                    $coveringEdit->startFilePos,
                    $coveringEdit->endFilePos,
                    $edit->startFilePos,
                    $edit->endFilePos,
                );
            }
            if ($maxEnd === null || $edit->endFilePos > $maxEnd) {
                $maxEnd = $edit->endFilePos;
                $coveringEdit = $edit;
            }
        }

        return $blockers;
    }

    /**
     * @param list<PlanEdit> $edits
     * @param list<PlanMove> $moves
     * @param list<PlanBlindSpot> $blindSpots
     * @param list<PlanStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     */
    private function result(
        AgentMapIndex $map,
        SymbolEntry $symbol,
        string $sourceFqn,
        string $destinationFqn,
        ?ClassMoveAutoloadEvidence $autoload,
        array $edits,
        array $moves,
        array $blindSpots,
        array $staleEvidence,
        array $blockers,
    ): ClassMovePlan {
        $blockers = array_values(array_unique($blockers));
        $blindSpots = $this->uniqueBlindSpots($blindSpots);
        $status = $staleEvidence !== [] || $blockers !== []
            ? ClassMovePlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? ClassMovePlan::STATUS_REVIEW_REQUIRED : ClassMovePlan::STATUS_SAFE);

        return new ClassMovePlan(
            status: $status,
            targetId: $symbol->id(),
            sourceFqn: $sourceFqn,
            destinationFqn: $destinationFqn,
            provenance: new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            autoload: $autoload,
            edits: $status === ClassMovePlan::STATUS_BLOCKED ? [] : $edits,
            moves: $status === ClassMovePlan::STATUS_BLOCKED ? [] : $moves,
            blindSpots: $blindSpots,
            staleEvidence: $staleEvidence,
            blockers: $blockers,
            notObservable: self::NOT_OBSERVABLE,
        );
    }

    /**
     * @param list<PlanBlindSpot> $blindSpots
     * @return list<PlanBlindSpot>
     */
    private function uniqueBlindSpots(array $blindSpots): array
    {
        $unique = [];
        foreach ($blindSpots as $blindSpot) {
            $unique[implode(':', [$blindSpot->kind, $blindSpot->path ?? '', (string) ($blindSpot->lineStart ?? 0), (string) ($blindSpot->lineEnd ?? 0)])] = $blindSpot;
        }

        return array_values($unique);
    }

    private function namespace(string $fqn): string
    {
        $separator = strrpos($fqn, '\\');

        return $separator === false ? '' : substr($fqn, 0, $separator);
    }

    private function shortName(string $fqn): string
    {
        $separator = strrpos($fqn, '\\');

        return $separator === false ? $fqn : substr($fqn, $separator + 1);
    }
}
