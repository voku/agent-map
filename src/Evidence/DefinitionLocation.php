<?php

declare(strict_types=1);

namespace voku\AgentMap\Evidence;

final readonly class DefinitionLocation
{
    public function __construct(
        public string $symbolId,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
    ) {
    }

    /** @return array{symbol_id: string, file: string, line_start: int, line_end: int} */
    public function toArray(): array
    {
        return [
            'symbol_id' => $this->symbolId,
            'file' => $this->file,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
        ];
    }
}
