<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class LocalExitCheckpoint implements LocalSemanticCheckpoint
{
    public function __construct(
        public int $line,
        public string $exitKind,
        public string $expressionType,
        public ?string $variable = null,
        public ?string $literalValue = null,
        public ?string $codeSnippet = null,
        public ?int $startFilePos = null,
    ) {
    }

    public function kind(): string
    {
        return 'exit';
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
        $snippet = $this->codeSnippet ?? ($this->exitKind . ($this->variable !== null ? ' ' . $this->variable : ($this->literalValue !== null ? ' ' . $this->literalValue : '')));

        return sprintf('line %d: exit: %s [type: %s]', $this->line, $snippet, $this->expressionType);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind(),
            'line' => $this->line,
            'exit_kind' => $this->exitKind,
            'expression_type' => $this->expressionType,
            'variable' => $this->variable,
            'literal_value' => $this->literalValue,
            'code_snippet' => $this->codeSnippet,
            'start_file_pos' => $this->startFilePos,
        ];
    }
}
