<?php

declare(strict_types=1);

namespace voku\AgentMap\Context;

final readonly class CodeSlice
{
    /**
     * @param non-empty-list<string> $roles
     * @param non-empty-list<string> $reasons
     * @param list<string> $evidenceIds
     */
    public function __construct(
        public string $path,
        public int $lineStart,
        public int $lineEnd,
        public string $content,
        public array $roles,
        public array $reasons,
        public array $evidenceIds,
        public string $sourceSha256,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'roles' => $this->roles,
            'reasons' => $this->reasons,
            'evidence_ids' => $this->evidenceIds,
            'source_sha256' => $this->sourceSha256,
            'content' => $this->content,
        ];
    }
}
