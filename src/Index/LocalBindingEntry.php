<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class LocalBindingEntry
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $variable,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
        public string $resolvedType,
        public string $expressionKind,
        public ?string $literalValue = null,
        public ?int $startFilePos = null,
        public ?int $endFilePos = null,
        public ?int $rhsStartFilePos = null,
        public ?int $rhsEndFilePos = null,
    ) {
    }

    public static function create(
        string $ownerId,
        string $variable,
        string $file,
        int $lineStart,
        int $lineEnd,
        string $resolvedType,
        string $expressionKind,
        ?string $literalValue = null,
        ?int $startFilePos = null,
        ?int $endFilePos = null,
        ?int $rhsStartFilePos = null,
        ?int $rhsEndFilePos = null,
    ): self {
        $identity = implode("\0", [
            $ownerId,
            $variable,
            $file,
            (string) $lineStart,
            (string) $lineEnd,
            $resolvedType,
            $expressionKind,
            $literalValue ?? '',
            (string) ($startFilePos ?? ''),
            (string) ($endFilePos ?? ''),
        ]);

        return new self(
            id: 'binding:' . hash('sha256', $identity),
            ownerId: $ownerId,
            variable: $variable,
            file: $file,
            lineStart: $lineStart,
            lineEnd: $lineEnd,
            resolvedType: $resolvedType,
            expressionKind: $expressionKind,
            literalValue: $literalValue,
            startFilePos: $startFilePos,
            endFilePos: $endFilePos,
            rhsStartFilePos: $rhsStartFilePos,
            rhsEndFilePos: $rhsEndFilePos,
        );
    }

    /**
     * @return array{id: string, owner_id: string, variable: string, file: string, line_start: int, line_end: int, resolved_type: string, expression_kind: string, literal_value: ?string, start_file_pos: ?int, end_file_pos: ?int, rhs_start_file_pos: ?int, rhs_end_file_pos: ?int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->ownerId,
            'variable' => $this->variable,
            'file' => $this->file,
            'line_start' => $this->lineStart,
            'line_end' => $this->lineEnd,
            'resolved_type' => $this->resolvedType,
            'expression_kind' => $this->expressionKind,
            'literal_value' => $this->literalValue,
            'start_file_pos' => $this->startFilePos,
            'end_file_pos' => $this->endFilePos,
            'rhs_start_file_pos' => $this->rhsStartFilePos,
            'rhs_end_file_pos' => $this->rhsEndFilePos,
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
            variable: (string) ($data['variable'] ?? ''),
            file: (string) ($data['file'] ?? ''),
            lineStart: (int) ($data['line_start'] ?? 0),
            lineEnd: (int) ($data['line_end'] ?? 0),
            resolvedType: (string) ($data['resolved_type'] ?? 'mixed'),
            expressionKind: (string) ($data['expression_kind'] ?? 'other'),
            literalValue: is_string($data['literal_value'] ?? null) ? $data['literal_value'] : null,
            startFilePos: isset($data['start_file_pos']) && is_int($data['start_file_pos']) ? $data['start_file_pos'] : null,
            endFilePos: isset($data['end_file_pos']) && is_int($data['end_file_pos']) ? $data['end_file_pos'] : null,
            rhsStartFilePos: isset($data['rhs_start_file_pos']) && is_int($data['rhs_start_file_pos']) ? $data['rhs_start_file_pos'] : null,
            rhsEndFilePos: isset($data['rhs_end_file_pos']) && is_int($data['rhs_end_file_pos']) ? $data['rhs_end_file_pos'] : null,
        );
    }
}
