<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class TemporalClaim
{
    /**
     * @param list<string> $revisions
     * @param array<string, string|int|float|bool|null> $evidence
     */
    public function __construct(
        public string $kind,
        public string $left,
        public string $right,
        public array $revisions,
        public array $evidence,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'left' => $this->left,
            'right' => $this->right,
            'heuristic' => true,
            'revisions' => $this->revisions,
            'evidence' => $this->evidence,
        ];
    }
}
