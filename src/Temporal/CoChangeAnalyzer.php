<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

use voku\AgentMap\Discovery\FileCouplingGraphBuilder;
use voku\AgentMap\Discovery\WeightedFileGraph;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;

final readonly class CoChangeAnalyzer
{
    /**
     * @param list<list<string>> $commits
     */
    public function analyze(
        AgentMapIndex $map,
        array $commits,
        int $minimumCoChanges = 2,
        int $top = 20,
        int $maximumFilesPerCommit = 100,
    ): CoChangeReport {
        $indexed = array_fill_keys(
            array_map(static fn (FileEntry $file): string => $file->path, $map->files),
            true,
        );
        $changes = [];
        $pairs = [];
        $analyzed = 0;
        $bulkSkipped = 0;

        foreach ($commits as $commitFiles) {
            $files = [];
            foreach ($commitFiles as $path) {
                if (isset($indexed[$path])) {
                    $files[$path] = true;
                }
            }
            $files = array_keys($files);
            sort($files, SORT_STRING);
            if ($files === []) {
                continue;
            }
            if (count($files) > $maximumFilesPerCommit) {
                ++$bulkSkipped;
                continue;
            }

            ++$analyzed;
            foreach ($files as $file) {
                $changes[$file] = ($changes[$file] ?? 0) + 1;
            }
            for ($leftIndex = 0, $count = count($files); $leftIndex < $count; ++$leftIndex) {
                for ($rightIndex = $leftIndex + 1; $rightIndex < $count; ++$rightIndex) {
                    $left = $files[$leftIndex];
                    $right = $files[$rightIndex];
                    $key = WeightedFileGraph::pairKey($left, $right);
                    $pairs[$key] ??= ['left' => $left, 'right' => $right, 'count' => 0];
                    ++$pairs[$key]['count'];
                }
            }
        }

        $graph = (new FileCouplingGraphBuilder())->build($map);
        $rows = [];
        foreach ($pairs as $pair) {
            $coChanges = $pair['count'];
            if ($coChanges < $minimumCoChanges) {
                continue;
            }
            $leftChanges = $changes[$pair['left']] ?? 0;
            $rightChanges = $changes[$pair['right']] ?? 0;
            $union = $leftChanges + $rightChanges - $coChanges;
            $smaller = min($leftChanges, $rightChanges);
            $signals = $graph->signalsBetween($pair['left'], $pair['right']);
            $pathWeight = $signals['path'] ?? 0.0;
            $semanticWeight = array_sum($signals) - $pathWeight;

            $rows[] = new CoChangePair(
                left: $pair['left'],
                right: $pair['right'],
                coChanges: $coChanges,
                leftChanges: $leftChanges,
                rightChanges: $rightChanges,
                jaccard: $union > 0 ? round($coChanges / $union, 6) : 0.0,
                smallerSideRatio: $smaller > 0 ? round($coChanges / $smaller, 6) : 0.0,
                semanticStaticWeight: round(max(0.0, $semanticWeight), 6),
                pathStaticWeight: round($pathWeight, 6),
                staticSignals: $signals,
            );
        }

        usort($rows, static function (CoChangePair $left, CoChangePair $right): int {
            return $right->coChanges <=> $left->coChanges
                ?: $right->smallerSideRatio <=> $left->smallerSideRatio
                ?: $right->jaccard <=> $left->jaccard
                ?: $left->left <=> $right->left
                ?: $left->right <=> $right->right;
        });

        return new CoChangeReport($analyzed, $bulkSkipped, array_slice($rows, 0, max(1, $top)));
    }
}
