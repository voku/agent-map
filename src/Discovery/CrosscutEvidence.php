<?php

declare(strict_types=1);

namespace voku\AgentMap\Discovery;

final readonly class CrosscutEvidence
{
    /** @param list<string> $signals */
    public function __construct(
        public string $file,
        public float $score,
        public array $signals,
    ) {
    }

    /** @return array{file: string, score: float, signals: list<string>} */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'score' => round($this->score, 6),
            'signals' => $this->signals,
        ];
    }
}
