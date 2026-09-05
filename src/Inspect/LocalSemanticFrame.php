<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class LocalSemanticFrame
{
    /**
     * @param list<LocalSemanticCheckpoint> $checkpoints
     */
    public function __construct(
        public string $targetId,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
        public array $checkpoints,
    ) {
    }

    public function toText(): string
    {
        if ($this->checkpoints === []) {
            return sprintf("Flow for %s (%s:%d-%d):\n  (empty)", $this->targetId, $this->file, $this->lineStart, $this->lineEnd);
        }

        $lines = [sprintf('Flow for %s (%s:%d-%d):', $this->targetId, $this->file, $this->lineStart, $this->lineEnd)];
        foreach ($this->checkpoints as $checkpoint) {
            $lines[] = '  ' . $checkpoint->toText();
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{target_id: string, file: string, line_start: int, line_end: int, checkpoints: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'target_id' => $this->targetId,
            'file' => $this->file,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'checkpoints' => array_map(static fn (LocalSemanticCheckpoint $cp): array => $cp->toArray(), $this->checkpoints),
        ];
    }
}
