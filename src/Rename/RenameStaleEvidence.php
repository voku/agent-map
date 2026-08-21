<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

/** A source snapshot mismatch, kept separate from semantic rename blockers. */
final readonly class RenameStaleEvidence
{
    public function __construct(
        public string $path,
        public string $reason,
    ) {
    }

    /** @return array{path: string, reason: string} */
    public function toArray(): array
    {
        return ['path' => $this->path, 'reason' => $this->reason];
    }
}
