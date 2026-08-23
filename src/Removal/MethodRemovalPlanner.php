<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Rename\RenameBlindSpot;
use voku\AgentMap\Rename\RenameEdit;
use voku\AgentMap\Rename\RenameProvenance;
use voku\AgentMap\Rename\RenameStaleEvidence;

/** Builds a fail-closed exact deletion plan, inspired by Rector's Removing rules. */
final readonly class MethodRemovalPlanner
{
    private const NOT_OBSERVABLE = [
        'Reflection, string callbacks, framework configuration and non-PHP configuration are not represented as PHPStan call relations.',
        'PHP source outside the indexed map scope is outside the observable envelope.',
    ];

    public function plan(AgentMapIndex $map, string $target): MethodRemovalPlan
    {
        $method = $map->resolveMethod($target);
        $blockers = [];
        $blindSpots = [];
        $stale = array_map(
            static fn (array $entry): RenameStaleEvidence => new RenameStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );
        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Method removal requires a PHPStan-backed map so usages are semantic rather than textual.';
        }
        if ($method->method->visibility !== 'private') {
            $blockers[] = 'Only private methods can be planned for removal; contracts and inheritors are intentionally excluded.';
        }
        if ($method->method->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot remove a method whose structural and semantic declarations conflict.';
        }

        foreach ($map->relations as $relation) {
            if ($relation->kind !== 'calls') {
                continue;
            }
            if (in_array($method->id, $relation->targetIds, true)) {
                $blockers[] = sprintf('Method is called at %s:%d-%d (%s).', $relation->file, $relation->lineStart, $relation->lineEnd, $relation->resolution);
            } elseif ($relation->resolution === 'dynamic' && $relation->receiverType !== null
                && strcasecmp(ltrim($relation->receiverType, '\\'), ltrim($method->owner->fqn, '\\')) === 0) {
                $blindSpots[] = new RenameBlindSpot('dynamic_method_name', 'A dynamic call on the owning type may invoke this method.', $relation->file, $relation->lineStart, $relation->lineEnd);
            }
        }

        $edits = [];
        if ($stale === [] && $blockers === []) {
            try {
                $range = (new MethodNodeRemover($map->root))->locate($method->file->path, $method->method->lineStart, $method->method->lineEnd, $method->method->name);
                $edits[] = new RenameEdit($method->file->path, $method->file->sha256, $range['start'], $range['end'], $method->method->lineStart, $method->method->lineEnd, $range['expected'], '', 'method_declaration_removal', $method->id, 'phpstan_resolved');
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }
        $blockers = array_values(array_unique($blockers));
        $status = $stale !== [] || $blockers !== [] ? MethodRemovalPlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? MethodRemovalPlan::STATUS_REVIEW_REQUIRED : MethodRemovalPlan::STATUS_SAFE);
        if ($status === MethodRemovalPlan::STATUS_BLOCKED) {
            $edits = [];
        }

        return new MethodRemovalPlan(
            $status,
            $method->id,
            new RenameProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            $edits,
            $blindSpots,
            $stale,
            $blockers,
            self::NOT_OBSERVABLE,
        );
    }
}
