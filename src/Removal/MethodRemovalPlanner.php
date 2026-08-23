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
    /** @var list<string> */
    private const IMPLICIT_MAGIC_METHODS = [
        '__call',
        '__callstatic',
        '__clone',
        '__construct',
        '__debuginfo',
        '__destruct',
        '__get',
        '__invoke',
        '__isset',
        '__serialize',
        '__set',
        '__set_state',
        '__sleep',
        '__tostring',
        '__unserialize',
        '__unset',
        '__wakeup',
    ];

    /** @var list<string> */
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
        if ($method->owner->kind === 'trait') {
            $blockers[] = 'Trait method removal is blocked until trait alias and insteadof adaptations are represented as removal evidence.';
        }
        if (in_array(strtolower($method->method->name), self::IMPLICIT_MAGIC_METHODS, true)) {
            $blockers[] = 'Language-invoked magic methods cannot be proven unused from ordinary call relations.';
        }
        if ($this->ownerHasMagicDispatch($method->owner->methods)) {
            $blockers[] = 'Classes with __call or __callStatic are blocked because magic dispatch can make ordinary call evidence incomplete.';
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
                && $this->receiverTypeContainsOwner($relation->receiverType, $method->owner->fqn)) {
                $blindSpots[] = new RenameBlindSpot(
                    'dynamic_method_name',
                    'A dynamic call on the owning type may invoke this method.',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                );
            }
        }

        $edits = [];
        if ($stale === [] && $blockers === []) {
            try {
                $range = (new MethodNodeRemover($map->root))->locate(
                    $method->file->path,
                    $method->method->lineStart,
                    $method->method->lineEnd,
                    $method->method->name,
                );
                if ($range['has_class_string_static_call']) {
                    $blockers[] = 'A class-string static call with this method name is not resolved by the current PHPStan call collector.';
                } else {
                    if ($range['has_attributes']) {
                        $blindSpots[] = new RenameBlindSpot(
                            'method_attributes',
                            'Method attributes may represent runtime or framework entry points that ordinary call relations do not prove unused.',
                            $method->file->path,
                            $method->method->lineStart,
                            $method->method->lineEnd,
                        );
                    }
                    $edits[] = new RenameEdit(
                        $method->file->path,
                        $method->file->sha256,
                        $range['start'],
                        $range['end'],
                        $method->method->lineStart,
                        $method->method->lineEnd,
                        $range['expected'],
                        '',
                        'method_declaration_removal',
                        $method->id,
                        'phpstan_resolved',
                    );
                }
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

    /** @param list<object> $methods */
    private function ownerHasMagicDispatch(array $methods): bool
    {
        foreach ($methods as $ownerMethod) {
            if (!property_exists($ownerMethod, 'name')) {
                continue;
            }
            $name = strtolower((string) $ownerMethod->name);
            if ($name === '__call' || $name === '__callstatic') {
                return true;
            }
        }

        return false;
    }

    private function receiverTypeContainsOwner(string $receiverType, string $ownerFqn): bool
    {
        $ownerPattern = preg_quote(ltrim($ownerFqn, '\\'), '/');

        return preg_match(
            '/(?<![A-Za-z0-9_\\\\])\\\\?' . $ownerPattern . '(?![A-Za-z0-9_\\\\])/i',
            $receiverType,
        ) === 1;
    }
}
