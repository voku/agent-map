<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class ImpactArchitectureProjector
{
    /** @param list<ImpactNode> $impacts @return list<ImpactRegionBucket> */
    public function project(ArchitectureMapReport $architecture, array $impacts): array
    {
        /**
         * @var array<string, array{
         *   region_id: ?string,
         *   label: string,
         *   path: list<string>,
         *   node_ids: array<string, true>,
         *   uncertain_nodes: array<string, true>,
         *   maximum_depth: int
         * }> $grouped
         */
        $grouped = [];

        foreach ($impacts as $impact) {
            $path = $architecture->pathForFile($impact->node->file);
            $region = $path[0] ?? null;
            $key = $region?->id ?? '(unassigned)';
            $grouped[$key] ??= [
                'region_id' => $region?->id,
                'label' => $region?->label ?? '(unassigned)',
                'path' => array_map(static fn (ArchitectureRegion $item): string => $item->label, $path),
                'node_ids' => [],
                'uncertain_nodes' => [],
                'maximum_depth' => 0,
            ];
            $grouped[$key]['node_ids'][$impact->node->id] = true;
            if ($impact->uncertain) {
                $grouped[$key]['uncertain_nodes'][$impact->node->id] = true;
            }
            $grouped[$key]['maximum_depth'] = max($grouped[$key]['maximum_depth'], $impact->depth);
        }

        $buckets = [];
        foreach ($grouped as $row) {
            $nodeIds = array_keys($row['node_ids']);
            sort($nodeIds, SORT_STRING);
            $buckets[] = new ImpactRegionBucket(
                regionId: $row['region_id'],
                label: $row['label'],
                pathLabels: $row['path'],
                nodeIds: $nodeIds,
                uncertainNodes: count($row['uncertain_nodes']),
                maximumDepth: $row['maximum_depth'],
            );
        }
        usort($buckets, static fn (ImpactRegionBucket $left, ImpactRegionBucket $right): int => count($right->nodeIds) <=> count($left->nodeIds) ?: $left->label <=> $right->label);

        return $buckets;
    }
}
