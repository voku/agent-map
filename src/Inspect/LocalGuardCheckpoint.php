<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class LocalGuardCheckpoint implements LocalSemanticCheckpoint
{
    public function __construct(
        public int $line,
        public string $condition,
        public ?string $variable = null,
        public bool $exits = false,
        public ?string $exitKind = null,
        public ?string $exitTarget = null,
        public ?string $narrowing = null,
        public ?int $startFilePos = null,
    ) {
    }

    public function kind(): string
    {
        return 'guard';
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
        $details = [];
        if ($this->exits && $this->exitKind !== null) {
            $details[] = 'exits: ' . $this->exitKind . ($this->exitTarget !== null ? ' ' . $this->exitTarget : '');
        }
        if ($this->narrowing !== null) {
            $details[] = 'narrows: ' . $this->narrowing;
        }
        $suffix = $details !== [] ? ' [' . implode(', ', $details) . ']' : '';

        return sprintf('line %d: guard on %s%s', $this->line, $this->condition, $suffix);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind(),
            'line' => $this->line,
            'condition' => $this->condition,
            'variable' => $this->variable,
            'exits' => $this->exits,
            'exit_kind' => $this->exitKind,
            'exit_target' => $this->exitTarget,
            'narrowing' => $this->narrowing,
            'start_file_pos' => $this->startFilePos,
        ];
    }
}
