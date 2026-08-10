<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class ArchitectureRegion
{
    /**
     * @param 'module'|'subsystem'|'system' $kind
     * @param list<string> $files
     * @param list<string> $childIds
     * @param list<string> $interfaceFiles
     * @param list<string> $dominantSignals
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public int $level,
        public array $files,
        public ?string $parentId,
        public array $childIds,
        public float $internalWeight,
        public float $externalWeight,
        public float $boundaryRatio,
        public float $boundaryStrength,
        public float $internalDensity,
        public float $crosscutScore,
        public float $namespaceAgreement,
        public float $directoryAgreement,
        public array $interfaceFiles,
        public array $dominantSignals,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'kind' => $this->kind,
            'level' => $this->level,
            'file_count' => count($this->files),
            'files' => $this->files,
            'parent_id' => $this->parentId,
            'child_ids' => $this->childIds,
            'evidence' => [
                'internal_weight' => round($this->internalWeight, 6),
                'external_weight' => round($this->externalWeight, 6),
                'boundary_ratio' => round($this->boundaryRatio, 6),
                'boundary_strength' => round($this->boundaryStrength, 6),
                'internal_density' => round($this->internalDensity, 6),
                'crosscut_score' => round($this->crosscutScore, 6),
                'namespace_agreement' => round($this->namespaceAgreement, 6),
                'directory_agreement' => round($this->directoryAgreement, 6),
                'interface_files' => $this->interfaceFiles,
                'dominant_signals' => $this->dominantSignals,
            ],
        ];
    }
}
