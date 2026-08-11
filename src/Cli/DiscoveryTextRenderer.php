<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use voku\AgentMap\Discovery\ArchitectureImpactReport;
use voku\AgentMap\Discovery\ArchitectureMapReport;
use voku\AgentMap\Discovery\ArchitectureRegion;
use voku\AgentMap\Discovery\GraphMetric;
use voku\AgentMap\Discovery\ImpactRegionBucket;
use voku\AgentMap\Discovery\RankedNode;
use voku\AgentMap\Discovery\RepositoryDiscoveryReport;

final readonly class DiscoveryTextRenderer
{
    public function discovery(RepositoryDiscoveryReport $report): string
    {
        $out = "PHP architecture discovery\n";
        $out .= 'Map: ' . $report->mapDigest . "\n";
        $out .= sprintf(
            "Relations: %d certain, %d uncertain, %d diagnostic(s)\n\n",
            $report->quality['certain_relations'],
            $report->quality['uncertain_relations'],
            $report->quality['diagnostics'],
        );
        if ($report->architecture !== null) {
            $out .= $this->architecture($report->architecture);
        }
        $out .= $this->rankedSection('Entrypoint candidates', $report->entrypointCandidates);
        $out .= $this->rankedSection('Call hubs', $report->callHubs);
        $out .= $this->rankedSection('Orchestrators', $report->orchestrators);
        $out .= $this->rankedSection('Type hubs', $report->typeHubs);
        $out .= $this->couplingSection('Namespace coupling', $report->namespaceCoupling);
        $out .= $this->couplingSection('Directory coupling', $report->directoryCoupling);
        $out .= $this->couplingSection('File coupling', $report->fileCoupling);

        return $out;
    }

    public function region(ArchitectureRegion $region, ArchitectureMapReport $architecture, int $limit): string
    {
        $out = sprintf("Region: %s (%s, level %d)\n", $region->label, $region->kind, $region->level);
        $path = $architecture->pathForRegion($region);
        $out .= 'Path: ' . implode(' > ', array_map(
            static fn (ArchitectureRegion $item): string => $item->label,
            array_reverse($path),
        )) . "\n";
        $out .= sprintf(
            "Evidence: boundary=%.2f ratio=%.2f density=%.2f crosscut=%.2f namespace=%.2f directory=%.2f\n",
            $region->boundaryStrength,
            $region->boundaryRatio,
            $region->internalDensity,
            $region->crosscutScore,
            $region->namespaceAgreement,
            $region->directoryAgreement,
        );
        $out .= 'Signals: ' . ($region->dominantSignals === [] ? '(none)' : implode(', ', $region->dominantSignals)) . "\n";
        $out .= $this->stringListSection('Files', $region->files, $limit);
        $out .= $this->stringListSection('Interface files', $region->interfaceFiles, $limit);

        $childrenById = [];
        foreach ($architecture->regions as $candidate) {
            $childrenById[$candidate->id] = $candidate->label;
        }
        $children = [];
        foreach ($region->childIds as $childId) {
            if (isset($childrenById[$childId])) {
                $children[] = $childrenById[$childId];
            }
        }
        if ($children !== []) {
            $out .= $this->stringListSection('Children', $children, $limit);
        }

        return $out;
    }

    /** @param list<RankedNode> $ranked */
    public function rank(GraphMetric $metric, array $ranked): string
    {
        return $this->rankedSection('Rank by ' . $metric->value, $ranked);
    }

    public function impact(ArchitectureImpactReport $report): string
    {
        $impact = $report->impact;
        $out = 'Impact: ' . $impact->target->name . "\n";
        if ($report->targetArchitecturePath !== []) {
            $out .= 'Target path: ' . implode(' > ', array_reverse($report->targetArchitecturePath)) . "\n";
        }
        if ($report->regionBuckets !== []) {
            $out .= "Propagation by region:\n";
            foreach ($report->regionBuckets as $bucket) {
                $out .= $this->impactRegion($bucket);
            }
        }
        $out .= "Nodes:\n";
        foreach ($impact->impacts as $node) {
            $out .= sprintf(
                "  d=%d %s%s [%s] %s:%d evidence=%s via=%s\n",
                $node->depth,
                $node->uncertain ? '? ' : '  ',
                $node->node->name,
                implode(',', $node->relationKinds),
                $node->node->file,
                $node->node->lineStart,
                implode(',', $node->evidenceIds),
                implode(',', $node->viaNodeIds),
            );
        }
        if ($impact->truncated) {
            $out .= "TRUNCATED: increase --max-nodes for a complete bounded traversal.\n";
        }

        return $out;
    }

    private function architecture(ArchitectureMapReport $architecture): string
    {
        $out = sprintf(
            "Architecture regions: %d region(s), %d level(s)\n",
            count($architecture->regions),
            $architecture->levels(),
        );
        if ($architecture->regions === []) {
            $out .= '  none inferred';
            if ($architecture->unassignedFiles !== []) {
                $out .= sprintf(' (%d unassigned file(s))', count($architecture->unassignedFiles));
            }
            return $out . "\n\n";
        }

        $byId = [];
        foreach ($architecture->regions as $region) {
            $byId[$region->id] = $region;
        }
        foreach ($architecture->rootRegionIds as $rootId) {
            $region = $byId[$rootId] ?? null;
            if ($region instanceof ArchitectureRegion) {
                $out .= $this->architectureRegion($region, $byId, 1);
            }
        }
        if ($architecture->crosscutFiles !== []) {
            $out .= '  cross-cutting: ' . implode(', ', array_map(
                static fn (array $row): string => $row['file'] . '=' . number_format($row['score'], 2, '.', ''),
                array_slice($architecture->crosscutFiles, 0, 5),
            )) . "\n";
        }
        if ($architecture->unassignedFiles !== []) {
            $out .= sprintf('  unassigned: %d file(s)\n', count($architecture->unassignedFiles));
        }

        return $out . "\n";
    }

    /** @param array<string, ArchitectureRegion> $byId */
    private function architectureRegion(ArchitectureRegion $region, array $byId, int $depth): string
    {
        $signals = $region->dominantSignals === [] ? '' : ' signals=' . implode(',', $region->dominantSignals);
        $out = sprintf(
            "%s%s %s [%df] boundary=%.2f ns=%.2f dir=%.2f%s\n",
            str_repeat('  ', $depth),
            strtoupper($region->kind),
            $region->label,
            count($region->files),
            $region->boundaryStrength,
            $region->namespaceAgreement,
            $region->directoryAgreement,
            $signals,
        );
        foreach ($region->childIds as $childId) {
            $child = $byId[$childId] ?? null;
            if ($child instanceof ArchitectureRegion) {
                $out .= $this->architectureRegion($child, $byId, $depth + 1);
            }
        }

        return $out;
    }

    /** @param list<string> $items */
    private function stringListSection(string $title, array $items, int $limit): string
    {
        $out = $title . ":\n";
        foreach (array_slice($items, 0, $limit) as $item) {
            $out .= '  ' . $item . "\n";
        }
        if (count($items) > $limit) {
            $out .= sprintf("  ... %d more\n", count($items) - $limit);
        }

        return $out;
    }

    /** @param list<RankedNode> $rows */
    private function rankedSection(string $title, array $rows): string
    {
        $out = $title . ":\n";
        foreach ($rows as $row) {
            $out .= sprintf(
                "  %4d  ?%-3d  %s  %s:%d\n",
                $row->score,
                $row->uncertainRelations,
                $row->node->name,
                $row->node->file,
                $row->node->lineStart,
            );
        }

        return $out . "\n";
    }

    /** @param list<array{from: string, to: string, links: int, uncertain_links: int}> $rows */
    private function couplingSection(string $title, array $rows): string
    {
        $out = $title . ":\n";
        foreach ($rows as $row) {
            $out .= sprintf(
                "  %4d link(s), %3d uncertain  %s -> %s\n",
                $row['links'],
                $row['uncertain_links'],
                $row['from'],
                $row['to'],
            );
        }

        return $out . "\n";
    }

    private function impactRegion(ImpactRegionBucket $bucket): string
    {
        $path = $bucket->pathLabels === [] ? $bucket->label : implode(' > ', array_reverse($bucket->pathLabels));
        return sprintf(
            "  %s: %d node(s), %d uncertain, depth<=%d\n",
            $path,
            count($bucket->nodeIds),
            $bucket->uncertainNodes,
            $bucket->maximumDepth,
        );
    }
}
