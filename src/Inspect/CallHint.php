<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class CallHint
{
    /**
     * @param list<string> $targetIds
     */
    public function __construct(
        public string $kind,
        public string $label,
        public int $line,
        public array $targetIds = [],
        public string $resolution = 'structural_only',
        public ?string $receiverType = null,
        public ?string $resultType = null,
    ) {
    }

    /**
     * @return array{kind: string, label: string, line: int, target_ids: list<string>, resolution: string, receiver_type: ?string, result_type: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'label' => $this->label,
            'line' => $this->line,
            'target_ids' => $this->targetIds,
            'resolution' => $this->resolution,
            'receiver_type' => $this->receiverType,
            'result_type' => $this->resultType,
        ];
    }
}
