<?php

declare(strict_types=1);

namespace voku\AgentMap\Rename;

/** One preconditioned file move projected by a read-only rename plan. */
final readonly class RenameMove
{
    public function __construct(
        public string $fromPath,
        public string $toPath,
        public string $sourceSha256,
        public string $reason,
        public bool $destinationMustBeAbsent = true,
    ) {
    }

    /** @return array{from_path: string, to_path: string, source_sha256: string, destination_must_be_absent: bool, reason: string} */
    public function toArray(): array
    {
        return [
            'from_path' => $this->fromPath,
            'to_path' => $this->toPath,
            'source_sha256' => $this->sourceSha256,
            'destination_must_be_absent' => $this->destinationMustBeAbsent,
            'reason' => $this->reason,
        ];
    }
}
