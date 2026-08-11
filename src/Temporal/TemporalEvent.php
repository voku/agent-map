<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class TemporalEvent
{
    /**
     * @param array<string, string|int|float|bool|null> $before
     * @param array<string, string|int|float|bool|null> $after
     */
    public function __construct(
        public string $kind,
        public string $entityType,
        public string $entityId,
        public array $before = [],
        public array $after = [],
    ) {
    }

    /**
     * @return array{
     *     kind: string,
     *     entity_type: string,
     *     entity_id: string,
     *     before: array<string, string|int|float|bool|null>,
     *     after: array<string, string|int|float|bool|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'before' => $this->before,
            'after' => $this->after,
        ];
    }
}
