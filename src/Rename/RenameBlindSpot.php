<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

final readonly class RenameBlindSpot
{
    public function __construct(
        public string $kind,
        public string $message,
        public ?string $path = null,
        public ?int $lineStart = null,
        public ?int $lineEnd = null,
    ) {
    }

    /** @return array{kind: string, message: string, path: ?string, line_start: ?int, line_end: ?int} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'message' => $this->message,
            'path' => $this->path,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
        ];
    }
}
