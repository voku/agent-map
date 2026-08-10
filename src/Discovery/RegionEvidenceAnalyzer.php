<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\FileEntry;

final readonly class RegionEvidenceAnalyzer
{
    /**
     * @param non-empty-list<string> $files
     * @param array<string, CrosscutEvidence> $crosscut
     * @param array<string, FileEntry> $fileEntries
     */
    public function analyze(WeightedFileGraph $graph, array $files, array $crosscut, array $fileEntries): RegionEvidence
    {
        $members = array_fill_keys($files, true);
        $internalWeight = 0.0;
        $externalWeight = 0.0;
        $interfaceFiles = [];
        $signals = [];

        foreach ($files as $file) {
            foreach ($graph->neighbours($file) as $neighbour => $weight) {
                if (isset($members[$neighbour])) {
                    if ($file < $neighbour) {
                        $internalWeight += $weight;
                        foreach ($graph->signalsBetween($file, $neighbour) as $signal => $signalWeight) {
                            $signals[$signal] = ($signals[$signal] ?? 0.0) + $signalWeight;
                        }
                    }
                    continue;
                }
                $externalWeight += $weight;
                $interfaceFiles[$file] = true;
            }
        }

        arsort($signals, SORT_NUMERIC);
        $dominantSignals = array_slice(array_keys($signals), 0, 3);
        $possibleInternalPairs = max(1.0, count($files) * (count($files) - 1) / 2.0);
        $totalBoundaryWeight = $internalWeight + $externalWeight;
        $crosscutScore = 0.0;
        foreach ($files as $file) {
            $crosscutScore += $crosscut[$file]->score ?? 0.0;
        }
        $crosscutScore /= count($files);

        $interfaces = array_keys($interfaceFiles);
        sort($interfaces, SORT_STRING);

        return new RegionEvidence(
            internalWeight: $internalWeight,
            externalWeight: $externalWeight,
            boundaryRatio: $externalWeight <= 0.000001 ? ($internalWeight > 0.0 ? 100.0 : 0.0) : min(100.0, $internalWeight / $externalWeight),
            boundaryStrength: $totalBoundaryWeight <= 0.0 ? 0.0 : $internalWeight / $totalBoundaryWeight,
            internalDensity: $internalWeight / $possibleInternalPairs,
            crosscutScore: $crosscutScore,
            namespaceAgreement: $this->namespaceAgreement($files, $fileEntries),
            directoryAgreement: $this->directoryAgreement($files),
            interfaceFiles: $interfaces,
            dominantSignals: $dominantSignals,
        );
    }

    /** @param non-empty-list<string> $files @param array<string, FileEntry> $fileEntries */
    private function namespaceAgreement(array $files, array $fileEntries): float
    {
        $counts = [];
        foreach ($files as $file) {
            $namespace = trim($fileEntries[$file]->namespace ?? '', '\\');
            if ($namespace !== '') {
                $counts[$namespace] = ($counts[$namespace] ?? 0) + 1;
            }
        }

        return $counts === [] ? 0.0 : max($counts) / count($files);
    }

    /** @param non-empty-list<string> $files */
    private function directoryAgreement(array $files): float
    {
        $counts = [];
        foreach ($files as $file) {
            $directory = dirname(str_replace('\\', '/', $file));
            $counts[$directory] = ($counts[$directory] ?? 0) + 1;
        }

        return max($counts) / count($files);
    }
}
