<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;

/** Builds a read-only, fail-closed plan for one proven private PHP property rename. */
final readonly class PropertyRenamePlanner
{
    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'Reflection, serialization metadata, property_exists/get_object_vars usage and framework configuration strings are not automatically rewritten.',
        'PHPDoc and arbitrary strings containing property names are outside the exact property-token contract.',
        'PHP source outside the indexed map scope and non-PHP configuration are outside the observable envelope.',
        'Public/protected inheritance semantics, promoted properties and PHP 8.4 property hooks are intentionally unsupported by contract 1.0.',
    ];

    public function plan(AgentMapIndex $map, string $target, string $replacementName): PropertyRenamePlan
    {
        [$ownerTarget, $originalName] = $this->parseTarget($target);
        $replacementName = ltrim(trim($replacementName), '$');
        $this->assertPropertyName($replacementName);
        if ($originalName === $replacementName) {
            throw new InvalidArgumentException('Replacement property name is identical to the current name: $' . $originalName);
        }
        if (strcasecmp($originalName, $replacementName) === 0) {
            throw new InvalidArgumentException('Case-only property renames are intentionally unsupported by property_rename_plan 1.0.');
        }

        [$file, $owner] = $this->resolveOwner($map, $ownerTarget);
        $ownerFqn = ltrim($owner->fqn, '\\');
        $targetId = 'property:' . $ownerFqn . '::$' . $originalName;

        $staleEntries = $map->staleEntries();
        usort($staleEntries, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        /** @var list<PlanStaleEvidence> $staleEvidence */
        $staleEvidence = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $staleEntries,
        );
        /** @var list<string> $blockers */
        $blockers = [];
        /** @var list<PlanBlindSpot> $blindSpots */
        $blindSpots = [];

        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Property rename requires a PHPStan-backed map so declaration/access identity is semantic rather than textual.';
        }
        if ($owner->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot rename a property on a conflicted class identity: ' . $ownerFqn;
        }
        if ($staleEvidence !== [] || $blockers !== []) {
            return $this->result($map, $targetId, $ownerFqn, $originalName, $replacementName, [], $blindSpots, $staleEvidence, $blockers);
        }

        $declarations = array_values(array_filter(
            $map->relations,
            static fn (RelationEntry $relation): bool => $relation->kind === 'declares_property'
                && in_array($targetId, $relation->targetIds, true),
        ));
        if (count($declarations) !== 1 || $declarations[0]->resolution !== 'phpstan_resolved') {
            $blockers[] = sprintf(
                'Property declaration identity %s is not backed by exactly one PHPStan declaration relation.',
                $targetId,
            );
        }

        $locator = new PropertyNameLocator($map->root);
        $declaration = null;
        try {
            $declaration = $locator->declaration($file->path, $ownerFqn, $originalName);
            if ($declaration['promoted']) {
                $blockers[] = 'Promoted property rename is blocked because the same token is also a constructor parameter and may participate in named-argument calls.';
            }
            if ($declaration['hooks']) {
                $blockers[] = 'Property hooks are blocked until hook dispatch and asymmetric visibility are represented as explicit rename evidence.';
            }
            if ($declaration['visibility'] !== 'private') {
                $blockers[] = 'Property rename 1.0 supports private properties only; public/protected inheritance and shadowing semantics require a later evidence contract.';
            }
            if ($locator->replacementExists($file->path, $ownerFqn, $replacementName)) {
                $blockers[] = sprintf('Replacement property $%s already exists on %s.', $replacementName, $ownerFqn);
            }
        } catch (RuntimeException $exception) {
            $blockers[] = $exception->getMessage();
        }

        foreach ($owner->methods as $method) {
            if (in_array(strtolower($method->name), ['__get', '__set', '__isset', '__unset'], true)) {
                $blindSpots[] = new PlanBlindSpot(
                    kind: 'magic_property_dispatch',
                    message: 'The owner defines magic property dispatch; runtime property-name strings may overlap the renamed private property.',
                    path: $file->path,
                    lineStart: $method->lineStart,
                    lineEnd: $method->lineEnd,
                );
            }
        }

        if ($blockers !== [] || $declaration === null) {
            return $this->result($map, $targetId, $ownerFqn, $originalName, $replacementName, [], $blindSpots, $staleEvidence, $blockers);
        }

        /** @var list<PlanEdit> $edits */
        $edits = [new PlanEdit(
            path: $file->path,
            sourceSha256: $file->sha256,
            startFilePos: $declaration['start_file_pos'],
            endFilePos: $declaration['end_file_pos'],
            lineStart: $declaration['line_start'],
            lineEnd: $declaration['line_end'],
            expected: $declaration['actual'],
            replacement: $locator->replacementToken($declaration['actual'], $replacementName),
            role: 'property_declaration',
            symbolId: $targetId,
            resolution: 'phpstan_resolved',
        )];

        foreach ($map->relations as $relation) {
            if ($relation->kind !== 'property_access') {
                continue;
            }
            if (
                $relation->resolution === 'dynamic'
                && $relation->targetIds === ['unresolved:property_access']
                && $this->receiverMayContain($relation->receiverType, $ownerFqn)
                && $locator->isDynamicAccess($relation->file, $relation->lineStart, $relation->lineEnd)
            ) {
                $blindSpots[] = new PlanBlindSpot(
                    kind: 'dynamic_property_name',
                    message: 'A dynamic property access on the target owner may resolve to the renamed property at runtime.',
                    path: $relation->file,
                    lineStart: $relation->lineStart,
                    lineEnd: $relation->lineEnd,
                );
                continue;
            }
            if (!in_array($targetId, $relation->targetIds, true)) {
                continue;
            }
            $outsideTargets = array_values(array_filter(
                $relation->targetIds,
                static fn (string $candidate): bool => $candidate !== $targetId,
            ));
            if ($outsideTargets !== []) {
                $blockers[] = sprintf(
                    'Property access at %s:%d-%d can resolve both to %s and outside property identity(s): %s.',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    $targetId,
                    implode(', ', $outsideTargets),
                );
                continue;
            }
            if ($relation->resolution !== 'phpstan_resolved') {
                $blockers[] = sprintf(
                    'Property access at %s:%d-%d reaches the target with unsupported resolution "%s".',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    $relation->resolution,
                );
                continue;
            }
            $accessFile = $map->file($relation->file);
            if ($accessFile === null) {
                $blockers[] = 'Property access relation points outside the indexed source set: ' . $relation->file;
                continue;
            }
            try {
                $position = $locator->access($relation->file, $relation->lineStart, $relation->lineEnd, $originalName);
                $edits[] = new PlanEdit(
                    path: $relation->file,
                    sourceSha256: $accessFile->sha256,
                    startFilePos: $position['start_file_pos'],
                    endFilePos: $position['end_file_pos'],
                    lineStart: $position['line_start'],
                    lineEnd: $position['line_end'],
                    expected: $position['actual'],
                    replacement: $locator->replacementToken($position['actual'], $replacementName),
                    role: 'property_access',
                    symbolId: $targetId,
                    resolution: $relation->resolution,
                );
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $edits = $this->uniqueSortedEdits($edits);
        foreach ($this->overlapBlockers($edits) as $blocker) {
            $blockers[] = $blocker;
        }

        return $this->result($map, $targetId, $ownerFqn, $originalName, $replacementName, $edits, $blindSpots, $staleEvidence, $blockers);
    }

    /** @return array{0: string, 1: string} */
    private function parseTarget(string $target): array
    {
        $target = ltrim(trim($target), '\\');
        $separator = strrpos($target, '::$');
        if ($separator === false) {
            throw new InvalidArgumentException('Property target must use Class::$property syntax: ' . $target);
        }
        $owner = substr($target, 0, $separator);
        $property = substr($target, $separator + 3);
        if ($owner === '' || $property === '') {
            throw new InvalidArgumentException('Property target must use Class::$property syntax: ' . $target);
        }
        $this->assertPropertyName($property);

        return [$owner, $property];
    }

    /** @return array{0: FileEntry, 1: SymbolEntry} */
    private function resolveOwner(AgentMapIndex $map, string $target): array
    {
        $qualified = str_contains($target, '\\');
        /** @var list<array{0: FileEntry, 1: SymbolEntry}> $matches */
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
                    $matches[] = [$file, $symbol];
                }
            }
        }
        if ($matches === []) {
            throw new RuntimeException('Property owner class not found: ' . $target);
        }
        if (count($matches) > 1) {
            $candidates = array_map(static fn (array $match): string => $match[1]->fqn, $matches);
            sort($candidates, SORT_STRING);
            throw new RuntimeException('Property owner is ambiguous: ' . $target . "\nUse a fully-qualified class name:\n- " . implode("\n- ", $candidates));
        }

        return $matches[0];
    }

    private function receiverMayContain(?string $receiverType, string $ownerFqn): bool
    {
        if ($receiverType === null || $receiverType === '') {
            return false;
        }
        $pattern = '/(?<![A-Za-z0-9_\\\\])' . preg_quote($ownerFqn, '/') . '(?![A-Za-z0-9_\\\\])/i';

        return preg_match($pattern, $receiverType) === 1;
    }

    /**
     * @param list<PlanEdit> $edits
     * @return list<PlanEdit>
     */
    private function uniqueSortedEdits(array $edits): array
    {
        /** @var array<string, PlanEdit> $unique */
        $unique = [];
        foreach ($edits as $edit) {
            $unique[$edit->path . ':' . $edit->startFilePos . ':' . $edit->endFilePos] = $edit;
        }
        $result = array_values($unique);
        usort($result, static fn (PlanEdit $left, PlanEdit $right): int => $left->path <=> $right->path ?: $left->startFilePos <=> $right->startFilePos);

        return $result;
    }

    /**
     * @param list<PlanEdit> $edits
     * @return list<string>
     */
    private function overlapBlockers(array $edits): array
    {
        /** @var list<string> $blockers */
        $blockers = [];
        /** @var array<string, PlanEdit> $coveringByPath */
        $coveringByPath = [];
        foreach ($edits as $edit) {
            $covering = $coveringByPath[$edit->path] ?? null;
            if ($covering !== null && $edit->startFilePos <= $covering->endFilePos) {
                $blockers[] = sprintf(
                    'Property rename edits overlap in %s at byte ranges %d-%d and %d-%d.',
                    $edit->path,
                    $covering->startFilePos,
                    $covering->endFilePos,
                    $edit->startFilePos,
                    $edit->endFilePos,
                );
            }
            if ($covering === null || $edit->endFilePos > $covering->endFilePos) {
                $coveringByPath[$edit->path] = $edit;
            }
        }

        return $blockers;
    }

    /**
     * @param list<PlanEdit> $edits
     * @param list<PlanBlindSpot> $blindSpots
     * @param list<PlanStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     */
    private function result(
        AgentMapIndex $map,
        string $targetId,
        string $ownerFqn,
        string $originalName,
        string $replacementName,
        array $edits,
        array $blindSpots,
        array $staleEvidence,
        array $blockers,
    ): PropertyRenamePlan {
        $blockers = array_values(array_unique($blockers));
        $blindSpots = $this->uniqueBlindSpots($blindSpots);
        $status = $staleEvidence !== [] || $blockers !== []
            ? PropertyRenamePlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? PropertyRenamePlan::STATUS_REVIEW_REQUIRED : PropertyRenamePlan::STATUS_SAFE);

        return new PropertyRenamePlan(
            status: $status,
            targetId: $targetId,
            ownerFqn: $ownerFqn,
            originalName: $originalName,
            replacementName: $replacementName,
            provenance: new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            edits: $status === PropertyRenamePlan::STATUS_BLOCKED ? [] : $edits,
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
        /** @var array<string, PlanBlindSpot> $unique */
        $unique = [];
        foreach ($blindSpots as $blindSpot) {
            $unique[implode(':', [$blindSpot->kind, $blindSpot->path ?? '', (string) ($blindSpot->lineStart ?? 0), (string) ($blindSpot->lineEnd ?? 0)])] = $blindSpot;
        }

        return array_values($unique);
    }

    private function assertPropertyName(string $name): void
    {
        if ($name === '' || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid PHP property name: $' . $name);
        }
    }
}
