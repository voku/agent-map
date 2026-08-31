<?php

declare(strict_types=1);

namespace voku\AgentMap\Plan;

/** One preconditioned file move projected by a read-only governed plan. */
final readonly class PlanMove
{
    public function __construct(
        public string $fromPath,
        public string $toPath,
        public string $sourceSha256,
        public string $reason,
        public bool $destinationMustBeAbsent = true,
    ) {
        ProjectRelativePath::assertSafe($fromPath, 'Plan move source');
        ProjectRelativePath::assertSafe($toPath, 'Plan move destination');
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
