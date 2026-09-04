<?php

declare(strict_types=1);

namespace voku\AgentMap\Move;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\ResolvedMethod;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Removal\MethodNodeRemover;

/**
 * Plans the relocation of one explicitly chosen method into one explicitly
 * chosen destination class.
 *
 * The first slice is deliberately narrow: static methods whose body observes no
 * owner state. That is the shape whose relocation can be proven mechanically
 * without inventing receiver semantics or dependency injection. Everything the
 * planner cannot prove stays visible as a blocker or a blind spot rather than
 * being smoothed into an apparently safe edit set.
 */
final readonly class MethodMovePlanner
{
    private const NOT_OBSERVABLE = [
        'Reflection, string callbacks, framework configuration and non-PHP configuration are not represented as PHPStan call relations.',
        'PHP source outside the indexed map scope is outside the observable envelope.',
        'Runtime metadata that reads the declaring class of the moved method is not represented as a call relation.',
    ];

    public function plan(AgentMapIndex $map, string $target, string $destination): MethodMovePlan
    {
        $method = $map->resolveMethod($target);
        $blockers = [];
        $blindSpots = [];
        $ownerDependencies = [];
        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        if (!str_ends_with($map->backend, '+phpstan')) {
            $blockers[] = 'Method move requires a PHPStan-backed map so call sites are semantic rather than textual.';
        }
        if (!$method->method->static) {
            $blockers[] = 'The first method-move slice supports static methods only; instance relocation would have to invent receiver semantics.';
        }
        if ($method->method->abstract) {
            $blockers[] = 'An abstract method declares an obligation on its owner and cannot be relocated as source text.';
        }
        if ($method->owner->kind === 'trait') {
            $blockers[] = 'Trait method relocation is blocked until alias and insteadof adaptations are represented as move evidence.';
        }
        if ($method->method->reconciliationStatus === 'conflict' || $method->owner->reconciliationStatus === 'conflict') {
            $blockers[] = 'Cannot move a method whose structural and semantic declarations conflict.';
        }
        if ($method->method->attributes !== []) {
            $blindSpots[] = new PlanBlindSpot(
                'method_attributes',
                'Method attributes may bind the method to its declaring class through runtime or framework metadata.',
                $method->file->path,
                $method->method->lineStart,
                $method->method->lineEnd,
            );
        }
        if ($method->method->visibility !== 'private') {
            $blindSpots[] = new PlanBlindSpot(
                'out_of_scope_api_exposure',
                sprintf(
                    'A %s method may be called from outside the indexed map scope, which no closed-world evidence here can disprove.',
                    $method->method->visibility,
                ),
                $method->file->path,
                $method->method->lineStart,
                $method->method->lineEnd,
            );
        }

        $resolvedDestination = $this->resolveDestination($map, $destination, $blockers);
        $destinationSymbol = $resolvedDestination[0] ?? null;
        $destinationFile = $resolvedDestination[1] ?? null;
        if ($destinationSymbol !== null) {
            if ($destinationSymbol->fqn === $method->owner->fqn) {
                $blockers[] = 'Destination class is the current owner; there is nothing to relocate.';
            }
            foreach ($destinationSymbol->methods as $existing) {
                if (strcasecmp($existing->name, $method->method->name) === 0) {
                    $blockers[] = sprintf(
                        'Destination %s already declares %s; a move cannot silently replace an existing declaration.',
                        $destinationSymbol->fqn,
                        $existing->name,
                    );
                }
            }
        }

        $locator = new MethodMoveNodeLocator($map->root);
        if ($blockers === []) {
            try {
                $ownerDependencies = $locator->ownerDependencies(
                    $method->file->path,
                    $method->method->lineStart,
                    $method->method->lineEnd,
                    $method->method->name,
                );
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
            }
            if ($ownerDependencies !== []) {
                $blockers[] = sprintf(
                    'The method body observes its declaring owner (%s); relocating the text would silently change what those resolve to.',
                    implode(', ', $ownerDependencies),
                );
            }
        }

        $callSites = $this->callSites($map, $method->id, $method->owner->fqn, $blindSpots);

        // Relocating a non-public method changes who may reach it. Every call
        // site outside the destination would still be re-pointed at the moved
        // owner and then fail at runtime with "Call to private method ... from
        // scope ...". The contract forbids an implicit visibility rewrite, so
        // the only honest answer is to refuse.
        if ($method->method->visibility !== 'public' && $callSites !== []) {
            $blockers[] = sprintf(
                'The method is %s and %d call site(s) would be re-pointed at %s, where that access is no longer valid; a move may not silently widen visibility.',
                $method->method->visibility,
                count($callSites),
                $destinationSymbol === null ? ltrim(trim($destination), '\\') : $destinationSymbol->fqn,
            );
        }

        $edits = [];
        if ($stale === [] && $blockers === [] && $destinationSymbol !== null && $destinationFile !== null) {
            try {
                $edits = $this->buildEdits($map, $method, $destinationSymbol, $destinationFile, $callSites, $locator);
            } catch (RuntimeException $exception) {
                $blockers[] = $exception->getMessage();
                $edits = [];
            }
        }

        $blockers = array_values(array_unique($blockers));
        $status = $stale !== [] || $blockers !== []
            ? MethodMovePlan::STATUS_BLOCKED
            : ($blindSpots !== [] ? MethodMovePlan::STATUS_REVIEW_REQUIRED : MethodMovePlan::STATUS_SAFE);
        if ($status === MethodMovePlan::STATUS_BLOCKED) {
            $edits = [];
        }

        return new MethodMovePlan(
            $status,
            $method->id,
            $destinationSymbol === null ? ltrim(trim($destination), '\\') : $destinationSymbol->fqn,
            new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            $ownerDependencies,
            $edits,
            $blindSpots,
            $stale,
            $blockers,
            self::NOT_OBSERVABLE,
        );
    }

    /**
     * @param list<string> $blockers
     * @return array{0: SymbolEntry, 1: FileEntry}|null
     */
    private function resolveDestination(AgentMapIndex $map, string $destination, array &$blockers): ?array
    {
        $wanted = ltrim(trim($destination), '\\');
        if ($wanted === '') {
            $blockers[] = 'Destination class must be given as a class identity.';

            return null;
        }
        $qualified = str_contains($wanted, '\\');
        $matches = [];
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->kind !== 'class') {
                    continue;
                }
                if ($qualified ? $symbol->fqn !== $wanted : $symbol->name !== $wanted) {
                    continue;
                }
                $matches[] = [$symbol, $file];
            }
        }
        if ($matches === []) {
            $blockers[] = 'Destination class is not present in the map: ' . $wanted . '.';

            return null;
        }
        if (count($matches) > 1) {
            $blockers[] = 'Destination class is ambiguous in the map: ' . $wanted . '; use the fully qualified name.';

            return null;
        }
        [$symbol, $file] = $matches[0];
        if ($symbol->reconciliationStatus === 'conflict') {
            $blockers[] = 'Destination class has conflicting structural and semantic declarations: ' . $symbol->fqn . '.';

            return null;
        }
        return [$symbol, $file];
    }

    /**
     * @param list<PlanBlindSpot> $blindSpots
     * @return list<array{file: string, lineStart: int, lineEnd: int}>
     */
    private function callSites(AgentMapIndex $map, string $methodId, string $ownerFqn, array &$blindSpots): array
    {
        $sites = [];
        foreach ($map->relations as $relation) {
            if ($relation->kind !== 'calls') {
                continue;
            }
            if (!in_array($methodId, $relation->targetIds, true)) {
                continue;
            }
            if (count($relation->targetIds) > 1 || $relation->resolution !== 'phpstan_resolved') {
                $blindSpots[] = new PlanBlindSpot(
                    'unresolved_call_target',
                    'A call reaching this method is not resolved to exactly one static owner, so it is not re-pointed.',
                    $relation->file,
                    $relation->lineStart,
                    $relation->lineEnd,
                );
                continue;
            }
            $sites[] = ['file' => $relation->file, 'lineStart' => $relation->lineStart, 'lineEnd' => $relation->lineEnd];
        }

        return $sites;
    }

    /**
     * @param list<array{file: string, lineStart: int, lineEnd: int}> $callSites
     * @return list<PlanEdit>
     */
    private function buildEdits(
        AgentMapIndex $map,
        ResolvedMethod $method,
        SymbolEntry $destination,
        FileEntry $destinationFile,
        array $callSites,
        MethodMoveNodeLocator $locator,
    ): array {
        $range = (new MethodNodeRemover($map->root))->locate(
            $method->file->path,
            $method->method->lineStart,
            $method->method->lineEnd,
            $method->method->name,
        );
        $anchor = $locator->insertionAnchor(
            $destinationFile->path,
            $destination->lineStart,
            $destination->lineEnd,
            $destination->fqn,
        );

        $edits = [
            new PlanEdit(
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
            ),
            new PlanEdit(
                $destinationFile->path,
                $destinationFile->sha256,
                $anchor['position'],
                $anchor['position'] + strlen($anchor['expected_anchor']) - 1,
                $anchor['line'],
                $anchor['line'],
                $anchor['expected_anchor'],
                rtrim($range['expected'], "\n") . "\n" . $anchor['expected_anchor'],
                'method_declaration_insertion',
                $destination->fqn . '::' . $method->method->name,
                'phpstan_resolved',
            ),
        ];

        // The owner token is replaced with the fully qualified destination so the
        // edit never depends on inventing an import in the calling file.
        foreach ($callSites as $site) {
            $owner = $locator->staticCallOwner($site['file'], $site['lineStart'], $site['lineEnd'], $method->method->name);
            $edits[] = new PlanEdit(
                $site['file'],
                $this->fileSha($map, $site['file']),
                $owner['start'],
                $owner['end'],
                $site['lineStart'],
                $site['lineEnd'],
                $owner['expected'],
                '\\' . $destination->fqn,
                'static_call_owner_rewrite',
                $method->id,
                'phpstan_resolved',
            );
        }

        return $edits;
    }

    private function fileSha(AgentMapIndex $map, string $path): string
    {
        foreach ($map->files as $file) {
            if ($file->path === $path) {
                return $file->sha256;
            }
        }

        throw new RuntimeException('Call site file is not part of the indexed map: ' . $path);
    }
}
