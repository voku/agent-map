<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\ResolvedMethod;

/** Builds a read-only, fail-closed rename plan for one PHP method family. */
final readonly class MethodRenamePlanner
{
    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'String callbacks, reflection and framework configuration outside PHPStan call relations are not automatically rewritten.',
        'Dynamically constructed method names whose receiver type does not resolve to the rename family are outside the observable envelope.',
        'PHP source outside the indexed map scope is outside the observable envelope.',
    ];

    public function plan(AgentMapIndex $map, string $target, string $replacementName): MethodRenamePlan
    {
        $replacementName = trim($replacementName);
        $this->assertMethodName($replacementName);

        $seed = $map->resolveMethod($target);
        $originalName = $seed->method->name;
        if (strcasecmp($replacementName, $originalName) === 0) {
            throw new InvalidArgumentException('Replacement method name is semantically identical to the current name: ' . $originalName);
        }

        $blockers = [];
        $blindSpots = [];
        $staleEvidence = array_map(
            static fn (array $entry): RenameStaleEvidence => new RenameStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );
        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Method rename requires a PHPStan-backed map so caller identity is semantic rather than textual.';
        }

        if ($staleEvidence !== [] || $blockers !== []) {
            return $this->result($map, $seed, $originalName, $replacementName, [$seed->id => $seed], [], $blindSpots, $staleEvidence, $blockers);
        }

        [$family, $familyBlockers] = $this->family($map, $seed);
        $blockers = [...$blockers, ...$familyBlockers];
        foreach ($family as $method) {
            if ($method->owner->kind === 'trait') {
                $blockers[] = 'Trait method rename is blocked until trait alias and insteadof adaptations are represented as rename evidence: ' . $method->id;
            }
        }
        $blockers = [...$blockers, ...$this->collisionBlockers($map, $family, $replacementName)];
        $blockers = array_values(array_unique($blockers));

        if ($blockers !== []) {
            return $this->result($map, $seed, $originalName, $replacementName, $family, [], $blindSpots, $staleEvidence, $blockers);
        }

        $locator = new SourceNameLocator($map->root);
        $edits = [];
        foreach ($family as $method) {
            try {
                $position = $locator->declaration(
                    $method->file->path,
                    $method->method->lineStart,
                    $method->method->lineEnd,
                    $method->method->name,
                );
                $edits[] = new RenameEdit(
                    path: $method->file->path,
                    sourceSha256: $method->file->sha256,
                    startFilePos: $position['start_file_pos'],
                    endFilePos: $position['end_file_pos'],
                    lineStart: $method->method->lineStart,
                    lineEnd: $method->method->lineEnd,
                    expected: $position['actual'],
                    replacement: $replacementName,
                    role: 'declaration',
                    symbolId: $method->id,
                    resolution: 'phpstan_resolved',
                );
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $familyIds = array_fill_keys(array_keys($family), true);
        foreach ($map->relations as $relation) {
            if ($relation->kind !== 'calls') {
                continue;
            }

            $familyTargets = array_values(array_filter(
                $relation->targetIds,
                static fn (string $targetId): bool => isset($familyIds[$targetId]),
            ));
            if ($familyTargets === []) {
                $this->recordDynamicBlindSpot($relation, $family, $blindSpots);
                continue;
            }

            $outsideTargets = array_values(array_filter(
                $relation->targetIds,
                static fn (string $targetId): bool => !isset($familyIds[$targetId]),
            ));
            if ($outsideTargets !== []) {
                $blockers[] = sprintf(
                    'Call at %s:%d-%d can target both the rename family and outside method(s): %s.',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    implode(', ', $outsideTargets),
                );
                continue;
            }
            if (!in_array($relation->resolution, ['phpstan_resolved', 'multiple_targets'], true)) {
                $blockers[] = sprintf(
                    'Call at %s:%d-%d reaches the rename family with unsupported resolution "%s".',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    $relation->resolution,
                );
                continue;
            }

            $file = $map->file($relation->file);
            if ($file === null) {
                $blockers[] = 'Call relation points outside the indexed source set: ' . $relation->file;
                continue;
            }

            try {
                $position = $locator->call($relation->file, $relation->lineStart, $relation->lineEnd, $originalName);
                $edits[] = new RenameEdit(
                    path: $relation->file,
                    sourceSha256: $file->sha256,
                    startFilePos: $position['start_file_pos'],
                    endFilePos: $position['end_file_pos'],
                    lineStart: $relation->lineStart,
                    lineEnd: $relation->lineEnd,
                    expected: $position['actual'],
                    replacement: $replacementName,
                    role: 'call',
                    symbolId: implode(',', $familyTargets),
                    resolution: $relation->resolution,
                );
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $edits = $this->uniqueSortedEdits($edits);
        $blockers = [...$blockers, ...$this->overlapBlockers($edits)];
        $blockers = array_values(array_unique($blockers));

        return $this->result($map, $seed, $originalName, $replacementName, $family, $edits, $blindSpots, $staleEvidence, $blockers);
    }

    /**
     * @return array{0: array<string, ResolvedMethod>, 1: list<string>}
     */
    private function family(AgentMapIndex $map, ResolvedMethod $seed): array
    {
        $family = [$seed->id => $seed];
        $blockers = [];
        $queue = [$seed->id];

        for ($offset = 0; isset($queue[$offset]); ++$offset) {
            $current = $queue[$offset];
            foreach ($map->relations as $relation) {
                if ($relation->kind !== 'overrides') {
                    continue;
                }

                $candidates = [];
                if ($relation->sourceId === $current) {
                    $candidates = $relation->targetIds;
                } elseif (in_array($current, $relation->targetIds, true)) {
                    $candidates[] = $relation->sourceId;
                }

                foreach ($candidates as $candidateId) {
                    if (isset($family[$candidateId])) {
                        continue;
                    }
                    $candidate = $map->resolvedMethodById($candidateId);
                    if ($candidate === null) {
                        $blockers[] = 'Rename family crosses an unindexed method contract: ' . $candidateId;
                        continue;
                    }
                    $family[$candidateId] = $candidate;
                    $queue[] = $candidateId;
                }
            }
        }

        ksort($family, SORT_STRING);

        return [$family, array_values(array_unique($blockers))];
    }

    /**
     * Replacement-name collisions matter only in the type hierarchy that can inherit the renamed
     * method, plus directly implemented contracts and directly composed traits. An unrelated class
     * that merely shares some second interface is not part of this rename family.
     *
     * @param array<string, ResolvedMethod> $family
     * @return list<string>
     */
    private function collisionBlockers(AgentMapIndex $map, array $family, string $replacementName): array
    {
        $familyIds = array_fill_keys(array_keys($family), true);
        $traversal = [];
        foreach ($family as $method) {
            $traversal[$method->owner->id()] = true;
        }

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($map->relations as $relation) {
                if ($relation->kind === 'extends') {
                    if (isset($traversal[$relation->sourceId])) {
                        foreach ($relation->targetIds as $targetId) {
                            $changed = $this->addIndexedType($map, $traversal, $targetId) || $changed;
                        }
                    }
                    foreach ($relation->targetIds as $targetId) {
                        if (isset($traversal[$targetId])) {
                            $changed = $this->addIndexedType($map, $traversal, $relation->sourceId) || $changed;
                            break;
                        }
                    }
                    continue;
                }

                if ($relation->kind !== 'implements') {
                    continue;
                }
                foreach ($relation->targetIds as $targetId) {
                    if (!isset($traversal[$targetId])) {
                        continue;
                    }
                    $target = $map->symbolById($targetId);
                    if (($target['symbol']->kind ?? null) !== 'interface') {
                        continue;
                    }
                    $changed = $this->addIndexedType($map, $traversal, $relation->sourceId) || $changed;
                    break;
                }
            }
        }

        $scan = $traversal;
        foreach ($map->relations as $relation) {
            if (!isset($traversal[$relation->sourceId])) {
                continue;
            }
            if (!in_array($relation->kind, ['implements', 'uses_trait'], true)) {
                continue;
            }
            foreach ($relation->targetIds as $targetId) {
                $this->addIndexedType($map, $scan, $targetId);
            }
        }

        $blockers = [];
        foreach (array_keys($scan) as $typeId) {
            $resolved = $map->symbolById($typeId);
            if ($resolved === null) {
                continue;
            }
            foreach ($resolved['symbol']->methods as $method) {
                if (strcasecmp($method->name, $replacementName) !== 0) {
                    continue;
                }
                $methodId = $resolved['symbol']->methodId($method);
                if (!isset($familyIds[$methodId])) {
                    $blockers[] = sprintf('Replacement name would collide with related method %s.', $methodId);
                }
            }
        }

        sort($blockers, SORT_STRING);

        return $blockers;
    }

    /** @param array<string, true> $types */
    private function addIndexedType(AgentMapIndex $map, array &$types, string $typeId): bool
    {
        if (isset($types[$typeId]) || $map->symbolById($typeId) === null) {
            return false;
        }

        $types[$typeId] = true;

        return true;
    }

    /**
     * @param array<string, ResolvedMethod> $family
     * @param list<RenameBlindSpot> $blindSpots
     */
    private function recordDynamicBlindSpot(RelationEntry $relation, array $family, array &$blindSpots): void
    {
        if ($relation->resolution !== 'dynamic' || $relation->receiverType === null) {
            return;
        }
        foreach ($family as $method) {
            if (!$this->receiverTypeContainsOwner($relation->receiverType, $method->owner->fqn)) {
                continue;
            }
            $blindSpots[] = new RenameBlindSpot(
                kind: 'dynamic_method_name',
                message: 'Dynamic method dispatch on a rename-family receiver cannot prove which runtime method name is used.',
                path: $relation->file,
                lineStart: $relation->lineStart,
                lineEnd: $relation->lineEnd,
            );
            return;
        }
    }

    private function receiverTypeContainsOwner(string $receiverType, string $ownerFqn): bool
    {
        $ownerPattern = preg_quote(ltrim($ownerFqn, '\\'), '/');

        return preg_match(
            '/(?<![A-Za-z0-9_\\\\])\\\\?' . $ownerPattern . '(?![A-Za-z0-9_\\\\])/i',
            $receiverType,
        ) === 1;
    }

    /**
     * @param list<RenameEdit> $edits
     * @return list<RenameEdit>
     */
    private function uniqueSortedEdits(array $edits): array
    {
        $unique = [];
        foreach ($edits as $edit) {
            $key = $edit->path . ':' . $edit->startFilePos . ':' . $edit->endFilePos;
            $unique[$key] = $edit;
        }
        $edits = array_values($unique);
        usort(
            $edits,
            static fn (RenameEdit $left, RenameEdit $right): int => $left->path <=> $right->path ?: $left->startFilePos <=> $right->startFilePos,
        );

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
                    'Rename edits overlap in %s at byte ranges %d-%d and %d-%d.',
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
     * @param array<string, ResolvedMethod> $family
     * @param list<RenameEdit> $edits
     * @param list<RenameBlindSpot> $blindSpots
     * @param list<RenameStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     */
    private function result(
        AgentMapIndex $map,
        ResolvedMethod $seed,
        string $originalName,
        string $replacementName,
        array $family,
        array $edits,
        array $blindSpots,
        array $staleEvidence,
        array $blockers,
    ): MethodRenamePlan {
        $familyIds = array_keys($family);
        sort($familyIds, SORT_STRING);
        $blockers = array_values(array_unique($blockers));
        $blindSpots = $this->uniqueBlindSpots($blindSpots);
        $status = $staleEvidence !== [] || $blockers !== []
            ? MethodRenamePlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? MethodRenamePlan::STATUS_REVIEW_REQUIRED : MethodRenamePlan::STATUS_SAFE);

        return new MethodRenamePlan(
            status: $status,
            targetId: $seed->id,
            originalName: $originalName,
            replacementName: $replacementName,
            provenance: $this->provenance($map),
            family: $familyIds,
            edits: $status === MethodRenamePlan::STATUS_BLOCKED ? [] : $edits,
            blindSpots: $blindSpots,
            staleEvidence: $staleEvidence,
            blockers: $blockers,
            notObservable: self::NOT_OBSERVABLE,
        );
    }

    private function provenance(AgentMapIndex $map): MethodRenameProvenance
    {
        return new MethodRenameProvenance($map->mapDigest(), $map->backend, $map->fingerprint);
    }

    /**
     * @param list<RenameBlindSpot> $blindSpots
     * @return list<RenameBlindSpot>
     */
    private function uniqueBlindSpots(array $blindSpots): array
    {
        $unique = [];
        foreach ($blindSpots as $blindSpot) {
            $key = implode(':', [
                $blindSpot->kind,
                $blindSpot->path ?? '',
                (string) ($blindSpot->lineStart ?? 0),
                (string) ($blindSpot->lineEnd ?? 0),
            ]);
            $unique[$key] = $blindSpot;
        }

        return array_values($unique);
    }

    private function assertMethodName(string $name): void
    {
        if ($name === '' || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid PHP method name: ' . $name);
        }
    }
}
