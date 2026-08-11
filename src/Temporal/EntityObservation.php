<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class EntityObservation
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public string $filePath,
        public string $signature,
        public int $callers,
        public int $callees,
        public int $dependents,
        public int $dependencies,
        public ?string $regionId,
        public ?string $regionLabel,
    ) {
    }

    /**
     * @return array{
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
     * }
     */
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'file_path' => $this->filePath,
            'signature' => $this->signature,
            'callers' => $this->callers,
            'callees' => $this->callees,
            'dependents' => $this->dependents,
            'dependencies' => $this->dependencies,
            'region_id' => $this->regionId,
            'region_label' => $this->regionLabel,
        ];
    }
}
