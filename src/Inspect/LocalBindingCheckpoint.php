<?php

declare(strict_types=1);

namespace voku\AgentMap\Inspect;

final readonly class LocalBindingCheckpoint implements LocalSemanticCheckpoint
{
    public function __construct(
        public int $line,
        public string $variable,
        public string $resolvedType,
        public string $expressionKind,
        public string $codeSnippet,
        public ?string $literalValue = null,
        public ?string $receiverType = null,
        public ?string $callTarget = null,
        public ?int $startFilePos = null,
    ) {
    }

    public function kind(): string
    {
        return 'binding';
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
        $meta = ['type: ' . $this->resolvedType, 'kind: ' . $this->expressionKind];
        if ($this->receiverType !== null) {
            $meta[] = 'receiver: ' . $this->receiverType;
        }
        if ($this->literalValue !== null && $this->expressionKind === 'literal') {
            $meta[] = 'value: ' . $this->literalValue;
        }

        return sprintf('line %d: %s [%s]', $this->line, $this->codeSnippet, implode(', ', $meta));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind(),
            'line' => $this->line,
            'variable' => $this->variable,
            'resolved_type' => $this->resolvedType,
            'expression_kind' => $this->expressionKind,
            'code_snippet' => $this->codeSnippet,
            'literal_value' => $this->literalValue,
            'receiver_type' => $this->receiverType,
            'call_target' => $this->callTarget,
            'start_file_pos' => $this->startFilePos,
        ];
    }
}
