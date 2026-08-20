<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

final readonly class RenameEdit
{
    public function __construct(
        public string $path,
        public string $sourceSha256,
        public int $startFilePos,
        public int $endFilePos,
        public int $lineStart,
        public int $lineEnd,
        public string $expected,
        public string $replacement,
        public string $role,
        public string $symbolId,
        public string $resolution,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'source_sha256' => $this->sourceSha256,
            'start_file_pos' => $this->startFilePos,
            'end_file_pos' => $this->endFilePos,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'expected' => $this->expected,
            'replacement' => $this->replacement,
            'role' => $this->role,
            'symbol_id' => $this->symbolId,
            'resolution' => $this->resolution,
        ];
    }
}
