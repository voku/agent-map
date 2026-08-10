<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;

final readonly class ArchitectureMapBuilder
{
    private const CROSSCUT_PENALTY = 0.4;
    private const CROSSCUT_REPORT_THRESHOLD = 0.4;

    /** @var list<string> */
    private const CROSSCUT_TOKENS = [
        'base', 'common', 'config', 'constant', 'constants', 'core', 'framework',
        'helper', 'helpers', 'log', 'logger', 'logging', 'shared', 'util', 'utils',
    ];

    /** @var list<string> */
    private const LABEL_NOISE = [
        'app', 'application', 'impl', 'implementation', 'internal', 'lib', 'main',
        'php', 'src', 'test', 'tests', 'vendor',
    ];

    public function __construct(
        private FileCouplingGraphBuilder $graphBuilder = new FileCouplingGraphBuilder(),
        private DeterministicCommunityDetector $communityDetector = new DeterministicCommunityDetector(),
    ) {
    }

    public function build(AgentMapIndex $map): ArchitectureMapReport
    {
        $rawGraph = $this->graphBuilder->build($map);
        $crosscut = $this->crosscutScores($rawGraph);
        $clusteringGraph = $this->applyCrosscutPenalty($rawGraph, $crosscut);
        $partitions = $this->communityDetector->cluster($clusteringGraph);
        $fileEntries = [];
        foreach ($map->files as $file) {
            $fileEntries[$file->path] = $file;
        }

        $drafts = $this->regionDrafts($rawGraph, $partitions, $crosscut, $fileEntries);
        $this->disambiguateLabels($drafts);
        [$parents, $children] = $this->hierarchy($drafts);

        $regions = [];
        foreach ($drafts as $id => $draft) {
            $regions[] = new ArchitectureRegion(
                id: $id,
                label: $draft['label'],
                kind: $draft['kind'],
                level: $draft['level'],
                files: $draft['files'],
                parentId: $parents[$id] ?? null,
                childIds: $children[$id] ?? [],
                internalWeight: $draft['internal_weight'],
                externalWeight: $draft['external_weight'],
                boundaryRatio: $draft['boundary_ratio'],
                boundaryStrength: $draft['boundary_strength'],
                internalDensity: $draft['internal_density'],
                crosscutScore: $draft['crosscut_score'],
                namespaceAgreement: $draft['namespace_agreement'],
                directoryAgreement: $draft['directory_agreement'],
                interfaceFiles: $draft['interface_files'],
                dominantSignals: $draft['dominant_signals'],
            );
        }
        usort($regions, static fn (ArchitectureRegion $left, ArchitectureRegion $right): int => $right->level <=> $left->level ?: $left->label <=> $right->label ?: $left->id <=> $right->id);

        $rootRegionIds = [];
        foreach ($regions as $region) {
            if ($region->parentId === null) {
                $rootRegionIds[] = $region->id;
            }
        }
        sort($rootRegionIds, SORT_STRING);

        $assigned = [];
        foreach ($regions as $region) {
            if ($region->level !== 1 && $this->maximumLevel($regions) > 1) {
                continue;
            }
            foreach ($region->files as $file) {
                $assigned[$file] = true;
            }
        }
        $unassigned = array_values(array_filter($rawGraph->files, static fn (string $file): bool => !isset($assigned[$file])));
        sort($unassigned, SORT_STRING);

        return new ArchitectureMapReport(
            mapDigest: $map->mapDigest(),
            regions: $regions,
            rootRegionIds: $rootRegionIds,
            crosscutFiles: $this->reportedCrosscutFiles($crosscut),
            unassignedFiles: $unassigned,
        );
    }

    /**
     * @return array<string, array{score: float, signals: list<string>}>
     */
    private function crosscutScores(WeightedFileGraph $graph): array
    {
        $degrees = [];
        foreach ($graph->files as $file) {
            $degrees[$file] = $graph->degree($file);
        }
        $sortedDegrees = array_values($degrees);
        sort($sortedDegrees, SORT_NUMERIC);
        $thresholdIndex = max(0, (int) floor((count($sortedDegrees) - 1) * 0.9));
        $degreeThreshold = $sortedDegrees[$thresholdIndex] ?? 0.0;
        $maximumDegree = $sortedDegrees === [] ? 0.0 : max($sortedDegrees);

        $scores = [];
        foreach ($graph->files as $file) {
            $signals = [];
            $degree = $degrees[$file];
            $degreeSignal = 0.0;
            if ($maximumDegree > 0.0 && $degree >= $degreeThreshold && $degree > 0.0) {
                $degreeSignal = $degree / $maximumDegree;
                $signals[] = 'high_degree';
            }

            $tokens = preg_split('/[\\\\\/._-]+/', strtolower($file)) ?: [];
            $lexicalSignal = array_intersect($tokens, self::CROSSCUT_TOKENS) === [] ? 0.0 : 0.5;
            if ($lexicalSignal > 0.0) {
                $signals[] = 'utility_path';
            }

            $scores[$file] = [
                'score' => min(1.0, 0.75 * $degreeSignal + 0.25 * $lexicalSignal),
                'signals' => $signals,
            ];
        }

        return $scores;
    }

    /** @param array<string, array{score: float, signals: list<string>}> $crosscut */
    private function applyCrosscutPenalty(WeightedFileGraph $graph, array $crosscut): WeightedFileGraph
    {
        $adjacency = array_fill_keys($graph->files, []);
        foreach ($graph->adjacency as $source => $neighbours) {
            foreach ($neighbours as $target => $weight) {
                if ($source >= $target) {
                    continue;
                }
                $average = (($crosscut[$source]['score'] ?? 0.0) + ($crosscut[$target]['score'] ?? 0.0)) / 2.0;
                $adjusted = $weight * (1.0 - self::CROSSCUT_PENALTY * $average);
                $adjacency[$source][$target] = $adjusted;
                $adjacency[$target][$source] = $adjusted;
            }
        }

        return new WeightedFileGraph($graph->files, $adjacency, []);
    }

    /**
     * @param list<CommunityPartition> $partitions
     * @param array<string, array{score: float, signals: list<string>}> $crosscut
     * @param array<string, FileEntry> $fileEntries
     * @return array<string, array{label: string, kind: 'module'|'subsystem'|'system', level: int, files: list<string>, internal_weight: float, external_weight: float, boundary_ratio: float, boundary_strength: float, internal_density: float, crosscut_score: float, namespace_agreement: float, directory_agreement: float, interface_files: list<string>, dominant_signals: list<string>}>
     */
    private function regionDrafts(WeightedFileGraph $graph, array $partitions, array $crosscut, array $fileEntries): array
    {
        $drafts = [];
        $maximumLevel = count($partitions);
        foreach ($partitions as $levelIndex => $partition) {
            $level = $levelIndex + 1;
            foreach ($partition->communities as $files) {
                if (count($files) < 2) {
                    continue;
                }
                $id = 'region:' . hash('sha256', $level . "\0" . implode("\0", $files));
                $metrics = $this->regionMetrics($graph, $files, $crosscut, $fileEntries);
                $drafts[$id] = [
                    'label' => $this->label($files, $fileEntries),
                    'kind' => $this->regionKind($level, $maximumLevel),
                    'level' => $level,
                    'files' => $files,
                    ...$metrics,
                ];
            }
        }

        return $drafts;
    }

    /**
     * @param list<string> $files
     * @param array<string, array{score: float, signals: list<string>}> $crosscut
     * @param array<string, FileEntry> $fileEntries
     * @return array{internal_weight: float, external_weight: float, boundary_ratio: float, boundary_strength: float, internal_density: float, crosscut_score: float, namespace_agreement: float, directory_agreement: float, interface_files: list<string>, dominant_signals: list<string>}
     */
    private function regionMetrics(WeightedFileGraph $graph, array $files, array $crosscut, array $fileEntries): array
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
            $crosscutScore += $crosscut[$file]['score'] ?? 0.0;
        }
        $crosscutScore /= max(1, count($files));

        $interfaces = array_keys($interfaceFiles);
        sort($interfaces, SORT_STRING);

        return [
            'internal_weight' => $internalWeight,
            'external_weight' => $externalWeight,
            'boundary_ratio' => $externalWeight <= 0.000001 ? ($internalWeight > 0.0 ? 100.0 : 0.0) : min(100.0, $internalWeight / $externalWeight),
            'boundary_strength' => $totalBoundaryWeight <= 0.0 ? 0.0 : $internalWeight / $totalBoundaryWeight,
            'internal_density' => $internalWeight / $possibleInternalPairs,
            'crosscut_score' => $crosscutScore,
            'namespace_agreement' => $this->namespaceAgreement($files, $fileEntries),
            'directory_agreement' => $this->directoryAgreement($files),
            'interface_files' => $interfaces,
            'dominant_signals' => $dominantSignals,
        ];
    }

    /** @param list<string> $files @param array<string, FileEntry> $fileEntries */
    private function label(array $files, array $fileEntries): string
    {
        $namespaces = [];
        foreach ($files as $file) {
            $namespace = trim($fileEntries[$file]->namespace ?? '', '\\');
            if ($namespace !== '') {
                $namespaces[$namespace] = ($namespaces[$namespace] ?? 0) + 1;
            }
        }
        if ($namespaces !== []) {
            arsort($namespaces, SORT_NUMERIC);
            $namespace = (string) array_key_first($namespaces);
            if ($namespaces[$namespace] / count($files) >= 0.6) {
                $parts = explode('\\', $namespace);
                $candidate = (string) end($parts);
                if (!$this->isNoise($candidate)) {
                    return $candidate;
                }
            }
        }

        $directories = array_map(static fn (string $file): array => explode('/', dirname(str_replace('\\', '/', $file))), $files);
        $common = $this->commonPrefix($directories);
        for ($index = count($common) - 1; $index >= 0; --$index) {
            if (!$this->isNoise($common[$index])) {
                return $this->humanize($common[$index]);
            }
        }

        $scores = [];
        foreach ($directories as $segments) {
            $maximumDepth = max(1, count($segments) - 1);
            foreach ($segments as $depth => $segment) {
                if ($segment === '.' || $this->isNoise($segment)) {
                    continue;
                }
                $scores[$segment] = ($scores[$segment] ?? 0.0) + 1.0 + $depth / $maximumDepth;
            }
        }
        if ($scores !== []) {
            arsort($scores, SORT_NUMERIC);
            return $this->humanize((string) array_key_first($scores));
        }

        $tokens = [];
        foreach ($files as $file) {
            $stem = pathinfo($file, PATHINFO_FILENAME);
            foreach (preg_split('/(?<=[a-z])(?=[A-Z])|[_-]+/', $stem) ?: [] as $token) {
                if (strlen($token) >= 3 && !$this->isNoise($token)) {
                    $tokens[$token] = ($tokens[$token] ?? 0) + 1;
                }
            }
        }
        if ($tokens !== []) {
            arsort($tokens, SORT_NUMERIC);
            return $this->humanize((string) array_key_first($tokens));
        }

        return 'Region';
    }

    /** @param array<string, array{label: string, kind: 'module'|'subsystem'|'system', level: int, files: list<string>, internal_weight: float, external_weight: float, boundary_ratio: float, boundary_strength: float, internal_density: float, crosscut_score: float, namespace_agreement: float, directory_agreement: float, interface_files: list<string>, dominant_signals: list<string>}> $drafts */
    private function disambiguateLabels(array &$drafts): void
    {
        $groups = [];
        foreach ($drafts as $id => $draft) {
            $groups[$draft['level'] . "\0" . strtolower($draft['label'])][] = $id;
        }
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $directory = basename(dirname($drafts[$id]['files'][0]));
                $suffix = !$this->isNoise($directory) && strcasecmp($directory, $drafts[$id]['label']) !== 0
                    ? $this->humanize($directory)
                    : substr(hash('sha256', implode("\0", $drafts[$id]['files'])), 0, 6);
                $drafts[$id]['label'] .= ' / ' . $suffix;
            }
        }
    }

    /**
     * @param array<string, array{label: string, kind: 'module'|'subsystem'|'system', level: int, files: list<string>, internal_weight: float, external_weight: float, boundary_ratio: float, boundary_strength: float, internal_density: float, crosscut_score: float, namespace_agreement: float, directory_agreement: float, interface_files: list<string>, dominant_signals: list<string>}> $drafts
     * @return array{array<string, string>, array<string, list<string>>}
     */
    private function hierarchy(array $drafts): array
    {
        $parents = [];
        foreach ($drafts as $id => $draft) {
            $candidates = [];
            foreach ($drafts as $candidateId => $candidate) {
                if ($candidate['level'] <= $draft['level'] || count($candidate['files']) <= count($draft['files'])) {
                    continue;
                }
                if ($this->containsAll($candidate['files'], $draft['files'])) {
                    $candidates[$candidateId] = count($candidate['files']);
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

    /** @param list<string> $container @param list<string> $contained */
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

    /** @param list<string> $files @param array<string, FileEntry> $fileEntries */
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

    /** @param list<string> $files */
    private function directoryAgreement(array $files): float
    {
        $counts = [];
        foreach ($files as $file) {
            $directory = dirname(str_replace('\\', '/', $file));
            $counts[$directory] = ($counts[$directory] ?? 0) + 1;
        }

        return $counts === [] ? 0.0 : max($counts) / count($files);
    }

    /** @param list<list<string>> $paths @return list<string> */
    private function commonPrefix(array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $prefix = $paths[0];
        foreach (array_slice($paths, 1) as $path) {
            $maximum = min(count($prefix), count($path));
            $length = 0;
            while ($length < $maximum && $prefix[$length] === $path[$length]) {
                ++$length;
            }
            $prefix = array_slice($prefix, 0, $length);
        }

        return $prefix;
    }

    /** @return 'module'|'subsystem'|'system' */
    private function regionKind(int $level, int $maximumLevel): string
    {
        if ($maximumLevel <= 1 || $level === $maximumLevel) {
            return 'system';
        }

        return $level === 1 ? 'module' : 'subsystem';
    }

    private function isNoise(string $token): bool
    {
        return $token === '' || in_array(strtolower($token), self::LABEL_NOISE, true);
    }

    private function humanize(string $token): string
    {
        $token = (string) preg_replace('/[_-]+/', ' ', $token);
        return ucwords($token);
    }

    /** @param array<string, array{score: float, signals: list<string>}> $crosscut @return list<array{file: string, score: float, signals: list<string>}> */
    private function reportedCrosscutFiles(array $crosscut): array
    {
        $rows = [];
        foreach ($crosscut as $file => $data) {
            if ($data['score'] >= self::CROSSCUT_REPORT_THRESHOLD) {
                $rows[] = ['file' => $file, 'score' => round($data['score'], 6), 'signals' => $data['signals']];
            }
        }
        usort($rows, static fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['file'] <=> $right['file']);

        return $rows;
    }

    /** @param list<ArchitectureRegion> $regions */
    private function maximumLevel(array $regions): int
    {
        $maximum = 0;
        foreach ($regions as $region) {
            $maximum = max($maximum, $region->level);
        }
        return $maximum;
    }
}
