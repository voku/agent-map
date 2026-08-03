<?php

declare(strict_types=1);

namespace voku\AgentMap\Context;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\ResolvedMethod;
use voku\AgentMap\Index\SymbolEntry;

final readonly class EditContextPlanner
{
    public function __construct(private SourceMaterializer $materializer = new SourceMaterializer())
    {
    }

    public function plan(AgentMapIndex $map, string $target, ?EditContextPolicy $policy = null): EditContextPlan
    {
        $policy ??= new EditContextPolicy();
        $this->materializer->reset();
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Agent map is stale. Rebuild it before planning an edit.');
        }
        $resolved = $map->resolveMethod($target);
        $candidates = [];
        $blindSpots = [];
        $omitted = [];

        $candidates[] = $this->methodCandidate($resolved, ContextRole::PRIMARY, 'requested edit target', [], 0, true);

        $contractIds = [];
        foreach ($map->outgoing($resolved->id, 'overrides') as $relation) {
            foreach ($relation->targetIds as $targetId) {
                $contractIds[$targetId] = true;
                $contract = $map->resolvedMethodById($targetId);
                if ($contract !== null) {
                    $candidates[] = $this->methodCandidate($contract, ContextRole::CONTRACT, 'overridden or implemented contract', [$relation->id], 10, true);
                }
            }
        }
        foreach ($map->incoming($resolved->id, 'overrides') as $relation) {
            $contractIds[$relation->sourceId] = true;
            $contract = $map->resolvedMethodById($relation->sourceId);
            if ($contract !== null) {
                $candidates[] = $this->methodCandidate($contract, ContextRole::CONTRACT, 'method overriding the requested target', [$relation->id], 10, false);
            }
        }

        $callTargetIds = [$resolved->id => true, ...$contractIds];
        $directTests = 0;
        $changeCandidateIds = [];
        foreach (array_keys($callTargetIds) as $callTargetId) {
            foreach ($map->incoming($callTargetId, 'calls') as $relation) {
                $caller = $map->resolvedMethodById($relation->sourceId);
                if ($caller === null) {
                    continue;
                }
                if ($this->looksLikeTestPath($caller->file->path)) {
                    ++$directTests;
                    $candidates[] = $this->methodCandidate($caller, ContextRole::VERIFICATION, 'direct test caller', [$relation->id], 20, false);
                } else {
                    $changeCandidateIds[$caller->id] = true;
                    $candidates[] = $this->methodCandidate($caller, ContextRole::CHANGE_CANDIDATE, 'direct caller that may need adaptation', [$relation->id], 20, false);
                }
            }
        }

        foreach (array_keys($changeCandidateIds) as $callerId) {
            foreach ($map->incoming($callerId, 'calls') as $relation) {
                $testCaller = $map->resolvedMethodById($relation->sourceId);
                if ($testCaller === null || !$this->looksLikeTestPath($testCaller->file->path)) {
                    continue;
                }
                ++$directTests;
                $candidates[] = $this->methodCandidate($testCaller, ContextRole::VERIFICATION, 'test covering a direct caller of the target', [$relation->id], 20, false);
            }
        }

        foreach ($map->outgoing($resolved->id, 'calls') as $relation) {
            if (in_array($relation->resolution, ['dynamic', 'multiple_targets'], true)) {
                $blindSpots[] = new ContextBlindSpot(
                    kind: $relation->resolution === 'dynamic' ? 'dynamic_call' : 'multiple_call_targets',
                    message: $relation->resolution === 'dynamic'
                        ? 'A call inside the target could not be resolved statically.'
                        : 'A call inside the target has multiple possible targets.',
                    path: $relation->file,
                    line: $relation->lineStart,
                    evidenceIds: [$relation->id],
                );
            }
            foreach ($relation->targetIds as $targetId) {
                $callee = $map->resolvedMethodById($targetId);
                if ($callee !== null) {
                    $candidates[] = $this->methodCandidate($callee, ContextRole::DEPENDENCY, 'direct callee used by the target', [$relation->id], 30, false);
                }
            }
        }

        foreach ($map->outgoing($resolved->id, 'references_type') as $relation) {
            foreach ($relation->targetIds as $targetId) {
                $symbolMatch = $map->symbolById($targetId);
                if ($symbolMatch === null) {
                    continue;
                }
                $symbol = $symbolMatch['symbol'];
                $end = min($symbol->lineEnd, $symbol->lineStart + 30);
                if ($symbol->methods !== []) {
                    $end = min($end, max($symbol->lineStart, $symbol->methods[0]->lineStart - 1));
                }
                $candidates[] = [
                    'symbol_id' => $symbol->id(),
                    'file' => $symbolMatch['file'],
                    'line_start' => $symbol->lineStart,
                    'line_end' => $end,
                    'role' => ContextRole::TYPE_DEFINITION,
                    'reason' => 'type referenced by the target signature',
                    'evidence_ids' => [$relation->id],
                    'priority' => 40,
                    'mandatory' => false,
                ];
            }
        }

        if ($directTests === 0) {
            foreach ($map->likelyTestFiles($resolved->file, $policy->maximumTests) as $testFile) {
                foreach ($testFile->symbols as $testSymbol) {
                    foreach (array_slice($testSymbol->methods, 0, 3) as $testMethod) {
                        $testResolved = new ResolvedMethod($testSymbol->methodId($testMethod), $testFile, $testSymbol, $testMethod);
                        $candidates[] = $this->methodCandidate($testResolved, ContextRole::VERIFICATION, 'heuristic test candidate; no direct test call was resolved', [], 50, false);
                    }
                }
            }
        }

        foreach ($map->diagnostics as $diagnostic) {
            if ($diagnostic->symbolId !== $resolved->id && $diagnostic->symbolId !== $resolved->owner->id()) {
                continue;
            }
            $blindSpots[] = new ContextBlindSpot('map_conflict', $diagnostic->message, $diagnostic->file, $diagnostic->line, [$diagnostic->id]);
        }

        $candidates = $this->deduplicateCandidates($candidates);
        usort($candidates, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority'] ?: $left['file']->path <=> $right['file']->path ?: $left['line_start'] <=> $right['line_start'] ?: $left['symbol_id'] <=> $right['symbol_id']);
        $selected = [];
        $selectedFiles = [];
        $sourceBytes = 0;
        $roleCounts = [ContextRole::CHANGE_CANDIDATE => 0, ContextRole::DEPENDENCY => 0, ContextRole::VERIFICATION => 0, ContextRole::TYPE_DEFINITION => 0];

        foreach ($candidates as $candidate) {
            $capReason = $this->capReason($candidate['role'], $roleCounts, $policy);
            if ($capReason !== null && !$candidate['mandatory']) {
                $omitted[] = new OmittedContext($candidate['symbol_id'], $candidate['role'], $capReason);
                continue;
            }

            /** @var FileEntry $file */
            $file = $candidate['file'];
            $source = $this->materializer->materialize($map->root, $file, $candidate['line_start'], $candidate['line_end']);
            $bytes = strlen($source['content']);
            $newFile = !isset($selectedFiles[$file->path]);
            $budgetExceeded = $sourceBytes + $bytes > $policy->maximumSourceBytes || ($newFile && count($selectedFiles) + 1 > $policy->maximumFiles);
            if ($budgetExceeded) {
                if ($candidate['mandatory']) {
                    throw new RuntimeException('Context budget is too small for the requested method and its contracts.');
                }
                $omitted[] = new OmittedContext($candidate['symbol_id'], $candidate['role'], $sourceBytes + $bytes > $policy->maximumSourceBytes ? 'source byte budget exhausted' : 'maximum file count reached');
                continue;
            }

            $selected[] = [
                'file' => $file,
                'line_start' => $source['start'],
                'line_end' => $source['end'],
                'role' => $candidate['role'],
                'reason' => $candidate['reason'],
                'evidence_ids' => $candidate['evidence_ids'],
            ];
            $sourceBytes += $bytes;
            $selectedFiles[$file->path] = true;
            if (isset($roleCounts[$candidate['role']])) {
                ++$roleCounts[$candidate['role']];
            }
        }

        $slices = $this->mergeAndMaterialize($map, $selected);
        $sourceBytes = array_sum(array_map(static fn (CodeSlice $slice): int => strlen($slice->content), $slices));

        return new EditContextPlan(
            requestedTarget: $target,
            resolvedTarget: $resolved,
            policy: $policy,
            slices: $slices,
            blindSpots: $this->uniqueBlindSpots($blindSpots),
            omitted: $omitted,
            mapDigest: $map->mapDigest(),
            sourceBytes: $sourceBytes,
        );
    }

    /**
     * @param list<string> $evidenceIds
     * @return array<string, mixed>
     */
    private function methodCandidate(ResolvedMethod $method, string $role, string $reason, array $evidenceIds, int $priority, bool $mandatory): array
    {
        return [
            'symbol_id' => $method->id,
            'file' => $method->file,
            'line_start' => $method->method->lineStart,
            'line_end' => $method->method->lineEnd,
            'role' => $role,
            'reason' => $reason,
            'evidence_ids' => $evidenceIds,
            'priority' => $priority,
            'mandatory' => $mandatory,
        ];
    }

    /** @param array<string, int> $counts */
    private function capReason(string $role, array $counts, EditContextPolicy $policy): ?string
    {
        return match ($role) {
            ContextRole::CHANGE_CANDIDATE => ($counts[$role] ?? 0) >= $policy->maximumCallers ? 'maximum caller count reached' : null,
            ContextRole::DEPENDENCY => ($counts[$role] ?? 0) >= $policy->maximumCallees ? 'maximum callee count reached' : null,
            ContextRole::VERIFICATION => ($counts[$role] ?? 0) >= $policy->maximumTests ? 'maximum test count reached' : null,
            ContextRole::TYPE_DEFINITION => ($counts[$role] ?? 0) >= $policy->maximumTypeDefinitions ? 'maximum type definition count reached' : null,
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $selected
     * @return list<CodeSlice>
     */
    private function mergeAndMaterialize(AgentMapIndex $map, array $selected): array
    {
        usort($selected, static fn (array $left, array $right): int => $left['file']->path <=> $right['file']->path ?: $left['line_start'] <=> $right['line_start']);
        $merged = [];
        foreach ($selected as $candidate) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0 && $merged[$lastIndex]['file']->path === $candidate['file']->path && $candidate['line_start'] <= $merged[$lastIndex]['line_end']) {
                $merged[$lastIndex]['line_end'] = max($merged[$lastIndex]['line_end'], $candidate['line_end']);
                $merged[$lastIndex]['roles'][] = $candidate['role'];
                $merged[$lastIndex]['reasons'][] = $candidate['reason'];
                $merged[$lastIndex]['evidence_ids'] = [...$merged[$lastIndex]['evidence_ids'], ...$candidate['evidence_ids']];
                continue;
            }
            $merged[] = [
                'file' => $candidate['file'],
                'line_start' => $candidate['line_start'],
                'line_end' => $candidate['line_end'],
                'roles' => [$candidate['role']],
                'reasons' => [$candidate['reason']],
                'evidence_ids' => $candidate['evidence_ids'],
            ];
        }

        $slices = [];
        foreach ($merged as $entry) {
            $source = $this->materializer->materialize($map->root, $entry['file'], $entry['line_start'], $entry['line_end'], false);
            $roles = array_values(array_unique($entry['roles']));
            $reasons = array_values(array_unique($entry['reasons']));
            $evidenceIds = array_values(array_unique($entry['evidence_ids']));
            sort($roles, SORT_STRING);
            sort($reasons, SORT_STRING);
            sort($evidenceIds, SORT_STRING);
            $slices[] = new CodeSlice($entry['file']->path, $source['start'], $source['end'], $source['content'], $roles, $reasons, $evidenceIds, $entry['file']->sha256);
        }

        return $slices;
    }

    /**
     * @param list<ContextBlindSpot> $blindSpots
     * @return list<ContextBlindSpot>
     */
    private function uniqueBlindSpots(array $blindSpots): array
    {
        $unique = [];
        foreach ($blindSpots as $blindSpot) {
            $key = $blindSpot->kind . "\0" . $blindSpot->message . "\0" . ($blindSpot->path ?? '') . "\0" . (string) ($blindSpot->line ?? 0);
            $unique[$key] = $blindSpot;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }


    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function deduplicateCandidates(array $candidates): array
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = (string) $candidate['symbol_id'] . "\0" . (string) $candidate['role'] . "\0" . (string) $candidate['reason'];
            if (!isset($unique[$key])) {
                $unique[$key] = $candidate;
                continue;
            }
            $evidence = [...$unique[$key]['evidence_ids'], ...$candidate['evidence_ids']];
            $evidence = array_values(array_unique(array_map('strval', $evidence)));
            sort($evidence, SORT_STRING);
            $unique[$key]['evidence_ids'] = $evidence;
            $unique[$key]['mandatory'] = (bool) $unique[$key]['mandatory'] || (bool) $candidate['mandatory'];
            $unique[$key]['priority'] = min((int) $unique[$key]['priority'], (int) $candidate['priority']);
        }

        return array_values($unique);
    }
    private function looksLikeTestPath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        $segments = explode('/', $normalized);
        $fileName = array_pop($segments);

        return in_array('tests', $segments, true)
            || in_array('test', $segments, true)
            || str_ends_with($fileName, 'test.php')
            || str_ends_with($fileName, 'cest.php');
    }
}
