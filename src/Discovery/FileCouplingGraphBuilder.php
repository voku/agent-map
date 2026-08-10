<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;

final readonly class FileCouplingGraphBuilder
{
    /** @var array<string, float> */
    private const RELATION_WEIGHTS = [
        'calls' => 1.0,
        'instantiates' => 0.95,
        'extends' => 0.9,
        'implements' => 0.9,
        'uses_trait' => 0.9,
        'overrides' => 0.85,
        'references_type' => 0.7,
    ];

    private const UNCERTAIN_FACTOR = 0.5;
    private const PATH_WEIGHT = 0.25;
    private const PATH_CLIQUE_LIMIT = 20;
    private const PATH_NEIGHBOURS = 2;

    public function build(AgentMapIndex $map): WeightedFileGraph
    {
        $catalog = new GraphNodeCatalog($map);
        $files = array_map(static fn ($file): string => $file->path, $map->files);
        sort($files, SORT_STRING);
        $fileSet = array_fill_keys($files, true);

        /** @var array<string, array{left: string, right: string, counts: array<string, float>}> $pairs */
        $pairs = [];
        foreach ($map->relations as $relation) {
            $baseWeight = self::RELATION_WEIGHTS[$relation->kind] ?? null;
            if ($baseWeight === null) {
                continue;
            }

            $source = $catalog->find($relation->sourceId);
            $sourceFile = $source?->file ?? $relation->file;
            if (!isset($fileSet[$sourceFile])) {
                continue;
            }

            foreach ($relation->targetIds as $targetId) {
                $target = $catalog->find($targetId);
                if ($target === null || $target->file === $sourceFile || !isset($fileSet[$target->file])) {
                    continue;
                }

                [$left, $right] = $this->orderedPair($sourceFile, $target->file);
                $key = WeightedFileGraph::pairKey($left, $right);
                $pairs[$key] ??= ['left' => $left, 'right' => $right, 'counts' => []];
                $factor = $this->isUncertain($relation) ? self::UNCERTAIN_FACTOR : 1.0;
                $pairs[$key]['counts'][$relation->kind] = ($pairs[$key]['counts'][$relation->kind] ?? 0.0) + $factor;
            }
        }

        /** @var array<string, array<string, float>> $adjacency */
        $adjacency = array_fill_keys($files, []);
        /** @var array<string, array<string, float>> $signalsByPair */
        $signalsByPair = [];

        foreach ($pairs as $key => $pair) {
            $signals = [];
            $weight = 0.0;
            foreach ($pair['counts'] as $kind => $effectiveCount) {
                $contribution = self::RELATION_WEIGHTS[$kind] * log1p($effectiveCount);
                $signals[$kind] = $contribution;
                $weight += $contribution;
            }

            $pathContribution = self::PATH_WEIGHT * $this->pathProximity($pair['left'], $pair['right']);
            if ($pathContribution > 0.0) {
                $signals['path'] = $pathContribution;
                $weight += $pathContribution;
            }

            $this->addEdge($adjacency, $pair['left'], $pair['right'], $weight);
            arsort($signals, SORT_NUMERIC);
            $signalsByPair[$key] = $signals;
        }

        $this->addBoundedPathPrior($files, $adjacency, $signalsByPair);

        foreach ($adjacency as &$neighbours) {
            ksort($neighbours, SORT_STRING);
        }
        unset($neighbours);
        ksort($signalsByPair, SORT_STRING);

        return new WeightedFileGraph($files, $adjacency, $signalsByPair);
    }

    /**
     * Directory proximity is deliberately weaker than semantic evidence. Small directories form
     * a clique, matching the useful part of Ix's structural prior. Large legacy directories are
     * connected only to nearby sorted siblings so one flat folder cannot create O(n²) edges.
     *
     * @param list<string> $files
     * @param array<string, array<string, float>> $adjacency
     * @param array<string, array<string, float>> $signalsByPair
     */
    private function addBoundedPathPrior(array $files, array &$adjacency, array &$signalsByPair): void
    {
        $byDirectory = [];
        foreach ($files as $file) {
            $directory = dirname(str_replace('\\', '/', $file));
            $byDirectory[$directory][] = $file;
        }

        foreach ($byDirectory as $directoryFiles) {
            $count = count($directoryFiles);
            if ($count < 2) {
                continue;
            }
            sort($directoryFiles, SORT_STRING);

            if ($count <= self::PATH_CLIQUE_LIMIT) {
                for ($leftIndex = 0; $leftIndex < $count; ++$leftIndex) {
                    for ($rightIndex = $leftIndex + 1; $rightIndex < $count; ++$rightIndex) {
                        $this->addPathOnlyEdge($directoryFiles[$leftIndex], $directoryFiles[$rightIndex], $adjacency, $signalsByPair);
                    }
                }
                continue;
            }

            for ($leftIndex = 0; $leftIndex < $count; ++$leftIndex) {
                for ($offset = 1; $offset <= self::PATH_NEIGHBOURS; ++$offset) {
                    $rightIndex = $leftIndex + $offset;
                    if ($rightIndex >= $count) {
                        break;
                    }
                    $this->addPathOnlyEdge($directoryFiles[$leftIndex], $directoryFiles[$rightIndex], $adjacency, $signalsByPair);
                }
            }
        }
    }

    /**
     * @param array<string, array<string, float>> $adjacency
     * @param array<string, array<string, float>> $signalsByPair
     */
    private function addPathOnlyEdge(string $left, string $right, array &$adjacency, array &$signalsByPair): void
    {
        if (($adjacency[$left][$right] ?? 0.0) > 0.0) {
            return;
        }

        $weight = self::PATH_WEIGHT * $this->pathProximity($left, $right);
        if ($weight <= 0.0) {
            return;
        }
        $this->addEdge($adjacency, $left, $right, $weight);
        $signalsByPair[WeightedFileGraph::pairKey($left, $right)] = ['path' => $weight];
    }

    /** @param array<string, array<string, float>> $adjacency */
    private function addEdge(array &$adjacency, string $left, string $right, float $weight): void
    {
        if ($weight <= 0.0) {
            return;
        }
        $adjacency[$left][$right] = $weight;
        $adjacency[$right][$left] = $weight;
    }

    private function pathProximity(string $left, string $right): float
    {
        $leftParts = $this->directoryParts($left);
        $rightParts = $this->directoryParts($right);
        $common = 0;
        $maximumCommon = min(count($leftParts), count($rightParts));
        while ($common < $maximumCommon && $leftParts[$common] === $rightParts[$common]) {
            ++$common;
        }
        $distance = (count($leftParts) - $common) + (count($rightParts) - $common);

        return 1.0 / (1.0 + $distance);
    }

    /** @return list<string> */
    private function directoryParts(string $file): array
    {
        $directory = dirname(str_replace('\\', '/', $file));
        if ($directory === '.') {
            return [];
        }

        return array_values(array_filter(explode('/', $directory), static fn (string $part): bool => $part !== ''));
    }

    /** @return array{string, string} */
    private function orderedPair(string $left, string $right): array
    {
        return $left < $right ? [$left, $right] : [$right, $left];
    }

    private function isUncertain(RelationEntry $relation): bool
    {
        return in_array($relation->resolution, ['dynamic', 'multiple_targets'], true);
    }
}
