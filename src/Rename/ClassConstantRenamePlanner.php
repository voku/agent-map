<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;

/** Builds a fail-closed plan inspired by Rector's type-aware RenameClassConstFetch rule. */
final readonly class ClassConstantRenamePlanner
{
    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'Constant names assembled dynamically, reflection and strings are not automatically rewritten.',
        'Inherited constant fetches through child-class names require semantic type-family evidence.',
        'PHP source outside the indexed map and non-PHP configuration are outside the observable envelope.',
    ];

    /** Builds one immutable plan and publishes no edits when stale or semantic blockers exist. */
    public function plan(AgentMapIndex $map, string $target, string $replacement): ClassConstantRenamePlan
    {
        [$owner, $original] = $this->target($target);
        $this->assertConstantName($replacement);
        if ($original === $replacement) {
            throw new InvalidArgumentException('Replacement class constant is identical to the current name: ' . $original);
        }

        [$file, $symbol] = $this->resolveOwner($map, $owner);
        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );
        $blockers = [];
        if ($symbol->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot rename a constant on a class whose structural and semantic identity conflict: ' . $symbol->fqn;
        }
        if ($stale !== [] || $blockers !== []) {
            return $this->result($map, $symbol, $original, $replacement, [], [], $stale, $blockers);
        }

        $locator = new ClassConstantNameLocator($map->root);
        $edits = [];
        $blindSpots = [];
        $declarations = 0;
        foreach ($map->files as $candidate) {
            try {
                $located = $locator->locate($candidate->path, ltrim($symbol->fqn, '\\'), $original, $replacement);
                if ($located['collision']) {
                    $blockers[] = sprintf('Replacement class constant %s::%s already exists.', $symbol->fqn, $replacement);
                }
                $blindSpots = [...$blindSpots, ...$located['blind_spots']];
                foreach ($located['edits'] as $position) {
                    if ($position['role'] === 'declaration') {
                        ++$declarations;
                    }
                    $edits[] = new PlanEdit(
                        $candidate->path,
                        $candidate->sha256,
                        $position['start_file_pos'],
                        $position['end_file_pos'],
                        $position['line_start'],
                        $position['line_end'],
                        $original,
                        $replacement,
                        'class_constant_' . $position['role'],
                        'class_constant:' . ltrim($symbol->fqn, '\\') . '::' . $original,
                        'parser_resolved',
                    );
                }
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }
        if ($declarations !== 1) {
            $blockers[] = sprintf('Expected exactly one declaration of %s::%s in %s; found %d.', $symbol->fqn, $original, $file->path, $declarations);
        }

        $unique = [];
        foreach ($edits as $edit) {
            $unique[$edit->path . ':' . $edit->startFilePos . ':' . $edit->endFilePos] = $edit;
        }
        $edits = array_values($unique);
        usort($edits, static fn (PlanEdit $a, PlanEdit $b): int => $a->path <=> $b->path ?: $a->startFilePos <=> $b->startFilePos);

        return $this->result($map, $symbol, $original, $replacement, $edits, $blindSpots, $stale, $blockers);
    }

    /** @return array{0: string, 1: string} Validated owner and constant name. */
    private function target(string $target): array
    {
        $target = ltrim(trim($target), '\\');
        $separator = strrpos($target, '::');
        if ($separator === false) {
            throw new InvalidArgumentException('Class constant target must use ClassName::CONSTANT syntax.');
        }
        $owner = substr($target, 0, $separator);
        $name = substr($target, $separator + 2);
        if ($owner === '') {
            throw new InvalidArgumentException('Class constant owner cannot be empty.');
        }
        $this->assertConstantName($name);

        return [$owner, $name];
    }

    /** @return array{0: FileEntry, 1: SymbolEntry} Exactly one mapped class-like owner. */
    private function resolveOwner(AgentMapIndex $map, string $owner): array
    {
        $matches = [];
        $qualified = str_contains($owner, '\\');
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if (!in_array($symbol->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                    continue;
                }
                if (($qualified && strcasecmp(ltrim($symbol->fqn, '\\'), $owner) === 0) || (!$qualified && strcasecmp($symbol->name, $owner) === 0)) {
                    $matches[] = [$file, $symbol];
                }
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(count($matches) === 0 ? 'Class constant owner not found: ' . $owner : 'Class constant owner is ambiguous: ' . $owner);
        }

        return $matches[0];
    }

    /**
     * Derives the contract status and suppresses all edits for a blocked plan.
     *
     * @param list<PlanEdit> $edits
     * @param list<PlanBlindSpot> $blindSpots
     * @param list<PlanStaleEvidence> $stale
     * @param list<string> $blockers
     */
    private function result(AgentMapIndex $map, SymbolEntry $owner, string $original, string $replacement, array $edits, array $blindSpots, array $stale, array $blockers): ClassConstantRenamePlan
    {
        $blockers = array_values(array_unique($blockers));
        $status = $stale !== [] || $blockers !== []
            ? ClassConstantRenamePlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? ClassConstantRenamePlan::STATUS_REVIEW_REQUIRED : ClassConstantRenamePlan::STATUS_SAFE);

        return new ClassConstantRenamePlan(
            $status,
            'class_constant:' . ltrim($owner->fqn, '\\') . '::' . $original,
            $owner->fqn,
            $original,
            $replacement,
            new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            $status === ClassConstantRenamePlan::STATUS_BLOCKED ? [] : $edits,
            $blindSpots,
            $stale,
            $blockers,
            self::NOT_OBSERVABLE,
        );
    }

    /** Rejects names that cannot be PHP class-constant identifiers. */
    private function assertConstantName(string $name): void
    {
        if ($name === '' || preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid PHP class constant name: ' . $name);
        }
    }
}
