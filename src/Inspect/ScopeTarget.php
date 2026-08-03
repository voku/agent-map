<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class ScopeTarget
{
    public function __construct(
        public string $kind,
        public string $label,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
        public ?string $sourceId = null,
    ) {
    }

    /**
     * @return array{kind: string, label: string, file: string, line_start: int, line_end: int, source_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'label' => $this->label,
            'file' => $this->file,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'source_id' => $this->sourceId,
        ];
    }
}
