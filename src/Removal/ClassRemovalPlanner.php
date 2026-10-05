<?php

declare(strict_types=1);

namespace voku\AgentMap\Removal;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanFileDeletion;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Rename\SourceClassNameLocator;

/** Builds fail-closed evidence for removing one unused class whose complete file is owned by that class. */
final readonly class ClassRemovalPlanner
{
    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'Reflection, container identifiers, string class names and framework configuration outside exact indexed PHP evidence are not proven unused.',
        'PHP source outside the indexed map scope and non-PHP configuration are outside the observable envelope.',
    ];

    public function plan(AgentMapIndex $map, string $target): ClassRemovalPlan
    {
        ['file' => $file, 'symbol' => $symbol] = $this->resolveClass($map, $target);
        $blockers = [];
        $blindSpots = [];
        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Class removal requires a PHPStan-backed map so type usages are semantic rather than textual.';
        }
        if ($symbol->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot remove a class whose structural and semantic identity conflict: ' . $symbol->fqn;
        }
        if (count($file->symbols) !== 1 || $file->symbols[0]->id() !== $symbol->id()) {
            $blockers[] = 'Class removal contract 1.0 requires the target class to be the only indexed symbol in its source file.';
        }

        foreach ($map->relations as $relation) {
            if (!in_array($symbol->id(), $relation->targetIds, true)) {
                continue;
            }
            if ($relation->kind === 'defines' && $relation->sourceId === 'file:' . $file->path) {
                continue;
            }
            $blockers[] = sprintf(
                'Class has incoming %s evidence at %s:%d-%d (%s).',
                $relation->kind,
                $relation->file,
                $relation->lineStart,
                $relation->lineEnd,
                $relation->resolution,
            );
        }

        $locator = new SourceClassNameLocator($map->root);
        if ($stale === [] && $blockers === []) {
            try {
                (new ClassFileRemovalInspector($locator))->assertOwnedFile(
                    $file->path,
                    $file->namespace,
                    $symbol->name,
                    $symbol->lineStart,
                    $symbol->lineEnd,
                );
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        if ($stale === [] && $blockers === []) {
            foreach ($map->files as $candidateFile) {
                try {
                    $references = $locator->references(
                        $candidateFile->path,
                        $candidateFile->sha256,
                        ltrim($symbol->fqn, '\\'),
                        $symbol->name,
                        $symbol->name,
                        $symbol->id(),
                    );
                    foreach ($references['edits'] as $edit) {
                        $blockers[] = sprintf(
                            'Exact class reference remains in indexed source %s:%d-%d (%s).',
                            $edit->path,
                            $edit->lineStart,
                            $edit->lineEnd,
                            $edit->role,
                        );
                    }
                    foreach ($references['blind_spots'] as $spot) {
                        if (!in_array($spot->kind, ['class_string_literal', 'phpdoc_type_reference'], true)) {
                            continue;
                        }
                        $blindSpots[] = new PlanBlindSpot(
                            kind: $spot->kind,
                            message: $spot->kind === 'class_string_literal'
                                ? 'A string literal may name the removed class and can represent a runtime or framework entry point.'
                                : 'PHPDoc references the removed class and may describe a runtime/framework contract outside semantic type relations.',
                            path: $spot->path,
                            lineStart: $spot->lineStart,
                            lineEnd: $spot->lineEnd,
                        );
                    }
                } catch (RuntimeException $exception) {
                    $blockers[] = $exception->getMessage();
                }
            }
        }

        if ($symbol->attributes !== []) {
            $blindSpots[] = new PlanBlindSpot(
                kind: 'class_attributes',
                message: 'Class attributes may register runtime or framework behavior that ordinary type relations do not prove unused.',
                path: $file->path,
                lineStart: $symbol->lineStart,
                lineEnd: $symbol->lineEnd,
            );
        }

        $blockers = array_values(array_unique($blockers));
        $blindSpots = $this->uniqueBlindSpots($blindSpots);
        $status = $stale !== [] || $blockers !== []
            ? ClassRemovalPlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? ClassRemovalPlan::STATUS_REVIEW_REQUIRED : ClassRemovalPlan::STATUS_SAFE);

        return new ClassRemovalPlan(
            status: $status,
            targetId: $symbol->id(),
            provenance: new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            edits: [],
            deletions: $status === ClassRemovalPlan::STATUS_BLOCKED ? [] : [
                new PlanFileDeletion(
                    path: $file->path,
                    sourceSha256: $file->sha256,
                    reason: 'The target is the only indexed declaration and the file contains no independently owned PHP statements.',
                ),
            ],
            blindSpots: $blindSpots,
            staleEvidence: $stale,
            blockers: $blockers,
            notObservable: self::NOT_OBSERVABLE,
        );
    }

    /** @return array{file: FileEntry, symbol: SymbolEntry} */
    private function resolveClass(AgentMapIndex $map, string $target): array
    {
        $target = ltrim(trim($target), '\\');
        if ($target === '') {
            throw new InvalidArgumentException('Class removal target cannot be empty.');
        }

        $qualified = str_contains($target, '\\');
        $matches = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->kind !== 'class') {
                    continue;
                }
                if ($qualified
                    ? strcasecmp(ltrim($symbol->fqn, '\\'), $target) === 0
                    : strcasecmp($symbol->name, $target) === 0
                ) {
                    $matches[] = ['file' => $file, 'symbol' => $symbol];
                }
            }
        }

        if ($matches === []) {
            throw new RuntimeException('Class removal target not found: ' . $target);
        }
        if (count($matches) > 1) {
            $candidates = array_map(static fn (array $match): string => $match['symbol']->fqn, $matches);
            sort($candidates, SORT_STRING);
            throw new RuntimeException('Class removal target is ambiguous: ' . $target . "\nUse a fully-qualified class name:\n- " . implode("\n- ", $candidates));
        }

        return $matches[0];
    }

    /** @param list<PlanBlindSpot> $blindSpots @return list<PlanBlindSpot> */
    private function uniqueBlindSpots(array $blindSpots): array
    {
        $unique = [];
        foreach ($blindSpots as $spot) {
            $unique[implode(':', [$spot->kind, $spot->path ?? '', (string) ($spot->lineStart ?? 0), (string) ($spot->lineEnd ?? 0)])] = $spot;
        }

        return array_values($unique);
    }
}
