<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;

final readonly class ArchitectureMapBuilder
{
    public function __construct(
        private FileCouplingGraphBuilder $graphBuilder = new FileCouplingGraphBuilder(),
        private CrosscutAnalyzer $crosscutAnalyzer = new CrosscutAnalyzer(),
        private DeterministicCommunityDetector $communityDetector = new DeterministicCommunityDetector(),
        private RegionLabeler $labeler = new RegionLabeler(),
        private RegionEvidenceAnalyzer $evidenceAnalyzer = new RegionEvidenceAnalyzer(),
    ) {
    }

    public function build(AgentMapIndex $map): ArchitectureMapReport
    {
        $rawGraph = $this->graphBuilder->build($map);
        $crosscut = $this->crosscutAnalyzer->analyze($rawGraph);
        $partitions = $this->communityDetector->cluster($this->crosscutAnalyzer->penalize($rawGraph, $crosscut));
        $fileEntries = $this->fileEntries($map);
        $drafts = $this->drafts($rawGraph, $partitions, $crosscut, $fileEntries);
        $drafts = $this->disambiguateLabels($drafts);
        [$parents, $children] = $this->hierarchy($drafts);

        $regions = [];
        foreach ($drafts as $draft) {
            $evidence = $draft->evidence;
            $regions[] = new ArchitectureRegion(
                id: $draft->id,
                label: $draft->label,
                kind: $draft->kind,
                level: $draft->level,
                files: $draft->files,
                parentId: $parents[$draft->id] ?? null,
                childIds: $children[$draft->id] ?? [],
                internalWeight: $evidence->internalWeight,
                externalWeight: $evidence->externalWeight,
                boundaryRatio: $evidence->boundaryRatio,
                boundaryStrength: $evidence->boundaryStrength,
                internalDensity: $evidence->internalDensity,
                crosscutScore: $evidence->crosscutScore,
                namespaceAgreement: $evidence->namespaceAgreement,
                directoryAgreement: $evidence->directoryAgreement,
                interfaceFiles: $evidence->interfaceFiles,
                dominantSignals: $evidence->dominantSignals,
            );
        }
        usort($regions, static fn (ArchitectureRegion $left, ArchitectureRegion $right): int => $right->level <=> $left->level ?: $left->label <=> $right->label ?: $left->id <=> $right->id);

        $rootRegionIds = [];
        $assigned = [];
        foreach ($regions as $region) {
            if ($region->parentId === null) {
                $rootRegionIds[] = $region->id;
            }
            if ($region->level === 1) {
                foreach ($region->files as $file) {
                    $assigned[$file] = true;
                }
            }
        }
        sort($rootRegionIds, SORT_STRING);

        $unassigned = [];
        foreach ($rawGraph->files as $file) {
            if (!isset($assigned[$file])) {
                $unassigned[] = $file;
            }
        }

        return new ArchitectureMapReport(
            mapDigest: $map->mapDigest(),
            regions: $regions,
            rootRegionIds: $rootRegionIds,
            crosscutFiles: $this->crosscutAnalyzer->reported($crosscut),
            unassignedFiles: $unassigned,
        );
    }

    /** @return array<string, FileEntry> */
    private function fileEntries(AgentMapIndex $map): array
    {
        $entries = [];
        foreach ($map->files as $file) {
            $entries[$file->path] = $file;
        }

        return $entries;
    }

    /**
     * @param list<CommunityPartition> $partitions
     * @param array<string, CrosscutEvidence> $crosscut
     * @param array<string, FileEntry> $fileEntries
     * @return array<string, RegionDraft>
     */
    private function drafts(WeightedFileGraph $graph, array $partitions, array $crosscut, array $fileEntries): array
    {
        $drafts = [];
        $maximumLevel = count($partitions);
        foreach ($partitions as $levelIndex => $partition) {
            $level = $levelIndex + 1;
            foreach ($partition->communities as $files) {
                if (count($files) < 2) {
                    continue;
                }
                /** @var non-empty-list<string> $files */
                $id = 'region:' . hash('sha256', $level . "\0" . implode("\0", $files));
                $drafts[$id] = new RegionDraft(
                    id: $id,
                    label: $this->labeler->label($files, $fileEntries),
                    kind: $this->regionKind($level, $maximumLevel),
                    level: $level,
                    files: $files,
                    evidence: $this->evidenceAnalyzer->analyze($graph, $files, $crosscut, $fileEntries),
                );
            }
        }

        return $drafts;
    }

    /** @param array<string, RegionDraft> $drafts @return array<string, RegionDraft> */
    private function disambiguateLabels(array $drafts): array
    {
        $groups = [];
        foreach ($drafts as $id => $draft) {
            $groups[$draft->level . "\0" . strtolower($draft->label)][] = $id;
        }
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $draft = $drafts[$id];
                $directory = basename(dirname(str_replace('\\', '/', $draft->files[0])));
                $suffix = !$this->labeler->isNoise($directory) && strcasecmp($directory, $draft->label) !== 0
                    ? $this->labeler->humanize($directory)
                    : substr(hash('sha256', implode("\0", $draft->files)), 0, 6);
                $drafts[$id] = $draft->withLabel($draft->label . ' / ' . $suffix);
            }
        }

        return $drafts;
    }

    /**
     * @param array<string, RegionDraft> $drafts
     * @return array{array<string, string>, array<string, list<string>>}
     */
    private function hierarchy(array $drafts): array
    {
        $parents = [];
        foreach ($drafts as $id => $draft) {
            $candidates = [];
            foreach ($drafts as $candidateId => $candidate) {
                if ($candidate->level <= $draft->level || count($candidate->files) <= count($draft->files)) {
                    continue;
                }
                if ($this->containsAll($candidate->files, $draft->files)) {
                    $candidates[$candidateId] = count($candidate->files);
                }
            }
            if ($candidates !== []) {
                asort($candidates, SORT_NUMERIC);
                $parents[$id] = (string) array_key_first($candidates);
            }
        }

        $children = [];
        foreach ($parents as $child => $parent) {
            $children[$parent][] = $child;
        }
        foreach ($children as &$ids) {
            sort($ids, SORT_STRING);
        }
        unset($ids);

        return [$parents, $children];
    }

    /** @param non-empty-list<string> $container @param non-empty-list<string> $contained */
    private function containsAll(array $container, array $contained): bool
    {
        $set = array_fill_keys($container, true);
        foreach ($contained as $file) {
            if (!isset($set[$file])) {
                return false;
            }
        }

        return true;
    }

    /** @return 'module'|'subsystem'|'system' */
    private function regionKind(int $level, int $maximumLevel): string
    {
        if ($maximumLevel <= 1 || $level === $maximumLevel) {
            return 'system';
        }

        return $level === 1 ? 'module' : 'subsystem';
    }
}
