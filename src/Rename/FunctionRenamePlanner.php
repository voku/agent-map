<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\SymbolEntry;

/** Builds a read-only, fail-closed plan for one PHP function rename. */
final readonly class FunctionRenamePlanner
{
    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'String callbacks, reflection and framework configuration are not automatically rewritten.',
        'Dynamically constructed function names cannot prove which runtime function name is used.',
        'PHP source outside the indexed map scope and non-PHP configuration are outside the observable envelope.',
        'Namespace moves, imported aliases and qualified function-call spellings require separate source evidence.',
    ];

    public function plan(AgentMapIndex $map, string $target, string $replacementName): FunctionRenamePlan
    {
        $target = ltrim(trim($target), '\\');
        $replacementName = trim($replacementName);
        $this->assertFunctionName($replacementName);
        if (str_contains($replacementName, '\\')) {
            throw new InvalidArgumentException('Function rename currently supports a replacement short name only; namespace moves require a separate evidence contract.');
        }

        [$file, $symbol] = $this->resolveFunction($map, $target);
        if (strcasecmp($symbol->name, $replacementName) === 0) {
            throw new InvalidArgumentException('Replacement function name is semantically identical to the current name: ' . $symbol->name);
        }

        $staleEntries = $map->staleEntries();
        usort($staleEntries, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        $staleEvidence = array_map(
            static fn (array $entry): RenameStaleEvidence => new RenameStaleEvidence($entry['path'], $entry['reason']),
            $staleEntries,
        );
        $blockers = [];
        $blindSpots = [];
        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Function rename requires a PHPStan-backed map so call identity is semantic rather than textual.';
        }
        if ($symbol->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot rename a function whose structural and semantic identity conflict: ' . $symbol->fqn;
        }
        $replacementFqn = $this->replacementFqn($symbol->fqn, $replacementName);
        foreach ($map->files as $candidateFile) {
            foreach ($candidateFile->symbols as $candidate) {
                if ($candidate->kind !== 'function' || $candidate->id() === $symbol->id()) {
                    continue;
                }
                if (strcasecmp(ltrim($candidate->fqn, '\\'), $replacementFqn) === 0) {
                    $blockers[] = 'Replacement function identity collides with indexed function ' . $candidate->fqn . '.';
                }
            }
        }

        if ($staleEvidence !== [] || $blockers !== []) {
            return $this->result($map, $symbol, $replacementName, [], $blindSpots, $staleEvidence, $blockers);
        }

        $locator = new FunctionNameLocator($map->root);
        $edits = [];
        try {
            $position = $locator->declaration($file->path, $symbol->lineStart, $symbol->lineEnd, $symbol->name);
            $edits[] = new RenameEdit(
                path: $file->path,
                sourceSha256: $file->sha256,
                startFilePos: $position['start_file_pos'],
                endFilePos: $position['end_file_pos'],
                lineStart: $symbol->lineStart,
                lineEnd: $symbol->lineEnd,
                expected: $position['actual'],
                replacement: $replacementName,
                role: 'declaration',
                symbolId: $symbol->id(),
                resolution: 'parser_resolved',
            );
        } catch (RuntimeException $exception) {
            $blockers[] = $exception->getMessage();
        }

        foreach ($map->relations as $relation) {
            if ($relation->kind !== 'calls') {
                continue;
            }
            if (
                $relation->resolution === 'dynamic'
                && $relation->targetIds === ['unresolved:calls']
                && $relation->receiverType === null
                && $locator->isDynamicFunctionCall($relation->file, $relation->lineStart, $relation->lineEnd)
            ) {
                $blindSpots[] = new RenameBlindSpot(
                    kind: 'dynamic_function_name',
                    message: 'A dynamic function call may invoke the renamed function at runtime.',
                    path: $relation->file,
                    lineStart: $relation->lineStart,
                    lineEnd: $relation->lineEnd,
                );
                continue;
            }
            if (!in_array($symbol->id(), $relation->targetIds, true)) {
                continue;
            }

            $outsideTargets = array_values(array_filter(
                $relation->targetIds,
                static fn (string $targetId): bool => $targetId !== $symbol->id(),
            ));
            if ($outsideTargets !== []) {
                $blockers[] = sprintf(
                    'Call at %s:%d-%d can target both the renamed function and outside symbol(s): %s.',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    implode(', ', $outsideTargets),
                );
                continue;
            }
            if ($relation->resolution !== 'phpstan_resolved') {
                $blockers[] = sprintf(
                    'Function call at %s:%d-%d reaches the target with unsupported resolution "%s".',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    $relation->resolution,
                );
                continue;
            }

            $callFile = $map->file($relation->file);
            if ($callFile === null) {
                $blockers[] = 'Function call relation points outside the indexed source set: ' . $relation->file;
                continue;
            }
            try {
                $position = $locator->call($relation->file, $relation->lineStart, $relation->lineEnd, $symbol->name);
                $edits[] = new RenameEdit(
                    path: $relation->file,
                    sourceSha256: $callFile->sha256,
                    startFilePos: $position['start_file_pos'],
                    endFilePos: $position['end_file_pos'],
                    lineStart: $relation->lineStart,
                    lineEnd: $relation->lineEnd,
                    expected: $position['actual'],
                    replacement: $replacementName,
                    role: 'call',
                    symbolId: $symbol->id(),
                    resolution: $relation->resolution,
                );
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $edits = $this->uniqueSortedEdits($edits);
        $blockers = [...$blockers, ...$this->overlapBlockers($edits)];

        return $this->result($map, $symbol, $replacementName, $edits, $blindSpots, $staleEvidence, $blockers);
    }

    /** @return array{0: FileEntry, 1: SymbolEntry} */
    private function resolveFunction(AgentMapIndex $map, string $target): array
    {
        if ($target === '') {
            throw new InvalidArgumentException('Function rename target cannot be empty.');
        }
        $qualified = str_contains($target, '\\');
        /** @var list<array{0: FileEntry, 1: SymbolEntry}> $matches */
        $matches = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->kind !== 'function') {
                    continue;
                }
                $matchesTarget = $qualified
                    ? strcasecmp(ltrim($symbol->fqn, '\\'), $target) === 0
                    : strcasecmp($symbol->name, $target) === 0;
                if ($matchesTarget) {
                    $matches[] = [$file, $symbol];
                }
            }
        }
        if ($matches === []) {
            throw new RuntimeException('Function target not found: ' . $target);
        }
        if (count($matches) > 1) {
            $candidates = [];
            foreach ($matches as $match) {
                $candidates[] = $match[1]->fqn;
            }
            sort($candidates, SORT_STRING);
            throw new RuntimeException('Function target is ambiguous: ' . $target . "\nUse a fully-qualified function name:\n- " . implode("\n- ", $candidates));
        }

        return $matches[0];
    }

    private function replacementFqn(string $originalFqn, string $replacementName): string
    {
        $originalFqn = ltrim($originalFqn, '\\');
        $separator = strrpos($originalFqn, '\\');
        if ($separator === false) {
            return $replacementName;
        }

        return substr($originalFqn, 0, $separator + 1) . $replacementName;
    }

    /**
     * @param list<RenameEdit> $edits
     * @return list<RenameEdit>
     */
    private function uniqueSortedEdits(array $edits): array
    {
        $unique = [];
        foreach ($edits as $edit) {
            $unique[$edit->path . ':' . $edit->startFilePos . ':' . $edit->endFilePos] = $edit;
        }
        $edits = array_values($unique);
        usort($edits, static fn (RenameEdit $left, RenameEdit $right): int => $left->path <=> $right->path ?: $left->startFilePos <=> $right->startFilePos);

        return $edits;
    }

    /**
     * @param list<RenameEdit> $edits
     * @return list<string>
     */
    private function overlapBlockers(array $edits): array
    {
        $blockers = [];
        $previous = null;
        foreach ($edits as $edit) {
            if ($previous instanceof RenameEdit && $previous->path === $edit->path && $edit->startFilePos <= $previous->endFilePos) {
                $blockers[] = sprintf(
                    'Function rename edits overlap in %s at byte ranges %d-%d and %d-%d.',
                    $edit->path,
                    $previous->startFilePos,
                    $previous->endFilePos,
                    $edit->startFilePos,
                    $edit->endFilePos,
                );
            }
            $previous = $edit;
        }

        return $blockers;
    }

    /**
     * @param list<RenameEdit> $edits
     * @param list<RenameBlindSpot> $blindSpots
     * @param list<RenameStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     */
    private function result(
        AgentMapIndex $map,
        SymbolEntry $symbol,
        string $replacementName,
        array $edits,
        array $blindSpots,
        array $staleEvidence,
        array $blockers,
    ): FunctionRenamePlan {
        $blockers = array_values(array_unique($blockers));
        $blindSpots = $this->uniqueBlindSpots($blindSpots);
        $status = $staleEvidence !== [] || $blockers !== []
            ? FunctionRenamePlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? FunctionRenamePlan::STATUS_REVIEW_REQUIRED : FunctionRenamePlan::STATUS_SAFE);

        return new FunctionRenamePlan(
            status: $status,
            targetId: $symbol->id(),
            originalName: $symbol->name,
            replacementName: $replacementName,
            provenance: new RenameProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            edits: $status === FunctionRenamePlan::STATUS_BLOCKED ? [] : $edits,
            blindSpots: $blindSpots,
            staleEvidence: $staleEvidence,
            blockers: $blockers,
            notObservable: self::NOT_OBSERVABLE,
        );
    }

    /**
     * @param list<RenameBlindSpot> $blindSpots
     * @return list<RenameBlindSpot>
     */
    private function uniqueBlindSpots(array $blindSpots): array
    {
        $unique = [];
        foreach ($blindSpots as $blindSpot) {
            $unique[implode(':', [$blindSpot->kind, $blindSpot->path ?? '', (string) ($blindSpot->lineStart ?? 0), (string) ($blindSpot->lineEnd ?? 0)])] = $blindSpot;
        }

        return array_values($unique);
    }

    private function assertFunctionName(string $name): void
    {
        if ($name === '' || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid PHP function name: ' . $name);
        }
    }
}
