<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class LocalExitEntry
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $kind,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
        public string $expressionType,
        public ?string $literalValue = null,
        public ?string $variable = null,
        public ?int $startFilePos = null,
        public ?int $endFilePos = null,
        public ?int $exprStartFilePos = null,
        public ?int $exprEndFilePos = null,
    ) {
    }

    public static function create(
        string $ownerId,
        string $kind,
        string $file,
        int $lineStart,
        int $lineEnd,
        string $expressionType,
        ?string $literalValue = null,
        ?string $variable = null,
        ?int $startFilePos = null,
        ?int $endFilePos = null,
        ?int $exprStartFilePos = null,
        ?int $exprEndFilePos = null,
    ): self {
        $identity = implode("\0", [
            $ownerId,
            $kind,
            $file,
            (string) $lineStart,
            (string) $lineEnd,
            $expressionType,
            $literalValue ?? '',
            $variable ?? '',
            (string) ($startFilePos ?? ''),
            (string) ($endFilePos ?? ''),
        ]);

        return new self(
            id: 'exit:' . hash('sha256', $identity),
            ownerId: $ownerId,
            kind: $kind,
            file: $file,
            lineStart: $lineStart,
            lineEnd: $lineEnd,
            expressionType: $expressionType,
            literalValue: $literalValue,
            variable: $variable,
            startFilePos: $startFilePos,
            endFilePos: $endFilePos,
            exprStartFilePos: $exprStartFilePos,
            exprEndFilePos: $exprEndFilePos,
        );
    }

    /**
     * @return array{id: string, owner_id: string, kind: string, file: string, line_start: int, line_end: int, expression_type: string, literal_value: ?string, variable: ?string, start_file_pos: ?int, end_file_pos: ?int, expr_start_file_pos: ?int, expr_end_file_pos: ?int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->ownerId,
            'kind' => $this->kind,
            'file' => $this->file,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'expression_type' => $this->expressionType,
            'literal_value' => $this->literalValue,
            'variable' => $this->variable,
            'start_file_pos' => $this->startFilePos,
            'end_file_pos' => $this->endFilePos,
            'expr_start_file_pos' => $this->exprStartFilePos,
            'expr_end_file_pos' => $this->exprEndFilePos,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            ownerId: (string) ($data['owner_id'] ?? ''),
            kind: (string) ($data['kind'] ?? 'return'),
            file: (string) ($data['file'] ?? ''),
            lineStart: (int) ($data['line_start'] ?? 0),
            lineEnd: (int) ($data['line_end'] ?? 0),
            expressionType: (string) ($data['expression_type'] ?? 'void'),
            literalValue: is_string($data['literal_value'] ?? null) ? $data['literal_value'] : null,
            variable: is_string($data['variable'] ?? null) ? $data['variable'] : null,
            startFilePos: isset($data['start_file_pos']) && is_int($data['start_file_pos']) ? $data['start_file_pos'] : null,
            endFilePos: isset($data['end_file_pos']) && is_int($data['end_file_pos']) ? $data['end_file_pos'] : null,
            exprStartFilePos: isset($data['expr_start_file_pos']) && is_int($data['expr_start_file_pos']) ? $data['expr_start_file_pos'] : null,
            exprEndFilePos: isset($data['expr_end_file_pos']) && is_int($data['expr_end_file_pos']) ? $data['expr_end_file_pos'] : null,
        );
    }
}
