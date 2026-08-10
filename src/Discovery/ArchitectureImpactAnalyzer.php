<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\AgentMapIndex;

final readonly class ArchitectureImpactAnalyzer
{
    public function __construct(
        private ImpactAnalyzer $impactAnalyzer = new ImpactAnalyzer(),
        private ArchitectureMapBuilder $architectureMapBuilder = new ArchitectureMapBuilder(),
        private ImpactArchitectureProjector $architectureProjector = new ImpactArchitectureProjector(),
    ) {
    }

    public function forMethod(
        AgentMapIndex $map,
        string $target,
        int $maximumDepth = 2,
        int $maximumNodes = 100,
    ): ArchitectureImpactReport {
        $impact = $this->impactAnalyzer->forMethod($map, $target, $maximumDepth, $maximumNodes);
        $architecture = $this->architectureMapBuilder->build($map);
        $targetPath = array_map(
            static fn (ArchitectureRegion $region): string => $region->label,
            $architecture->pathForFile($impact->target->file),
        );

        return new ArchitectureImpactReport(
            impact: $impact,
            targetArchitecturePath: $targetPath,
            regionBuckets: $this->architectureProjector->project($architecture, $impact->impacts),
        );
    }
}
