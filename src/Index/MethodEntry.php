<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class MethodEntry
{
    public function __construct(
        public string $name,
        public string $visibility,
        public int $lineStart,
    ) {
    }

    /**
     * @return array{name: string, visibility: string, line_start: int}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'visibility' => $this->visibility,
            'line_start' => $this->lineStart,
        ];
    }

    /**
     * @param array{name?: mixed, visibility?: mixed, line_start?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['visibility'] ?? 'public'),
            (int) ($data['line_start'] ?? 0),
        );
    }
}
