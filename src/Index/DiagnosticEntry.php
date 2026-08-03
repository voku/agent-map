<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class DiagnosticEntry
{
    public function __construct(
        public string $id,
        public string $severity,
        public string $kind,
        public string $message,
        public ?string $symbolId = null,
        public ?string $file = null,
        public ?int $line = null,
    ) {
    }

    public static function create(
        string $severity,
        string $kind,
        string $message,
        ?string $symbolId = null,
        ?string $file = null,
        ?int $line = null,
    ): self {
        return new self(
            id: 'diagnostic:' . hash('sha256', implode("\0", [$severity, $kind, $message, $symbolId ?? '', $file ?? '', (string) ($line ?? 0)])),
            severity: $severity,
            kind: $kind,
            message: $message,
            symbolId: $symbolId,
            file: $file,
            line: $line,
        );
    }

    /**
     * @return array{id: string, severity: string, kind: string, message: string, symbol_id: ?string, file: ?string, line: ?int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity,
            'kind' => $this->kind,
            'message' => $this->message,
            'symbol_id' => $this->symbolId,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }

    /**
     * @param array{id?: mixed, severity?: mixed, kind?: mixed, message?: mixed, symbol_id?: mixed, file?: mixed, line?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            severity: (string) ($data['severity'] ?? 'warning'),
            kind: (string) ($data['kind'] ?? 'unknown'),
            message: (string) ($data['message'] ?? ''),
            symbolId: is_string($data['symbol_id'] ?? null) ? $data['symbol_id'] : null,
            file: is_string($data['file'] ?? null) ? $data['file'] : null,
            line: is_int($data['line'] ?? null) ? $data['line'] : null,
        );
    }
}
