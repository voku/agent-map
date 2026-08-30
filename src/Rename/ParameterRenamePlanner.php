<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\ResolvedMethod;

/** Builds a read-only, fail-closed rename plan for one method parameter family. */
final readonly class ParameterRenamePlanner
{
    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'Named callers in PHP source outside the indexed map scope are not automatically rewritten.',
        'Reflection, string callables and framework configuration outside semantic call relations are not automatically rewritten.',
        'Runtime argument arrays may contain named keys that static call evidence cannot prove.',
    ];

    public function plan(AgentMapIndex $map, string $target, string $originalName, string $replacementName): ParameterRenamePlan
    {
        $originalName = $this->parameterName($originalName);
        $replacementName = $this->parameterName($replacementName);
        if ($originalName === $replacementName) {
            throw new InvalidArgumentException('Replacement parameter name is identical to the current name: $' . $originalName);
        }

        $seed = $map->resolveMethod($target);
        $blockers = [];
        $blindSpots = [];
        $parameterIndexes = [];
        foreach ($seed->method->parameters as $index => $parameter) {
            if ($parameter->name === $originalName) {
                $parameterIndexes[] = $index;
            }
        }
        $parameterIndex = $parameterIndexes[0] ?? -1;
        if (count($parameterIndexes) !== 1) {
            $blockers[] = sprintf(
                'Target method %s must expose exactly one parameter named $%s; found %d.',
                $seed->id,
                $originalName,
                count($parameterIndexes),
            );
        }

        $staleEntries = $map->staleEntries();
        usort($staleEntries, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        $staleEvidence = array_map(
            static fn (array $entry): RenameStaleEvidence => new RenameStaleEvidence($entry['path'], $entry['reason']),
            $staleEntries,
        );
        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Parameter rename requires a PHPStan-backed map so caller identity is semantic rather than textual.';
        }

        if ($staleEvidence !== [] || $blockers !== []) {
            return $this->result(
                $map,
                $seed,
                $originalName,
                $replacementName,
                $parameterIndex,
                [$seed->id => $seed],
                [],
                $blindSpots,
                $staleEvidence,
                $blockers,
            );
        }

        [$family, $familyBlockers] = $this->family($map, $seed);
        $blockers = [...$blockers, ...$familyBlockers];
        foreach ($family as $method) {
            if ($method->owner->kind === 'trait') {
                $blockers[] = 'Trait method parameter rename is blocked until trait adaptation contracts are represented: ' . $method->id;
            }

            $parameter = $method->method->parameters[$parameterIndex] ?? null;
            if ($parameter === null || $parameter->name !== $originalName) {
                $actual = $parameter === null ? 'missing' : '$' . $parameter->name;
                $blockers[] = sprintf(
                    'Method family parameter %d is not consistently named $%s: %s exposes %s.',
                    $parameterIndex,
                    $originalName,
                    $method->id,
                    $actual,
                );
                continue;
            }

            foreach ($method->method->parameters as $index => $candidate) {
                if ($index !== $parameterIndex && $candidate->name === $replacementName) {
                    $blockers[] = sprintf(
                        'Replacement parameter $%s collides with parameter %d on %s.',
                        $replacementName,
                        $index,
                        $method->id,
                    );
                }
            }
        }
        $blockers = array_values(array_unique($blockers));
        if ($blockers !== []) {
            return $this->result($map, $seed, $originalName, $replacementName, $parameterIndex, $family, [], $blindSpots, $staleEvidence, $blockers);
        }

        if ($this->familyIsExternallyCallable($family)) {
            $blindSpots[] = new RenameBlindSpot(
                kind: 'out_of_scope_named_callers',
                message: 'Public/protected method-family callers outside the indexed PHP scope may use this parameter name.',
            );
        }

        $locator = new ParameterNameLocator($map->root);
        $edits = [];
        foreach ($family as $method) {
            try {
                $position = $locator->declaration(
                    $method->file->path,
                    $method->method->lineStart,
                    $method->method->lineEnd,
                    $method->method->name,
                    $originalName,
                    $parameterIndex,
                );
                $edits[] = new RenameEdit(
                    path: $method->file->path,
                    sourceSha256: $method->file->sha256,
                    startFilePos: $position['start_file_pos'],
                    endFilePos: $position['end_file_pos'],
                    lineStart: $position['line'],
                    lineEnd: $position['line'],
                    expected: $position['actual'],
                    replacement: '$' . $replacementName,
                    role: 'parameter_declaration',
                    symbolId: $method->id,
                    resolution: 'parser_exact',
                );

                $references = $locator->references(
                    $method->file->path,
                    $method->method->lineStart,
                    $method->method->lineEnd,
                    $method->method->name,
                    $originalName,
                    $replacementName,
                );
                if ($references['replacement_references'] !== []) {
                    $first = $references['replacement_references'][0];
                    $blockers[] = sprintf(
                        'Replacement variable $%s already exists in method scope %s at %s:%d; renaming would merge local bindings.',
                        $replacementName,
                        $method->id,
                        $method->file->path,
                        $first['line'],
                    );
                    continue;
                }

                foreach ($references['references'] as $reference) {
                    $edits[] = new RenameEdit(
                        path: $method->file->path,
                        sourceSha256: $method->file->sha256,
                        startFilePos: $reference['start_file_pos'],
                        endFilePos: $reference['end_file_pos'],
                        lineStart: $reference['line'],
                        lineEnd: $reference['line'],
                        expected: $reference['actual'],
                        replacement: '$' . $replacementName,
                        role: 'parameter_reference',
                        symbolId: $method->id,
                        resolution: 'parser_exact',
                    );
                }
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
                $this->recordDynamicBlindSpot($relation, $family, $locator, $originalName, $replacementName, $blindSpots, $blockers);
                continue;
            }

            $file = $map->file($relation->file);
            if ($file === null) {
                $blockers[] = 'Call relation points outside the indexed source set: ' . $relation->file;
                continue;
            }

            try {
                $call = $locator->call(
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    $seed->method->name,
                    $originalName,
                    $replacementName,
                );
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
                continue;
            }

            $isRelevant = $call['named'] !== [] || $call['has_unpack'];
            if (!$isRelevant) {
                continue;
            }

            $outsideTargets = array_values(array_filter(
                $relation->targetIds,
                static fn (string $targetId): bool => !isset($familyIds[$targetId]),
            ));
            if ($outsideTargets !== []) {
                $blockers[] = sprintf(
                    'Named/unpacked call at %s:%d-%d can target both the parameter family and outside method(s): %s.',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    implode(', ', $outsideTargets),
                );
                continue;
            }
            if (!in_array($relation->resolution, ['phpstan_resolved', 'multiple_targets'], true)) {
                $blockers[] = sprintf(
                    'Named/unpacked call at %s:%d-%d reaches the parameter family with unsupported resolution "%s".',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    $relation->resolution,
                );
                continue;
            }
            if ($call['named'] !== [] && $call['replacement_named'] !== []) {
                $blockers[] = sprintf(
                    'Renaming $%s to $%s would duplicate an existing named argument at %s:%d-%d.',
                    $originalName,
                    $replacementName,
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                );
                continue;
            }
            if ($call['has_unpack']) {
                $blindSpots[] = new RenameBlindSpot(
                    kind: 'argument_unpacking',
                    message: 'Argument unpacking may supply the renamed parameter as a runtime string key.',
                    path: $relation->file,
                    lineStart: $relation->lineStart,
                    lineEnd: $relation->lineEnd,
                );
            }

            foreach ($call['named'] as $position) {
                $edits[] = new RenameEdit(
                    path: $relation->file,
                    sourceSha256: $file->sha256,
                    startFilePos: $position['start_file_pos'],
                    endFilePos: $position['end_file_pos'],
                    lineStart: $position['line'],
                    lineEnd: $position['line'],
                    expected: $position['actual'],
                    replacement: $replacementName,
                    role: 'named_argument',
                    symbolId: implode(',', $familyTargets),
                    resolution: $relation->resolution,
                );
            }
        }

        $edits = $this->uniqueSortedEdits($edits);
        $blockers = [...$blockers, ...$this->overlapBlockers($edits)];
        $blockers = array_values(array_unique($blockers));

        return $this->result($map, $seed, $originalName, $replacementName, $parameterIndex, $family, $edits, $blindSpots, $staleEvidence, $blockers);
    }

    /** @return array{0: array<string, ResolvedMethod>, 1: list<string>} */
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
                        $blockers[] = 'Parameter family crosses an unindexed method contract: ' . $candidateId;
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

    /** @param array<string, ResolvedMethod> $family */
    private function familyIsExternallyCallable(array $family): bool
    {
        foreach ($family as $method) {
            if ($method->method->visibility !== 'private') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, ResolvedMethod> $family
     * @param list<RenameBlindSpot> $blindSpots
     * @param list<string> $blockers
     */
    private function recordDynamicBlindSpot(
        RelationEntry $relation,
        array $family,
        ParameterNameLocator $locator,
        string $originalName,
        string $replacementName,
        array &$blindSpots,
        array &$blockers,
    ): void {
        if ($relation->resolution !== 'dynamic' || $relation->receiverType === null) {
            return;
        }

        $matchesFamily = false;
        foreach ($family as $method) {
            if ($this->receiverTypeContainsOwner($relation->receiverType, $method->owner->fqn)) {
                $matchesFamily = true;
                break;
            }
        }
        if (!$matchesFamily) {
            return;
        }

        try {
            $call = $locator->call(
                $relation->file,
                $relation->lineStart,
                $relation->lineEnd,
                null,
                $originalName,
                $replacementName,
            );
        } catch (RuntimeException $exception) {
            $blockers[] = $exception->getMessage();
            return;
        }
        if ($call['named'] === [] && !$call['has_unpack']) {
            return;
        }

        $blindSpots[] = new RenameBlindSpot(
            kind: 'dynamic_parameter_call',
            message: 'Dynamic dispatch on a parameter-family receiver cannot prove which callable contract owns the named/unpacked argument.',
            path: $relation->file,
            lineStart: $relation->lineStart,
            lineEnd: $relation->lineEnd,
        );
    }

    private function receiverTypeContainsOwner(string $receiverType, string $ownerFqn): bool
    {
        $ownerPattern = preg_quote(ltrim($ownerFqn, '\\'), '/');

        return preg_match('/(?<![A-Za-z0-9_\\\\])\\\\?' . $ownerPattern . '(?![A-Za-z0-9_\\\\])/i', $receiverType) === 1;
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
                    'Parameter rename edits overlap in %s at byte ranges %d-%d and %d-%d.',
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
        int $parameterIndex,
        array $family,
        array $edits,
        array $blindSpots,
        array $staleEvidence,
        array $blockers,
    ): ParameterRenamePlan {
        $familyIds = array_keys($family);
        sort($familyIds, SORT_STRING);
        $blockers = array_values(array_unique($blockers));
        $blindSpots = $this->uniqueBlindSpots($blindSpots);
        $status = $staleEvidence !== [] || $blockers !== []
            ? ParameterRenamePlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? ParameterRenamePlan::STATUS_REVIEW_REQUIRED : ParameterRenamePlan::STATUS_SAFE);

        return new ParameterRenamePlan(
            status: $status,
            targetId: $seed->id,
            originalName: '$' . $originalName,
            replacementName: '$' . $replacementName,
            parameterIndex: $parameterIndex,
            provenance: new MethodRenameProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            family: $familyIds,
            edits: $status === ParameterRenamePlan::STATUS_BLOCKED ? [] : $edits,
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
            $key = implode(':', [$blindSpot->kind, $blindSpot->path ?? '', (string) ($blindSpot->lineStart ?? 0), (string) ($blindSpot->lineEnd ?? 0)]);
            $unique[$key] = $blindSpot;
        }

        return array_values($unique);
    }

    private function parameterName(string $name): string
    {
        $name = trim($name);
        if (str_starts_with($name, '$')) {
            $name = substr($name, 1);
        }
        if ($name === 'this' || $name === '' || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid PHP parameter name: $' . $name);
        }

        return $name;
    }
}
