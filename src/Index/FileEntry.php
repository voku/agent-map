<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class FileEntry
{
    /**
     * @param list<SymbolEntry> $symbols
     */
    public function __construct(
        public string $path,
        public int $modifiedAt,
        public string $sha1,
        public string $namespace,
        public array $symbols,
    ) {
    }

    /**
     * @return array{path: string, modified_at: int, sha1: string, namespace: string, symbols: list<array{kind: string, name: string, fqn: string, line_start: int, line_end: int, methods: list<array{name: string, visibility: string, line_start: int}>}>}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'modified_at' => $this->modifiedAt,
            'sha1' => $this->sha1,
            'namespace' => $this->namespace,
            'symbols' => array_map(static fn (SymbolEntry $symbol): array => $symbol->toArray(), $this->symbols),
        ];
    }

    /**
     * @param array{path?: mixed, modified_at?: mixed, sha1?: mixed, namespace?: mixed, symbols?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        $symbols = [];
        foreach (is_array($data['symbols'] ?? null) ? $data['symbols'] : [] as $symbol) {
            if (is_array($symbol)) {
                $symbols[] = SymbolEntry::fromArray($symbol);
            }
        }

        return new self(
            (string) ($data['path'] ?? ''),
            (int) ($data['modified_at'] ?? 0),
            (string) ($data['sha1'] ?? ''),
            (string) ($data['namespace'] ?? ''),
            $symbols,
        );
    }
}
