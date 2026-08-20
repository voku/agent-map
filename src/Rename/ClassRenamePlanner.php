<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

use InvalidArgumentException;
use ParseError;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\SymbolEntry;

/** Builds a read-only, fail-closed plan for one same-namespace PHP class rename. */
final readonly class ClassRenamePlanner
{
    /** @var list<string> */
    private const NOT_OBSERVABLE = [
        'Dynamically constructed class names, reflection and container/configuration strings outside exact observed literals are not automatically rewritten.',
        'Non-PHP artifacts such as YAML, XML, templates and generated metadata are outside the indexed source envelope.',
        'Composer/autoloader semantics are not inferred beyond a deterministic same-directory basename move.',
    ];

    public function plan(AgentMapIndex $map, string $target, string $replacement): ClassRenamePlan
    {
        $resolved = $this->resolveClass($map, $target);
        $file = $resolved['file'];
        $symbol = $resolved['symbol'];
        [$replacementFqn, $replacementShort] = $this->replacement($symbol->fqn, $replacement);

        $blockers = [];
        $blindSpots = [];
        $stale = $map->staleEntries();
        if ($stale !== []) {
            $blockers[] = sprintf('Map is stale for %d file(s); refresh it before planning a class rename.', count($stale));
        }
        if ($symbol->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot rename a class whose structural and semantic identity conflict: ' . $symbol->fqn;
        }
        foreach ($map->files as $candidateFile) {
            foreach ($candidateFile->symbols as $candidate) {
                if (!in_array($candidate->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                    continue;
                }
                if ($candidate->id() === $symbol->id()) {
                    continue;
                }
                if (strcasecmp(ltrim($candidate->fqn, '\\'), $replacementFqn) === 0) {
                    $blockers[] = sprintf('Replacement class identity collides with indexed %s %s.', $candidate->kind, $candidate->fqn);
                }
            }
        }

        if ($blockers !== []) {
            return $this->result($map, $symbol, $replacementFqn, [], [], $blindSpots, $blockers);
        }

        $locator = new SourceClassNameLocator($map->root);
        $scopeGuard = new ClassRenameScopeGuard($map->root);
        $edits = [];
        try {
            $declaration = $locator->declaration($file->path, $symbol->lineStart, $symbol->lineEnd, $symbol->name);
            $edits[] = new RenameEdit(
                path: $file->path,
                sourceSha256: $file->sha256,
                startFilePos: $declaration['start_file_pos'],
                endFilePos: $declaration['end_file_pos'],
                lineStart: $symbol->lineStart,
                lineEnd: $symbol->lineEnd,
                expected: $declaration['expected'],
                replacement: $replacementShort,
                role: 'class_declaration',
                symbolId: $symbol->id(),
                resolution: 'parser_resolved',
            );
        } catch (RuntimeException $exception) {
            $blockers[] = $exception->getMessage();
        }

        foreach ($map->files as $candidateFile) {
            try {
                $scopeGuard->assertReplacementAvailable(
                    $candidateFile->path,
                    ltrim($symbol->fqn, '\\'),
                    $symbol->name,
                    $replacementShort,
                );
                $references = $locator->references(
                    $candidateFile->path,
                    $candidateFile->sha256,
                    ltrim($symbol->fqn, '\\'),
                    $symbol->name,
                    $replacementShort,
                    $symbol->id(),
                );
                $edits = [...$edits, ...$references['edits']];
                $blindSpots = [...$blindSpots, ...$references['blind_spots']];
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
        }

        $moves = [];
        [$move, $moveBlindSpot, $moveBlocker] = $this->fileMove($map, $file, $symbol, $replacementShort);
        if ($move instanceof RenameMove) {
            $moves[] = $move;
        }
        if ($moveBlindSpot instanceof RenameBlindSpot) {
            $blindSpots[] = $moveBlindSpot;
        }
        if (is_string($moveBlocker)) {
            $blockers[] = $moveBlocker;
        }

        $edits = $this->uniqueSortedEdits($edits);
        $blockers = [...$blockers, ...$this->overlapBlockers($edits)];

        return $this->result($map, $symbol, $replacementFqn, $edits, $moves, $blindSpots, $blockers);
    }

    /** @return array{file: FileEntry, symbol: SymbolEntry} */
    private function resolveClass(AgentMapIndex $map, string $target): array
    {
        $target = ltrim(trim($target), '\\');
        if ($target === '') {
            throw new InvalidArgumentException('Class rename target cannot be empty.');
        }

        $qualified = str_contains($target, '\\');
        /** @var list<array{file: FileEntry, symbol: SymbolEntry}> $matches */
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
                    $matches[] = ['file' => $file, 'symbol' => $symbol];
                }
            }
        }

        if ($matches === []) {
            throw new RuntimeException('Class target not found: ' . $target);
        }
        if (count($matches) > 1) {
            $candidates = array_map(static fn (array $match): string => $match['symbol']->fqn, $matches);
            sort($candidates, SORT_STRING);
            throw new RuntimeException('Class target is ambiguous: ' . $target . "\nUse a fully-qualified class name:\n- " . implode("\n- ", $candidates));
        }

        return $matches[0];
    }

    /** @return array{0: string, 1: string} */
    private function replacement(string $originalFqn, string $replacement): array
    {
        $replacement = ltrim(trim($replacement), '\\');
        if ($replacement === '') {
            throw new InvalidArgumentException('Replacement class name cannot be empty.');
        }

        $originalNamespace = $this->namespace($originalFqn);
        if (str_contains($replacement, '\\')) {
            $replacementNamespace = $this->namespace($replacement);
            if (strcasecmp($replacementNamespace, $originalNamespace) !== 0) {
                throw new InvalidArgumentException('Class rename currently supports the same namespace only; namespace moves require a separate evidence contract.');
            }
            $replacementShort = $this->shortName($replacement);
            $replacementFqn = $replacement;
        } else {
            $replacementShort = $replacement;
            $replacementFqn = $originalNamespace === '' ? $replacementShort : $originalNamespace . '\\' . $replacementShort;
        }

        $this->assertClassName($replacementShort);
        if (strcasecmp(ltrim($originalFqn, '\\'), $replacementFqn) === 0) {
            throw new InvalidArgumentException('Replacement resolves to the same PHP class identity; case-only class renames are not semantic renames.');
        }

        return [$replacementFqn, $replacementShort];
    }

    /** @return array{0: ?RenameMove, 1: ?RenameBlindSpot, 2: ?string} */
    private function fileMove(AgentMapIndex $map, FileEntry $file, SymbolEntry $symbol, string $replacementShort): array
    {
        $expectedBasename = $symbol->name . '.php';
        $basename = basename($file->path);
        if (strcasecmp($basename, $expectedBasename) !== 0) {
            return [
                null,
                new RenameBlindSpot(
                    kind: 'autoload_path',
                    message: 'Class file basename does not match the current class name; autoload/file-path consequences need host review.',
                    path: $file->path,
                    lineStart: $symbol->lineStart,
                    lineEnd: $symbol->lineEnd,
                ),
                null,
            ];
        }

        $directory = dirname($file->path);
        $toPath = ($directory === '.' ? '' : $directory . '/') . $replacementShort . '.php';
        $absoluteTarget = rtrim($map->root, '/\\') . '/' . $toPath;
        if (file_exists($absoluteTarget) || is_link($absoluteTarget)) {
            return [null, null, 'Replacement class path already exists: ' . $toPath];
        }

        $blindSpot = count($file->symbols) > 1
            ? new RenameBlindSpot(
                kind: 'multi_symbol_file_move',
                message: 'Renamed class shares its file with other declarations; moving the file may affect their loading or autoload contract.',
                path: $file->path,
                lineStart: $symbol->lineStart,
                lineEnd: $symbol->lineEnd,
            )
            : null;

        return [
            new RenameMove(
                fromPath: $file->path,
                toPath: $toPath,
                sourceSha256: $file->sha256,
                reason: 'Source basename matches the renamed class; project the conventional same-directory file move.',
            ),
            $blindSpot,
            null,
        ];
    }

    /** @param list<RenameEdit> $edits @return list<RenameEdit> */
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

    /** @param list<RenameEdit> $edits @return list<string> */
    private function overlapBlockers(array $edits): array
    {
        $blockers = [];
        $previous = null;
        foreach ($edits as $edit) {
            if ($previous instanceof RenameEdit && $previous->path === $edit->path && $edit->startFilePos <= $previous->endFilePos) {
                $blockers[] = sprintf(
                    'Class rename edits overlap in %s at byte ranges %d-%d and %d-%d.',
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
     * @param list<RenameEdit> $edits
     * @param list<RenameMove> $moves
     * @param list<RenameBlindSpot> $blindSpots
     * @param list<string> $blockers
     */
    private function result(
        AgentMapIndex $map,
        SymbolEntry $symbol,
        string $replacementFqn,
        array $edits,
        array $moves,
        array $blindSpots,
        array $blockers,
    ): ClassRenamePlan {
        $blockers = array_values(array_unique($blockers));
        $blindSpots = $this->uniqueBlindSpots($blindSpots);
        $status = $blockers !== []
            ? ClassRenamePlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? ClassRenamePlan::STATUS_REVIEW_REQUIRED : ClassRenamePlan::STATUS_SAFE);

        return new ClassRenamePlan(
            status: $status,
            targetId: $symbol->id(),
            originalFqn: ltrim($symbol->fqn, '\\'),
            replacementFqn: $replacementFqn,
            backend: $map->backend,
            mapDigest: $map->mapDigest(),
            edits: $status === ClassRenamePlan::STATUS_BLOCKED ? [] : $edits,
            moves: $status === ClassRenamePlan::STATUS_BLOCKED ? [] : $moves,
            blindSpots: $blindSpots,
            blockers: $blockers,
            notObservable: self::NOT_OBSERVABLE,
        );
    }

    /** @param list<RenameBlindSpot> $blindSpots @return list<RenameBlindSpot> */
    private function uniqueBlindSpots(array $blindSpots): array
    {
        $unique = [];
        foreach ($blindSpots as $blindSpot) {
            $unique[implode(':', [$blindSpot->kind, $blindSpot->path ?? '', (string) ($blindSpot->lineStart ?? 0), (string) ($blindSpot->lineEnd ?? 0)])] = $blindSpot;
        }

        return array_values($unique);
    }

    private function namespace(string $fqn): string
    {
        $separator = strrpos($fqn, '\\');
        return $separator === false ? '' : substr($fqn, 0, $separator);
    }

    private function shortName(string $fqn): string
    {
        $separator = strrpos($fqn, '\\');
        return $separator === false ? $fqn : substr($fqn, $separator + 1);
    }

    private function assertClassName(string $name): void
    {
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid PHP class name: ' . $name);
        }

        try {
            token_get_all('<?php class ' . $name . ' {}', TOKEN_PARSE);
        } catch (ParseError) {
            throw new InvalidArgumentException('Invalid or reserved PHP class name: ' . $name);
        }
    }
}
