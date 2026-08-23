<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Rename\ClassConstantNameLocator;
use voku\AgentMap\Rename\RenameBlindSpot;
use voku\AgentMap\Rename\RenameEdit;
use voku\AgentMap\Rename\RenameProvenance;
use voku\AgentMap\Rename\RenameStaleEvidence;

/**
 * Builds an exact, fail-closed unused-private-class-constant deletion plan.
 *
 * The single-declaration and private-only rules are adapted from Rector's
 * RemoveUnusedPrivateClassConstantRector. Unlike Rector, this component only publishes evidence.
 */
final readonly class ClassConstantRemovalPlanner
{
    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'Reflection, constant(), defined(), serialization metadata and arbitrary strings are not represented as static class-constant fetches.',
        'Inherited and late-static constant lookup cannot be assigned to one declaring class by the parser-only constant evidence.',
        'PHP source outside the indexed map scope and non-PHP configuration are outside the observable envelope.',
    ];

    public function plan(AgentMapIndex $map, string $target): ClassConstantRemovalPlan
    {
        [$ownerTarget, $constantName] = $this->parseTarget($target);
        [$file, $owner] = $this->resolveOwner($map, $ownerTarget);
        $ownerFqn = ltrim($owner->fqn, '\\');
        $targetId = 'class_constant:' . $ownerFqn . '::' . $constantName;
        $blockers = [];
        $blindSpots = [];
        $stale = array_map(
            static fn (array $entry): RenameStaleEvidence => new RenameStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Class-constant removal requires a PHPStan-backed map to establish a reconciled, bounded analysis scope.';
        }
        if ($owner->kind !== 'class') {
            $blockers[] = 'Only constants declared on classes can be removed; interface, trait and enum contracts are excluded.';
        }
        if ($owner->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot remove a class constant on a conflicted class identity: ' . $ownerFqn;
        }

        $locator = new ClassConstantNameLocator($map->root);
        $declarations = 0;
        if ($stale === [] && $blockers === []) {
            foreach ($map->files as $candidate) {
                try {
                    $located = $locator->locate($candidate->path, $ownerFqn, $constantName, $constantName);
                    $blindSpots = [...$blindSpots, ...$located['blind_spots']];
                    foreach ($located['edits'] as $position) {
                        if ($position['role'] === 'declaration') {
                            ++$declarations;
                            continue;
                        }
                        $blockers[] = sprintf('Class constant is fetched at %s:%d-%d.', $candidate->path, $position['line_start'], $position['line_end']);
                    }
                } catch (RuntimeException $exception) {
                    $blockers[] = $exception->getMessage();
                }
            }
            if ($declarations !== 1) {
                $blockers[] = sprintf('Expected exactly one declaration of %s::%s in %s; found %d.', $ownerFqn, $constantName, $file->path, $declarations);
            }
        }

        $edits = [];
        if ($stale === [] && $blockers === []) {
            try {
                $range = (new ClassConstantNodeRemover($map->root))->locate($file->path, $ownerFqn, $constantName);
                if (!$range['private']) {
                    $blockers[] = 'Only private class constants can be planned for removal; public/protected contracts are excluded.';
                }
                if (!$range['single_constant']) {
                    $blockers[] = 'Multi-constant declarations are blocked because deleting one constant must not remove neighboring declarations.';
                }
                if ($range['has_attributes']) {
                    $blindSpots[] = new RenameBlindSpot('class_constant_attributes', 'Class-constant attributes may represent runtime or framework metadata.', $file->path, $range['line_start'], $range['line_end']);
                }
                if ($range['has_docblock']) {
                    $blindSpots[] = new RenameBlindSpot('class_constant_phpdoc', 'Class-constant PHPDoc may contain metadata or tooling contracts.', $file->path, $range['line_start'], $range['line_end']);
                }
                if ($blockers === []) {
                    $edits[] = new RenameEdit($file->path, $file->sha256, $range['start'], $range['end'], $range['line_start'], $range['line_end'], $range['expected'], '', 'class_constant_declaration_removal', $targetId, 'parser_resolved');
                }
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $blockers = array_values(array_unique($blockers));
        $blindSpots = $this->uniqueBlindSpots($blindSpots);
        $status = $stale !== [] || $blockers !== [] ? ClassConstantRemovalPlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? ClassConstantRemovalPlan::STATUS_REVIEW_REQUIRED : ClassConstantRemovalPlan::STATUS_SAFE);

        return new ClassConstantRemovalPlan($status, $targetId, new RenameProvenance($map->mapDigest(), $map->backend, $map->fingerprint), $status === ClassConstantRemovalPlan::STATUS_BLOCKED ? [] : $edits, $blindSpots, $stale, $blockers, self::NOT_OBSERVABLE);
    }

    /** @return array{0: string, 1: string} */
    private function parseTarget(string $target): array
    {
        $target = ltrim(trim($target), '\\');
        $separator = strrpos($target, '::');
        if ($separator === false) {
            throw new InvalidArgumentException('Class-constant removal target must use Class::CONSTANT syntax: ' . $target);
        }
        $owner = substr($target, 0, $separator);
        $constant = substr($target, $separator + 2);
        if ($owner === '' || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $constant) !== 1) {
            throw new InvalidArgumentException('Class-constant removal target must use Class::CONSTANT syntax with a valid constant name: ' . $target);
        }

        return [$owner, $constant];
    }

    /** @return array{0: FileEntry, 1: SymbolEntry} */
    private function resolveOwner(AgentMapIndex $map, string $target): array
    {
        $qualified = str_contains($target, '\\');
        $matches = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if (!in_array($symbol->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                    continue;
                }
                if (($qualified && strcasecmp(ltrim($symbol->fqn, '\\'), $target) === 0) || (!$qualified && strcasecmp($symbol->name, $target) === 0)) {
                    $matches[] = [$file, $symbol];
                }
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(count($matches) === 0 ? 'Class-constant removal owner not found: ' . $target : 'Class-constant removal owner is ambiguous: ' . $target);
        }

        return $matches[0];
    }

    /**
     * @param list<RenameBlindSpot> $blindSpots
     * @return list<RenameBlindSpot>
     */
    private function uniqueBlindSpots(array $blindSpots): array
    {
        $unique = [];
        foreach ($blindSpots as $spot) {
            $unique[$spot->kind . ':' . $spot->path . ':' . $spot->lineStart . ':' . $spot->lineEnd] = $spot;
        }

        return array_values($unique);
    }
}
