<?php

declare(strict_types=1);

namespace voku\AgentMap\Plan;

/** One preconditioned whole-file deletion projected by a read-only governed plan. */
final readonly class PlanFileDeletion
{
    public function __construct(
        public string $path,
        public string $sourceSha256,
        public string $reason,
    ) {
        ProjectRelativePath::assertSafe($path, 'Plan file deletion');
    }

    /** @return array{path: string, source_sha256: string, reason: string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'source_sha256' => $this->sourceSha256,
            'reason' => $this->reason,
        ];
    }
}
