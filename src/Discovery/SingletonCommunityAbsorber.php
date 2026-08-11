<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

/** @internal */
final readonly class SingletonCommunityAbsorber
{
    private const MIN_GAIN = 0.000001;

    /**
     * @param array<string, int> $assignment
     * @return array<string, int>
     */
    public function absorb(WeightedFileGraph $graph, array $assignment): array
    {
        /** @var array<int, non-empty-list<string>> $members */
        $members = [];
        foreach ($assignment as $node => $community) {
            $members[$community][] = $node;
        }
        ksort($members, SORT_NUMERIC);

        /** @var array<int, int> $sizes */
        $sizes = array_map(static fn (array $nodes): int => count($nodes), $members);

        foreach ($members as $nodes) {
            if (count($nodes) !== 1) {
                continue;
            }

            $node = $nodes[0];
            $sourceCommunity = $assignment[$node];
            if (($sizes[$sourceCommunity] ?? 0) !== 1) {
                continue;
            }

            $weights = [];
            foreach ($graph->neighbours($node) as $neighbour => $weight) {
                $targetCommunity = $assignment[$neighbour];
                if ($targetCommunity === $sourceCommunity) {
                    continue;
                }
                $weights[$targetCommunity] = ($weights[$targetCommunity] ?? 0.0) + $weight;
            }
            if ($weights === []) {
                continue;
            }

            $bestCommunity = null;
            $bestWeight = -1.0;
            ksort($weights, SORT_NUMERIC);
            foreach ($weights as $targetCommunity => $weight) {
                if ($weight > $bestWeight + self::MIN_GAIN) {
                    $bestCommunity = $targetCommunity;
                    $bestWeight = $weight;
                }
            }
            if (!is_int($bestCommunity)) {
                continue;
            }

            --$sizes[$sourceCommunity];
            $sizes[$bestCommunity] = ($sizes[$bestCommunity] ?? 0) + 1;
            $assignment[$node] = $bestCommunity;
        }

        return $assignment;
    }
}
