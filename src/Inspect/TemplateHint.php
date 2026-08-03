<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class TemplateHint
{
    public function __construct(
        public string $path,
        public int $line,
    ) {
    }

    /**
     * @return array{path: string, line: int}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'line' => $this->line,
        ];
    }
}
