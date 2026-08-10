<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class CrosscutAnalyzer
{
    private const PENALTY = 0.4;
    private const REPORT_THRESHOLD = 0.4;
    private const DEGREE_OUTLIER_FACTOR = 1.5;

    /** @var list<string> */
    private const TOKENS = [
        'base', 'common', 'config', 'constant', 'constants', 'core', 'framework',
        'helper', 'helpers', 'log', 'logger', 'logging', 'shared', 'util', 'utils',
    ];

    /** @return array<string, CrosscutEvidence> */
    public function analyze(WeightedFileGraph $graph): array
    {
        $degrees = [];
        foreach ($graph->files as $file) {
            $degrees[$file] = $graph->degree($file);
        }
        $sortedDegrees = array_values($degrees);
        sort($sortedDegrees, SORT_NUMERIC);
        $thresholdIndex = max(0, (int) floor((count($sortedDegrees) - 1) * 0.9));
        $degreeThreshold = $sortedDegrees[$thresholdIndex] ?? 0.0;
        $medianDegree = $sortedDegrees === []
            ? 0.0
            : $sortedDegrees[intdiv(count($sortedDegrees) - 1, 2)];
        $distinctiveThreshold = max($degreeThreshold, $medianDegree * self::DEGREE_OUTLIER_FACTOR);
        $maximumDegree = $sortedDegrees === [] ? 0.0 : max($sortedDegrees);

        $result = [];
        foreach ($graph->files as $file) {
            $signals = [];
            $degree = $degrees[$file];
            $degreeSignal = 0.0;
            if ($maximumDegree > 0.0
                && $degree >= $distinctiveThreshold
                && $degree > $medianDegree
            ) {
                $degreeSignal = $degree / $maximumDegree;
                $signals[] = 'high_degree';
            }

            $tokens = preg_split('/[\\\\\/._-]+/', strtolower($file)) ?: [];
            $lexicalSignal = array_intersect($tokens, self::TOKENS) === [] ? 0.0 : 0.5;
            if ($lexicalSignal > 0.0) {
                $signals[] = 'utility_path';
            }

            $result[$file] = new CrosscutEvidence(
                file: $file,
                score: min(1.0, 0.75 * $degreeSignal + 0.25 * $lexicalSignal),
                signals: $signals,
            );
        }

        return $result;
    }

    /** @param array<string, CrosscutEvidence> $evidence */
    public function penalize(WeightedFileGraph $graph, array $evidence): WeightedFileGraph
    {
        $adjacency = array_fill_keys($graph->files, []);
        foreach ($graph->adjacency as $source => $neighbours) {
            foreach ($neighbours as $target => $weight) {
                if ($source >= $target) {
                    continue;
                }
                $average = (($evidence[$source]->score ?? 0.0) + ($evidence[$target]->score ?? 0.0)) / 2.0;
                $adjusted = $weight * (1.0 - self::PENALTY * $average);
                $adjacency[$source][$target] = $adjusted;
                $adjacency[$target][$source] = $adjusted;
            }
        }

        return new WeightedFileGraph($graph->files, $adjacency, []);
    }

    /** @param array<string, CrosscutEvidence> $evidence @return list<array{file: string, score: float, signals: list<string>}> */
    public function reported(array $evidence): array
    {
        $rows = [];
        foreach ($evidence as $item) {
            if ($item->score >= self::REPORT_THRESHOLD) {
                $rows[] = $item->toArray();
            }
        }
        usort($rows, static fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['file'] <=> $right['file']);

        return $rows;
    }
}
