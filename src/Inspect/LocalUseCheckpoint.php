<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class LocalUseCheckpoint implements LocalSemanticCheckpoint
{
    public function __construct(
        public int $line,
        public string $variable,
        public string $expression,
        public ?string $receiverType = null,
        public ?string $targetId = null,
        public ?string $resultType = null,
        public ?int $startFilePos = null,
    ) {
    }

    public function kind(): string
    {
        return 'use';
    }

    public function line(): int
    {
        return $this->line;
    }

    public function startFilePos(): ?int
    {
        return $this->startFilePos;
    }

    public function toText(): string
    {
        $meta = [];
        if ($this->receiverType !== null) {
            $meta[] = 'receiver: ' . $this->receiverType;
        }
        if ($this->resultType !== null) {
            $meta[] = 'result: ' . $this->resultType;
        }
        $suffix = $meta !== [] ? ' [' . implode(', ', $meta) . ']' : '';

        return sprintf('line %d: semantic use: %s%s', $this->line, $this->expression, $suffix);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind(),
            'line' => $this->line,
            'variable' => $this->variable,
            'expression' => $this->expression,
            'receiver_type' => $this->receiverType,
            'target_id' => $this->targetId,
            'result_type' => $this->resultType,
            'start_file_pos' => $this->startFilePos,
        ];
    }
}
