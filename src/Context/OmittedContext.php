<?php

declare(strict_types=1);

namespace voku\AgentMap\Context;

final readonly class OmittedContext
{
    public function __construct(
        public string $symbolId,
        public string $role,
        public string $reason,
    ) {
    }

    /** @return array{symbol_id: string, role: string, reason: string} */
    public function toArray(): array
    {
        return ['symbol_id' => $this->symbolId, 'role' => $this->role, 'reason' => $this->reason];
    }
}
