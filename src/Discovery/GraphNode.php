<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class GraphNode
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
    ) {
    }

    /**
     * @return array{id: string, kind: string, name: string, file: string, line_start: int, line_end: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->name,
            'file' => $this->file,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
        ];
    }
}
