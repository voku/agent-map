<?php

declare(strict_types=1);

namespace voku\AgentMap\Scaffold;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\ResolvedMethod;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Move\MethodMoveNodeLocator;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Plan\PlanProvenance;
use voku\AgentMap\Plan\PlanStaleEvidence;
use voku\AgentMap\Plan\PlanStatus;
use voku\AgentMap\Removal\MethodNodeRemover;

final readonly class MethodCopyPlanner
{
    private const NOT_OBSERVABLE = [
        'Method copy duplicates declaration text; runtime callers and reflection references are not updated.',
    ];

    public function __construct(private ?string $root = null)
    {
    }

    public function plan(
        AgentMapIndex $map,
        string $sourceTarget,
        ?string $destinationClass = null,
        ?string $newMethodName = null,
        ?string $visibility = null,
    ): MethodCopyPlan {
        $root = $this->root ?? $map->root;

        try {
            $method = $map->resolveMethod($sourceTarget);
        } catch (RuntimeException $e) {
            return $this->blockedPlan($sourceTarget, $destinationClass ?? '', $newMethodName ?? '', $e->getMessage(), $map);
        }

        $destFqn = $destinationClass !== null && trim($destinationClass) !== ''
            ? ltrim(trim($destinationClass), '\\')
            : $method->owner->fqn;

        $targetName = $newMethodName !== null && trim($newMethodName) !== ''
            ? trim($newMethodName)
            : ($destFqn === $method->owner->fqn ? null : $method->method->name);

        if ($targetName === null || $targetName === '') {
            return $this->blockedPlan(
                $method->id,
                $destFqn,
                '',
                'Copying a method within the same class requires an explicit new method name.',
                $map,
            );
        }

        $destMatch = $this->resolveClass($map, $destFqn);
        if ($destMatch === null) {
            return $this->blockedPlan($method->id, $destFqn, $targetName, 'Destination class not found in index: ' . $destFqn, $map);
        }
        [$destSymbol, $destFile] = $destMatch;

        foreach ($destSymbol->methods as $existing) {
            if (strcasecmp($existing->name, $targetName) === 0) {
                return $this->blockedPlan(
                    $method->id,
                    $destSymbol->fqn,
                    $targetName,
                    sprintf('Method %s already exists on destination class %s.', $targetName, $destSymbol->fqn),
                    $map,
                );
            }
        }

        $destFilePath = rtrim(str_replace('\\', '/', $root), '/') . '/' . ltrim($destFile->path, '/');
        if (!is_file($destFilePath)) {
            return $this->blockedPlan($method->id, $destSymbol->fqn, $targetName, 'Destination file not found: ' . $destFile->path, $map);
        }
        $destSource = file_get_contents($destFilePath);
        if (!is_string($destSource)) {
            return $this->blockedPlan($method->id, $destSymbol->fqn, $targetName, 'Could not read destination file: ' . $destFile->path, $map);
        }

        // Locate source method declaration text
        try {
            $range = (new MethodNodeRemover($root))->locate(
                $method->file->path,
                $method->method->lineStart,
                $method->method->lineEnd,
                $method->method->name,
            );
        } catch (RuntimeException $e) {
            return $this->blockedPlan($method->id, $destSymbol->fqn, $targetName, 'Could not extract source method declaration: ' . $e->getMessage(), $map);
        }

        $adaptedText = $range['expected'];

        // Adapt method name in declaration
        $namePattern = '/(\bfunction\s+)' . preg_quote($method->method->name, '/') . '(\s*\()/i';
        if (preg_match($namePattern, $adaptedText) === 1) {
            $adaptedText = (string) preg_replace($namePattern, '${1}' . $targetName . '${2}', $adaptedText, 1);
        }

        // Adapt visibility if requested
        if ($visibility !== null && in_array(strtolower($visibility), ['public', 'protected', 'private'], true)) {
            $newVis = strtolower($visibility);
            $visPattern = '/(\b)(public|protected|private)(\s+function|\s+static\s+function)/i';
            if (preg_match($visPattern, $adaptedText) === 1) {
                $adaptedText = (string) preg_replace($visPattern, '${1}' . $newVis . '${3}', $adaptedText, 1);
            }
        }

        // Locate insertion anchor in destination class
        $locator = new MethodMoveNodeLocator($root);
        try {
            $anchor = $locator->insertionAnchor($destFile->path, $destSymbol->lineStart, $destSymbol->lineEnd, $destSymbol->fqn);
        } catch (RuntimeException $e) {
            return $this->blockedPlan($method->id, $destSymbol->fqn, $targetName, 'Could not locate insertion anchor: ' . $e->getMessage(), $map);
        }

        $edits = [];
        $blindSpots = [];
        $useLocator = new UseStatementLocator();

        // Check if destination differs and method uses owner dependencies
        $ownerDependencies = [];
        $isDifferentClass = $destSymbol->fqn !== $method->owner->fqn;
        if ($isDifferentClass) {
            $ownerDependencies = $locator->ownerDependencies(
                $method->file->path,
                $method->method->lineStart,
                $method->method->lineEnd,
                $method->method->name,
            );
            if ($ownerDependencies !== []) {
                $blindSpots[] = new PlanBlindSpot(
                    'copied_owner_dependencies',
                    sprintf(
                        'Copied method body references owner dependencies (%s) that may need manual adaptation for %s.',
                        implode(', ', $ownerDependencies),
                        $destSymbol->fqn,
                    ),
                    $destFile->path,
                    $anchor['line'],
                    $anchor['line'],
                );
            }
        }

        // Check types for use statement insertion in destination
        $typesToCheck = [];
        $retType = $method->method->resolvedReturnType ?? $method->method->nativeReturnType;
        if ($retType !== null && !$useLocator->isBuiltinType($retType)) {
            $typesToCheck[] = $retType;
        }
        foreach ($method->method->parameters as $param) {
            $pType = $param->resolvedType ?? $param->nativeType;
            if ($pType !== null && !$useLocator->isBuiltinType($pType)) {
                $typesToCheck[] = $pType;
            }
        }

        foreach (array_unique($typesToCheck) as $typeFqn) {
            $useInsertion = $useLocator->findUseInsertion($destSource, $typeFqn);
            if ($useInsertion !== null) {
                $edits[] = new PlanEdit(
                    $destFile->path,
                    $destFile->sha256,
                    $useInsertion['start'],
                    $useInsertion['end'],
                    $useInsertion['line'],
                    $useInsertion['line'],
                    $useInsertion['expected'],
                    $useInsertion['insertion'],
                    'use_statement_insertion',
                    'use:' . $typeFqn,
                    'copy_dependency',
                );
            }
        }

        // Add method declaration insertion edit
        $edits[] = new PlanEdit(
            $destFile->path,
            $destFile->sha256,
            $anchor['position'],
            $anchor['position'] + strlen($anchor['expected_anchor']) - 1,
            $anchor['line'],
            $anchor['line'],
            $anchor['expected_anchor'],
            "\n" . rtrim($adaptedText, "\n") . "\n" . $anchor['expected_anchor'],
            'method_declaration_insertion',
            $destSymbol->fqn . '::' . $targetName,
            'copy_declaration',
        );

        // Verify syntax of simulated destination file
        $verifier = new PhpSyntaxVerifier();
        $syntaxError = $verifier->verifyEdits($destSource, $edits);
        if ($syntaxError !== null) {
            return $this->blockedPlan($method->id, $destSymbol->fqn, $targetName, 'Syntax check failed on copied code: ' . $syntaxError, $map);
        }

        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        $status = $blindSpots !== [] ? PlanStatus::REVIEW_REQUIRED : PlanStatus::SAFE;

        return new MethodCopyPlan(
            status: $status,
            sourceId: $method->id,
            destinationFqn: $destSymbol->fqn,
            newMethodName: $targetName,
            provenance: new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            ownerDependencies: $ownerDependencies,
            edits: $edits,
            blindSpots: $blindSpots,
            staleEvidence: $stale,
            blockers: [],
            notObservable: self::NOT_OBSERVABLE,
        );
    }

    /**
     * @return array{SymbolEntry, FileEntry}|null
     */
    private function resolveClass(AgentMapIndex $map, string $target): ?array
    {
        $clean = ltrim($target, '\\');
        foreach ($map->files as $file) {
            foreach ($file->symbols as $symbol) {
                if (ltrim($symbol->fqn, '\\') === $clean || $symbol->name === $clean) {
                    return [$symbol, $file];
                }
            }
        }

        return null;
    }

    private function blockedPlan(string $sourceId, string $destFqn, string $newMethodName, string $blocker, AgentMapIndex $map): MethodCopyPlan
    {
        $stale = array_map(
            static fn (array $entry): PlanStaleEvidence => new PlanStaleEvidence($entry['path'], $entry['reason']),
            $map->staleEntries(),
        );

        return new MethodCopyPlan(
            status: PlanStatus::BLOCKED,
            sourceId: $sourceId,
            destinationFqn: $destFqn,
            newMethodName: $newMethodName,
            provenance: new PlanProvenance($map->mapDigest(), $map->backend, $map->fingerprint),
            ownerDependencies: [],
            edits: [],
            blindSpots: [],
            staleEvidence: $stale,
            blockers: [$blocker],
            notObservable: self::NOT_OBSERVABLE,
        );
    }
}
