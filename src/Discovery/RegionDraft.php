<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class RegionDraft
{
    /**
     * @param 'module'|'subsystem'|'system' $kind
     * @param non-empty-list<string> $files
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public int $level,
        public array $files,
        public RegionEvidence $evidence,
    ) {
    }

    public function withLabel(string $label): self
    {
        return new self($this->id, $label, $this->kind, $this->level, $this->files, $this->evidence);
    }
}
