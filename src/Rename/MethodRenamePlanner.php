<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\ResolvedMethod;
use voku\AgentMap\Removal\MethodNodeRemover;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Reference\NonPhpReferenceScanner;
use voku\AgentMap\Reference\ReferenceTarget;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

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
        $staleEntries = $map->staleEntries();
        usort($staleEntries, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        $staleEvidence = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $staleEntries,
        );
        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Method rename requires a PHPStan-backed map so caller identity is semantic rather than textual.';
        }

        if ($staleEvidence !== [] || $blockers !== []) {
            return $this->result($map, $seed, $originalName, $replacementName, [$seed->id => $seed], [], $blindSpots, $staleEvidence, $blockers);
        }

        [$family, $anonymous, $familyBlockers] = $this->family($map, $seed);
        $blockers = [...$blockers, ...$familyBlockers];
        foreach ($family as $method) {
            if ($method->owner->kind === 'trait') {
                $blockers[] = 'Trait method rename is blocked until trait alias and insteadof adaptations are represented as rename evidence: ' . $method->id;
            }
        }
        $blockers = [...$blockers, ...$this->collisionBlockers($map, $family, $replacementName)];
        $blockers = [...$blockers, ...$this->anonymousCollisionBlockers($map, $anonymous, $originalName, $replacementName)];

        // A callable is not a call relation. Renaming only the declaration while
        // `[$this, 'name']` or `'Class::name'` still names the old method leaves
        // source that parses and then fatals, so the rename is refused for the
        // same reason removal refuses it. First-class callable syntax is the
        // exception when the map resolved it: it is recorded as a call relation
        // with its byte range, and the relation loop below rewrites it.
        $nodeRemover = new MethodNodeRemover($map->root);
        foreach ($family as $method) {
            foreach ($map->files as $file) {
                if ($nodeRemover->hasNonFirstClassCallableReference($file->path, $method->method->name)
                    || !$this->firstClassCallablesAreResolved($map, $nodeRemover, $file->path, $method->method->name)) {
                    $blockers[] = sprintf(
                        'A callable naming this method exists in indexed source %s; building a callable is not a call relation, so the rename cannot prove every reference is rewritten.',
                        $file->path,
                    );
                }
            }
        }

        $blockers = array_values(array_unique($blockers));

        if ($blockers !== []) {
            return $this->result($map, $seed, $originalName, $replacementName, $family, [], $blindSpots, $staleEvidence, $blockers, array_keys($anonymous));
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
                $edits[] = new PlanEdit(
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

        // An anonymous class has no symbol in the structural index, but PHPStan names its methods and records their
        // overrides with file and lines, so the declaration is located exactly like a named one.
        foreach ($anonymous as $anonymousId => $member) {
            $file = $map->file($member['path']);
            if ($file === null) {
                $blockers[] = 'Anonymous rename family member is outside the indexed files: ' . $anonymousId;
                continue;
            }
            try {
                $position = $locator->declaration($member['path'], $member['line_start'], $member['line_end'], $originalName);
                $edits[] = new PlanEdit(
                    path: $member['path'],
                    sourceSha256: $file->sha256,
                    startFilePos: $position['start_file_pos'],
                    endFilePos: $position['end_file_pos'],
                    lineStart: $member['line_start'],
                    lineEnd: $member['line_end'],
                    expected: $position['actual'],
                    replacement: $replacementName,
                    role: 'declaration',
                    symbolId: $anonymousId,
                    resolution: 'phpstan_resolved',
                );
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $familyIds = array_fill_keys([...array_keys($family), ...array_keys($anonymous)], true);
        $anonymousOwners = array_values(array_unique(array_map(
            static fn (string $anonymousId): string => substr($anonymousId, strlen('method:'), (int) strpos($anonymousId, '::') - strlen('method:')),
            array_keys($anonymous),
        )));
        foreach ($map->relations as $relation) {
            if ($relation->kind !== 'calls') {
                continue;
            }

            $familyTargets = array_values(array_filter(
                $relation->targetIds,
                static fn (string $targetId): bool => isset($familyIds[$targetId]),
            ));
            if ($familyTargets === []) {
                $this->recordDynamicBlindSpot($relation, $family, $blindSpots, $anonymousOwners);
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
                $position = $locator->call($relation->file, $relation->lineStart, $relation->lineEnd, $originalName, $relation->startFilePos, $relation->endFilePos);
                $edits[] = new PlanEdit(
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

        return $this->result($map, $seed, $originalName, $replacementName, $family, $edits, $blindSpots, $staleEvidence, $blockers, array_keys($anonymous));
    }

    /**
     * @return array{0: array<string, ResolvedMethod>, 1: array<string, array{path: string, line_start: int, line_end: int}>, 2: list<string>}
     */
    private function family(AgentMapIndex $map, ResolvedMethod $seed): array
    {
        $family = [$seed->id => $seed];
        $anonymous = [];
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
                    if (isset($family[$candidateId]) || isset($anonymous[$candidateId])) {
                        continue;
                    }
                    $candidate = $map->resolvedMethodById($candidateId);
                    if ($candidate === null) {
                        // `new class implements Contract` overrides through its own relation, which carries the method's
                        // file and lines. Any other unindexed member stays a blocker.
                        if ($relation->sourceId === $candidateId && self::isAnonymousMethodId($candidateId)) {
                            $anonymous[$candidateId] = ['path' => $relation->file, 'line_start' => $relation->lineStart, 'line_end' => $relation->lineEnd];
                            $queue[] = $candidateId;
                            continue;
                        }
                        $blockers[] = 'Rename family crosses an unindexed method contract: ' . $candidateId;
                        continue;
                    }
                    $family[$candidateId] = $candidate;
                    $queue[] = $candidateId;
                }
            }
        }

        ksort($family, SORT_STRING);
        ksort($anonymous, SORT_STRING);

        return [$family, $anonymous, array_values(array_unique($blockers))];
    }

    private static function isAnonymousMethodId(string $methodId): bool
    {
        return preg_match('/^method:AnonymousClass[0-9a-f]{32}::[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $methodId) === 1;
    }

    /**
     * The replacement name must not already be a method of the anonymous class, nor of a type it extends, implements
     * or uses. Its methods are not in the symbol index, so the class is read from source: the owner is the one
     * anonymous class that directly declares the target method at its recorded lines. No such class, more than one,
     * or a related type outside the index blocks the rename.
     *
     * @param array<string, array{path: string, line_start: int, line_end: int}> $anonymous
     * @return list<string>
     */
    private function anonymousCollisionBlockers(AgentMapIndex $map, array $anonymous, string $originalName, string $replacementName): array
    {
        $blockers = [];
        foreach ($anonymous as $anonymousId => $member) {
            $source = @file_get_contents(rtrim($map->root, '/') . '/' . $member['path']);
            $owners = [];
            if (is_string($source)) {
                $owners = (new NodeFinder())->find(
                    PhpCodeParser::getAstFromString($source),
                    static fn (Node $node): bool => $node instanceof Class_
                        && $node->name === null
                        && array_filter(
                            $node->getMethods(),
                            static fn (ClassMethod $method): bool => strcasecmp($method->name->toString(), $originalName) === 0
                                && $method->getStartLine() === $member['line_start']
                                && $method->getEndLine() === $member['line_end'],
                        ) !== [],
                );
            }
            if (count($owners) !== 1 || !$owners[0] instanceof Class_) {
                $blockers[] = 'Anonymous rename family member could not be located in exactly one anonymous class: ' . $anonymousId;
                continue;
            }
            foreach ($owners[0]->getMethods() as $method) {
                if (strcasecmp($method->name->toString(), $replacementName) === 0) {
                    $blockers[] = sprintf('Replacement name would collide with method %s of anonymous class %s.', $method->name->toString(), $anonymousId);
                }
            }
            $blockers = [...$blockers, ...$this->anonymousRelatedTypeCollisions($map, $anonymousId, $replacementName)];
        }

        return $blockers;
    }

    /**
     * Types the anonymous class extends, implements or uses, and their indexed ancestors, must not declare the
     * replacement name; a related type that is not indexed cannot be checked and blocks.
     *
     * @return list<string>
     */
    private function anonymousRelatedTypeCollisions(AgentMapIndex $map, string $anonymousId, string $replacementName): array
    {
        $ownerId = 'class:' . substr($anonymousId, strlen('method:'), (int) strpos($anonymousId, '::') - strlen('method:'));
        $types = [];
        $queue = [$ownerId];
        for ($offset = 0; isset($queue[$offset]); ++$offset) {
            foreach ($map->relations as $relation) {
                if ($relation->sourceId !== $queue[$offset] || !in_array($relation->kind, ['extends', 'implements', 'uses_trait'], true)) {
                    continue;
                }
                foreach ($relation->targetIds as $targetId) {
                    if (!isset($types[$targetId])) {
                        $types[$targetId] = true;
                        $queue[] = $targetId;
                    }
                }
            }
        }

        $blockers = [];
        foreach (array_keys($types) as $typeId) {
            $resolved = $map->symbolById($typeId);
            if ($resolved === null) {
                $blockers[] = sprintf('Anonymous class %s relates to a type outside the index (%s), so a replacement-name collision cannot be ruled out.', $ownerId, $typeId);
                continue;
            }
            foreach ($resolved['symbol']->methods as $method) {
                if (strcasecmp($method->name, $replacementName) === 0) {
                    $blockers[] = sprintf('Replacement name would collide with method %s inherited by anonymous class %s.', $resolved['symbol']->methodId($method), $ownerId);
                }
            }
        }

        return $blockers;
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

    /**
     * Every first-class callable of the name in the file is a resolved call relation of the family, or of no family
     * member at all (another class's method of the same name), so the relation loop handles it exactly.
     */
    private function firstClassCallablesAreResolved(AgentMapIndex $map, MethodNodeRemover $nodeRemover, string $path, string $name): bool
    {
        foreach ($nodeRemover->firstClassCallableRanges($path, $name) as $range) {
            $resolved = false;
            foreach ($map->relations as $relation) {
                if ($relation->kind === 'calls'
                    && $relation->file === $path
                    && $relation->startFilePos === $range['start_file_pos']
                    && $relation->endFilePos === $range['end_file_pos']
                    && $relation->resolution === 'phpstan_resolved') {
                    $resolved = true;
                    break;
                }
            }
            if (!$resolved) {
                return false;
            }
        }

        return true;
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
     * @param list<PlanBlindSpot> $blindSpots
     * @param list<string> $anonymousOwners
     */
    private function recordDynamicBlindSpot(RelationEntry $relation, array $family, array &$blindSpots, array $anonymousOwners = []): void
    {
        if ($relation->resolution !== 'dynamic' || $relation->receiverType === null) {
            return;
        }
        $owners = [...array_map(static fn (ResolvedMethod $method): string => $method->owner->fqn, array_values($family)), ...$anonymousOwners];
        foreach ($owners as $owner) {
            if (!$this->receiverTypeContainsOwner($relation->receiverType, $owner)) {
                continue;
            }
            $blindSpots[] = new PlanBlindSpot(
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
     * @param list<PlanEdit> $edits
     * @return list<PlanEdit>
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
            static fn (PlanEdit $left, PlanEdit $right): int => $left->path <=> $right->path ?: $left->startFilePos <=> $right->startFilePos,
        );

        return $edits;
    }

    /**
     * @param list<PlanEdit> $edits
     * @return list<string>
     */
    private function overlapBlockers(array $edits): array
    {
        $blockers = [];
        $previous = null;
        foreach ($edits as $edit) {
            if ($previous instanceof PlanEdit && $previous->path === $edit->path && $edit->startFilePos <= $previous->endFilePos) {
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
     * @param list<PlanEdit> $edits
     * @param list<PlanBlindSpot> $blindSpots
     * @param list<PlanStaleEvidence> $staleEvidence
     * @param list<string> $blockers
     * @param list<string> $anonymousIds
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
        array $anonymousIds = [],
    ): MethodRenamePlan {
        $familyIds = [...array_keys($family), ...$anonymousIds];
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
            nonPhpReferences: $status === MethodRenamePlan::STATUS_BLOCKED ? null : (new NonPhpReferenceScanner())->scan(
                $map->root,
                ...array_map(
                    static fn (string $owner): ReferenceTarget => ReferenceTarget::method($owner, $originalName),
                    array_values(array_unique(array_map(static fn (ResolvedMethod $method): string => $method->owner->fqn, $family))),
                ),
            ),
        );
    }

    private function provenance(AgentMapIndex $map): PlanProvenance
    {
        return new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint);
    }

    /**
     * @param list<PlanBlindSpot> $blindSpots
     * @return list<PlanBlindSpot>
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
