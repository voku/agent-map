<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class EntityHistoryReport
{
    /**
     * @param list<array{
     *   snapshot_id: int,
     *   revision: string,
     *   committed_at: string,
     *   map_digest: string,
     *   entity_type: string,
     *   entity_id: string,
     *   file_path: string,
     *   signature: string,
     *   callers: int,
     *   callees: int,
     *   dependents: int,
     *   dependencies: int,
     *   region_id: ?string,
     *   region_label: ?string
     * }> $observations
     * @param array{snapshot_id: int, revision: string, committed_at: string, map_digest: string}|null $latestSnapshot
     */
    public function __construct(
        public string $entityId,
        public array $observations,
        public ?array $latestSnapshot,
    ) {
    }

    public function status(): string
    {
        if ($this->observations === []) {
            return 'never_observed';
        }
        $latestObservation = $this->observations[array_key_last($this->observations)];
        if ($this->latestSnapshot === null || $latestObservation['snapshot_id'] !== $this->latestSnapshot['snapshot_id']) {
            return 'absent_from_latest';
        }

        return 'present';
    }

    /** @return array<string, int> */
    public function metricDelta(): array
    {
        if ($this->observations === []) {
            return ['callers' => 0, 'callees' => 0, 'dependents' => 0, 'dependencies' => 0];
        }
        $first = $this->observations[0];
        $last = $this->observations[array_key_last($this->observations)];

        return [
            'callers' => $last['callers'] - $first['callers'],
            'callees' => $last['callees'] - $first['callees'],
            'dependents' => $last['dependents'] - $first['dependents'],
            'dependencies' => $last['dependencies'] - $first['dependencies'],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entity_id' => $this->entityId,
            'status' => $this->status(),
            'observation_count' => count($this->observations),
            'signature_variants' => $this->variantCount('signature'),
            'path_variants' => $this->variantCount('file_path'),
            'region_variants' => $this->variantCount('region_id'),
            'metric_delta' => $this->metricDelta(),
            'latest_snapshot' => $this->latestSnapshot,
            'observations' => $this->observations,
        ];
    }

    private function variantCount(string $key): int
    {
        $values = [];
        foreach ($this->observations as $observation) {
            $value = $observation[$key] ?? null;
            $values[is_string($value) ? $value : 'null'] = true;
        }

        return count($values);
    }
}
