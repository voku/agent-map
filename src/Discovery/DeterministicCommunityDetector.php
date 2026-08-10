<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class DeterministicCommunityDetector
{
    private const MAX_PASSES = 30;
    private const MIN_GAIN = 0.000001;

    public function __construct(
        private SingletonCommunityAbsorber $singletonAbsorber = new SingletonCommunityAbsorber(),
    ) {
    }

    /** @return list<CommunityPartition> Finest partition first. */
    public function cluster(WeightedFileGraph $graph, int $maximumLevels = 4): array
    {
        if ($maximumLevels < 1 || count($graph->files) < 2 || $graph->totalWeight() <= 0.0) {
            return [];
        }

        $resolution = $this->resolutionForFileCount(count($graph->files));
        /** @var array<string, non-empty-list<string>> $expansion */
        $expansion = [];
        foreach ($graph->files as $file) {
            $expansion[$file] = [$file];
        }

        $current = $graph;
        $levels = [];
        for ($level = 0; $level < $maximumLevels && count($current->files) > 1; ++$level) {
            $assignment = $this->louvainPass($current, $resolution);
            $assignment = $this->singletonAbsorber->absorb($current, $assignment);
            $assignment = $this->denseAssignment($assignment);
            $projected = $this->project($assignment, $expansion);

            if (count($projected->communities) <= 1) {
                if ($levels === []) {
                    $levels[] = $projected;
                }
                break;
            }
            if ($levels !== [] && $this->samePartition($levels[array_key_last($levels)], $projected)) {
                break;
            }
            $levels[] = $projected;

            [$current, $expansion] = $this->coarsen($current, $assignment, $expansion);
            $resolution = max(0.45, $resolution * 0.82);
        }

        return $levels;
    }

    public function resolutionForFileCount(int $fileCount): float
    {
        return match (true) {
            $fileCount < 100 => 1.1,
            $fileCount < 750 => 0.9,
            $fileCount < 2500 => 0.75,
            default => 0.6,
        };
    }

    /** @return array<string, int> */
    private function louvainPass(WeightedFileGraph $graph, float $resolution): array
    {
        $nodes = $graph->files;
        sort($nodes, SORT_STRING);

        $communityByNode = [];
        $totalDegreeByCommunity = [];
        foreach ($nodes as $index => $node) {
            $communityByNode[$node] = $index;
            $totalDegreeByCommunity[$index] = $graph->degree($node);
        }

        $totalWeight = max($graph->totalWeight(), 0.000000000001);
        for ($pass = 0; $pass < self::MAX_PASSES; ++$pass) {
            $moved = false;
            $passGain = 0.0;

            foreach ($nodes as $node) {
                $currentCommunity = $communityByNode[$node];
                $degree = $graph->degree($node);
                if ($degree <= 0.0) {
                    continue;
                }

                $weightByCommunity = [];
                foreach ($graph->neighbours($node) as $neighbour => $weight) {
                    $community = $communityByNode[$neighbour];
                    $weightByCommunity[$community] = ($weightByCommunity[$community] ?? 0.0) + $weight;
                }

                $totalDegreeByCommunity[$currentCommunity] -= $degree;
                $bestCommunity = $currentCommunity;
                $bestGain = 0.0;
                ksort($weightByCommunity, SORT_NUMERIC);
                foreach ($weightByCommunity as $candidateCommunity => $weightIntoCommunity) {
                    $gain = $weightIntoCommunity / $totalWeight
                        - $resolution
                        * ($totalDegreeByCommunity[$candidateCommunity] ?? 0.0)
                        * $degree
                        / (2.0 * $totalWeight * $totalWeight);

                    if ($gain > $bestGain + self::MIN_GAIN
                        || (abs($gain - $bestGain) <= self::MIN_GAIN && $gain > self::MIN_GAIN && $candidateCommunity < $bestCommunity)
                    ) {
                        $bestCommunity = $candidateCommunity;
                        $bestGain = $gain;
                    }
                }

                $communityByNode[$node] = $bestCommunity;
                $totalDegreeByCommunity[$bestCommunity] = ($totalDegreeByCommunity[$bestCommunity] ?? 0.0) + $degree;
                if ($bestCommunity !== $currentCommunity) {
                    $moved = true;
                    $passGain += $bestGain;
                }
            }

            if (!$moved || $passGain < self::MIN_GAIN) {
                break;
            }
        }

        return $this->denseAssignment($communityByNode);
    }

    /**
     * @param array<string, int> $assignment
     * @param array<string, non-empty-list<string>> $expansion
     */
    private function project(array $assignment, array $expansion): CommunityPartition
    {
        /** @var array<int, non-empty-list<string>> $grouped */
        $grouped = [];
        foreach ($assignment as $node => $community) {
            foreach ($expansion[$node] ?? [$node] as $originalFile) {
                $grouped[$community][] = $originalFile;
            }
        }

        /** @var list<non-empty-list<string>> $communities */
        $communities = [];
        foreach ($grouped as $files) {
            $files = array_values(array_unique($files));
            sort($files, SORT_STRING);
            $communities[] = $files;
        }
        usort($communities, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $projectedAssignment = [];
        foreach ($communities as $community => $files) {
            foreach ($files as $file) {
                $projectedAssignment[$file] = $community;
            }
        }
        ksort($projectedAssignment, SORT_STRING);

        return new CommunityPartition($communities, $projectedAssignment);
    }

    /**
     * @param array<string, int> $assignment
     * @param array<string, non-empty-list<string>> $expansion
     * @return array{WeightedFileGraph, array<string, non-empty-list<string>>}
     */
    private function coarsen(WeightedFileGraph $graph, array $assignment, array $expansion): array
    {
        /** @var array<int, non-empty-list<string>> $membersByCommunity */
        $membersByCommunity = [];
        foreach ($assignment as $node => $community) {
            $membersByCommunity[$community][] = $node;
        }
        ksort($membersByCommunity, SORT_NUMERIC);

        $superNodeByCommunity = [];
        /** @var array<string, non-empty-list<string>> $newExpansion */
        $newExpansion = [];
        foreach ($membersByCommunity as $community => $members) {
            $originalFiles = [];
            foreach ($members as $member) {
                array_push($originalFiles, ...($expansion[$member] ?? [$member]));
            }
            $originalFiles = array_values(array_unique($originalFiles));
            sort($originalFiles, SORT_STRING);
            /** @var non-empty-list<string> $originalFiles */
            $superNode = 'community:' . hash('sha256', implode("\0", $originalFiles));
            $superNodeByCommunity[$community] = $superNode;
            $newExpansion[$superNode] = $originalFiles;
        }

        $newFiles = array_values($superNodeByCommunity);
        sort($newFiles, SORT_STRING);
        $newAdjacency = array_fill_keys($newFiles, []);

        foreach ($graph->adjacency as $source => $neighbours) {
            foreach ($neighbours as $target => $weight) {
                if ($source >= $target) {
                    continue;
                }
                $sourceCommunity = $assignment[$source];
                $targetCommunity = $assignment[$target];
                if ($sourceCommunity === $targetCommunity) {
                    continue;
                }
                $left = $superNodeByCommunity[$sourceCommunity];
                $right = $superNodeByCommunity[$targetCommunity];
                $newAdjacency[$left][$right] = ($newAdjacency[$left][$right] ?? 0.0) + $weight;
                $newAdjacency[$right][$left] = ($newAdjacency[$right][$left] ?? 0.0) + $weight;
            }
        }

        foreach ($newAdjacency as &$neighbours) {
            ksort($neighbours, SORT_STRING);
        }
        unset($neighbours);
        ksort($newExpansion, SORT_STRING);

        return [new WeightedFileGraph($newFiles, $newAdjacency, []), $newExpansion];
    }

    /**
     * @param array<string, int> $assignment
     * @return array<string, int>
     */
    private function denseAssignment(array $assignment): array
    {
        /** @var array<int, non-empty-list<string>> $members */
        $members = [];
        foreach ($assignment as $node => $community) {
            $members[$community][] = $node;
        }
        foreach ($members as &$nodes) {
            sort($nodes, SORT_STRING);
        }
        unset($nodes);
        uasort($members, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $reindex = [];
        foreach (array_keys($members) as $dense => $oldCommunity) {
            $reindex[$oldCommunity] = $dense;
        }
        foreach ($assignment as $node => $community) {
            $assignment[$node] = $reindex[$community];
        }
        ksort($assignment, SORT_STRING);

        return $assignment;
    }

    private function samePartition(CommunityPartition $left, CommunityPartition $right): bool
    {
        return $left->communities === $right->communities;
    }
}
