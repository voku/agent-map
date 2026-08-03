<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class TableHint
{
    public function __construct(
        public string $table,
        public string $action,
        public int $line,
    ) {
    }

    /**
     * @return array{table: string, action: string, line: int}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'action' => $this->action,
            'line' => $this->line,
        ];
    }
}
