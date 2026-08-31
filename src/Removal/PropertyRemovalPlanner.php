<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;

/**
 * Builds an exact, fail-closed unused-private-property deletion plan.
 *
 * Safety behavior is informed by Rector's RemoveUnusedPrivatePropertyRector at
 * rectorphp/rector-src@cbeefaa869f3c5a8721af602b887c242b18741fd. The planner remains
 * stricter: contract 1.0 never removes write-only assignments because current Map evidence does
 * not expose a sufficiently strong read-vs-write property-access contract.
 */
final readonly class PropertyRemovalPlanner
{
    /** @var list<string> */
    private const MAGIC_PROPERTY_METHODS = ['__get', '__set', '__isset', '__unset'];

    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'Reflection, serialization metadata, property_exists/get_object_vars usage and framework configuration strings are not represented as semantic property-access relations.',
        'PHPDoc and arbitrary strings containing property names are outside the exact property-removal contract.',
        'PHP source outside the indexed map scope and non-PHP configuration are outside the observable envelope.',
        'Write-only property elimination and assignment rewriting are intentionally unsupported until Map publishes explicit read/write access evidence.',
    ];

    public function plan(AgentMapIndex $map, string $target): PropertyRemovalPlan
    {
        [$ownerTarget, $propertyName] = $this->parseTarget($target);
        [$file, $owner] = $this->resolveOwner($map, $ownerTarget);
        $ownerFqn = ltrim($owner->fqn, '\\');
        $targetId = 'property:' . $ownerFqn . '::$' . $propertyName;

        /** @var list<string> $blockers */
        $blockers = [];
        /** @var list<PlanBlindSpot> $blindSpots */
        $blindSpots = [];
        $staleEntries = $map->staleEntries();
        usort($staleEntries, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        /** @var list<PlanStaleEvidence> $stale */
        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $staleEntries,
        );

        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Property removal requires a PHPStan-backed map so declaration/access identity is semantic rather than textual.';
        }
        if ($owner->kind === 'trait') {
            $blockers[] = 'Trait property removal is blocked until trait alias/composition behavior is represented as removal evidence.';
        }
        if ($owner->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot remove a property on a conflicted class identity: ' . $ownerFqn;
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

        foreach ($map->relations as $relation) {
            if ($relation->kind !== 'property_access') {
                continue;
            }
            if (in_array($targetId, $relation->targetIds, true)) {
                $blockers[] = sprintf(
                    'Property is accessed at %s:%d-%d (%s); contract 1.0 only removes properties with zero observed semantic accesses.',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                    $relation->resolution,
                );
                continue;
            }
            if ($relation->resolution === 'dynamic'
                && $relation->receiverType !== null
                && $this->receiverTypeContainsOwner($relation->receiverType, $ownerFqn)
            ) {
                $blindSpots[] = new PlanBlindSpot(
                    'dynamic_property_name',
                    'A dynamic property access on the owning type may resolve to this property at runtime.',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                );
            }
        }

        if ($this->ownerHasMagicPropertyDispatch($owner->methods)) {
            $blindSpots[] = new PlanBlindSpot(
                'magic_property_dispatch',
                'The owner defines magic property dispatch; runtime property-name strings may overlap this private property.',
                $file->path,
                $owner->lineStart,
                $owner->lineEnd,
            );
        }

        /** @var list<PlanEdit> $edits */
        $edits = [];
        if ($stale === [] && $blockers === []) {
            try {
                $range = (new PropertyNodeRemover($map->root))->locate($file->path, $ownerFqn, $propertyName);
                if (!$range['private']) {
                    $blockers[] = 'Only private properties can be planned for removal; public/protected contracts are intentionally excluded.';
                }
                if ($range['static']) {
                    $blockers[] = 'Static property removal is blocked because dynamic/static lookup surfaces are not fully represented by contract 1.0.';
                }
                if (!$range['single_property']) {
                    $blockers[] = 'Multi-property declarations are blocked because deleting one property must not remove neighboring declarations.';
                }
                if ($range['hooks']) {
                    $blockers[] = 'Property hooks are blocked until hook dispatch and asymmetric visibility are represented as removal evidence.';
                }
                if ($range['owner_uses_trait']) {
                    $blockers[] = 'Classes using traits are blocked because trait code may access the property without owner-local source evidence.';
                }
                if ($range['owner_has_load_metadata']) {
                    $blockers[] = 'Classes defining loadMetadata() are blocked because Doctrine static metadata may refer to private properties indirectly.';
                }
                if ($range['has_attributes']) {
                    $blindSpots[] = new PlanBlindSpot(
                        'property_attributes',
                        'Property attributes may represent runtime or framework metadata that semantic property-access relations do not prove unused.',
                        $file->path,
                        $range['line_start'],
                        $range['line_end'],
                    );
                }
                if ($range['has_docblock']) {
                    $blindSpots[] = new PlanBlindSpot(
                        'property_phpdoc',
                        'Property PHPDoc may contain metadata or tooling contracts outside semantic property-access evidence.',
                        $file->path,
                        $range['line_start'],
                        $range['line_end'],
                    );
                }

                if ($blockers === []) {
                    $edits[] = new PlanEdit(
                        $file->path,
                        $file->sha256,
                        $range['start'],
                        $range['end'],
                        $range['line_start'],
                        $range['line_end'],
                        $range['expected'],
                        '',
                        'property_declaration_removal',
                        $targetId,
                        'phpstan_resolved',
                    );
                }
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $blockers = array_values(array_unique($blockers));
        $blindSpots = $this->uniqueBlindSpots($blindSpots);
        $status = $stale !== [] || $blockers !== []
            ? PropertyRemovalPlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? PropertyRemovalPlan::STATUS_REVIEW_REQUIRED : PropertyRemovalPlan::STATUS_SAFE);
        if ($status === PropertyRemovalPlan::STATUS_BLOCKED) {
            $edits = [];
        }

        return new PropertyRemovalPlan(
            $status,
            $targetId,
            new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            $edits,
            $blindSpots,
            $stale,
            $blockers,
            self::NOT_OBSERVABLE,
        );
    }

    /** @return array{0: string, 1: string} */
    private function parseTarget(string $target): array
    {
        $target = ltrim(trim($target), '\\');
        $separator = strrpos($target, '::$');
        if ($separator === false) {
            throw new InvalidArgumentException('Property-removal target must use Class::$property syntax: ' . $target);
        }
        $owner = substr($target, 0, $separator);
        $property = substr($target, $separator + 3);
        if ($owner === '' || $property === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $property) !== 1) {
            throw new InvalidArgumentException('Property-removal target must use Class::$property syntax with a valid property name: ' . $target);
        }

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
                if ($symbol->kind !== 'class' && $symbol->kind !== 'trait') {
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
            throw new RuntimeException('Property-removal owner class not found: ' . $target);
        }
        if (count($matches) > 1) {
            $candidates = array_map(static fn (array $match): string => $match[1]->fqn, $matches);
            sort($candidates, SORT_STRING);
            throw new RuntimeException('Property-removal owner is ambiguous: ' . $target . "\nUse a fully-qualified class name:\n- " . implode("\n- ", $candidates));
        }

        return $matches[0];
    }

    /** @param list<MethodEntry> $methods */
    private function ownerHasMagicPropertyDispatch(array $methods): bool
    {
        foreach ($methods as $method) {
            if (in_array(strtolower($method->name), self::MAGIC_PROPERTY_METHODS, true)) {
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

    /**
     * @param list<PlanBlindSpot> $blindSpots
     * @return list<PlanBlindSpot>
     */
    private function uniqueBlindSpots(array $blindSpots): array
    {
        /** @var array<string, PlanBlindSpot> $unique */
        $unique = [];
        foreach ($blindSpots as $blindSpot) {
            $unique[implode(':', [
                $blindSpot->kind,
                $blindSpot->path ?? '',
                (string) ($blindSpot->lineStart ?? 0),
                (string) ($blindSpot->lineEnd ?? 0),
            ])] = $blindSpot;
        }

        return array_values($unique);
    }
}
