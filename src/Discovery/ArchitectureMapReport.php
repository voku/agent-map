<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

use InvalidArgumentException;

final readonly class ArchitectureMapReport
{
    /**
     * @param list<ArchitectureRegion> $regions
     * @param list<string> $rootRegionIds
     * @param list<array{file: string, score: float, signals: list<string>}> $crosscutFiles
     * @param list<string> $unassignedFiles
     */
    public function __construct(
        public string $mapDigest,
        public array $regions,
        public array $rootRegionIds,
        public array $crosscutFiles,
        public array $unassignedFiles,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'map_digest' => $this->mapDigest,
            'region_count' => count($this->regions),
            'levels' => $this->levels(),
            'root_region_ids' => $this->rootRegionIds,
            'regions' => array_map(static fn (ArchitectureRegion $region): array => $region->toArray(), $this->regions),
            'crosscut_files' => $this->crosscutFiles,
            'unassigned_files' => $this->unassignedFiles,
        ];
    }

    public function levels(): int
    {
        $maximum = 0;
        foreach ($this->regions as $region) {
            $maximum = max($maximum, $region->level);
        }

        return $maximum;
    }

    public function regionForFile(string $file, ?int $level = null): ?ArchitectureRegion
    {
        $matches = [];
        foreach ($this->regions as $region) {
            if (($level === null || $region->level === $level) && in_array($file, $region->files, true)) {
                $matches[] = $region;
            }
        }
        usort($matches, static fn (ArchitectureRegion $left, ArchitectureRegion $right): int => $left->level <=> $right->level);

        return $matches[0] ?? null;
    }

    /** @return list<ArchitectureRegion> Finest region first, repository root last. */
    public function pathForFile(string $file): array
    {
        $region = $this->regionForFile($file);

        return $region === null ? [] : $this->pathForRegion($region);
    }

    /** @return non-empty-list<ArchitectureRegion> Selected region first, repository root last. */
    public function pathForRegion(ArchitectureRegion $region): array
    {
        $byId = [];
        foreach ($this->regions as $candidate) {
            $byId[$candidate->id] = $candidate;
        }

        $path = [$region];
        while ($region->parentId !== null && isset($byId[$region->parentId])) {
            $region = $byId[$region->parentId];
            $path[] = $region;
        }

        return $path;
    }

    public function resolveRegion(string $query): ArchitectureRegion
    {
        $query = trim($query);
        if ($query === '') {
            throw new InvalidArgumentException('Region query must not be empty.');
        }

        $exact = [];
        $prefix = [];
        foreach ($this->regions as $region) {
            if ($region->id === $query || strcasecmp($region->label, $query) === 0) {
                $exact[$region->id] = $region;
                continue;
            }
            if (str_starts_with($region->id, $query)) {
                $prefix[$region->id] = $region;
            }
        }

        $matches = $exact !== [] ? $exact : $prefix;
        if ($matches === []) {
            throw new InvalidArgumentException('No architecture region matches: ' . $query);
        }
        if (count($matches) > 1) {
            throw new InvalidArgumentException('Architecture region query is ambiguous: ' . $query);
        }

        return array_values($matches)[0];
    }
}
