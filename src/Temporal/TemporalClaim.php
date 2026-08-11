<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class TemporalClaim
{
    /** @param array<string, string|int|float|bool|null> $evidence */
    public function __construct(
        public string $kind,
        public string $left,
        public string $right,
        public array $evidence,
    ) {
    }

    /** @return array{kind: string, left: string, right: string, heuristic: true, evidence: array<string, string|int|float|bool|null>} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'left' => $this->left,
            'right' => $this->right,
            'heuristic' => true,
            'evidence' => $this->evidence,
        ];
    }
}
