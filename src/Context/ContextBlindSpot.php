<?php

declare(strict_types=1);

namespace voku\AgentMap\Context;

final readonly class ContextBlindSpot
{
    /** @param list<string> $evidenceIds */
    public function __construct(
        public string $kind,
        public string $message,
        public ?string $path = null,
        public ?int $line = null,
        public array $evidenceIds = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'message' => $this->message, 'path' => $this->path, 'line' => $this->line, 'evidence_ids' => $this->evidenceIds];
    }
}
