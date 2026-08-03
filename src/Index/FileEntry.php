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
        public string $sha256,
        public string $namespace,
        public array $symbols,
        public string $semanticStatus = 'analysed',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'sha256' => $this->sha256,
            'namespace' => $this->namespace,
            'semantic_status' => $this->semanticStatus,
            'symbols' => array_map(static fn (SymbolEntry $symbol): array => $symbol->toArray(), $this->symbols),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $symbols = [];
        foreach (is_array($data['symbols'] ?? null) ? $data['symbols'] : [] as $symbol) {
            if (is_array($symbol)) {
                $symbols[] = SymbolEntry::fromArray($symbol);
            }
        }

        $sha256 = (string) ($data['sha256'] ?? '');
        if ($sha256 === '' && is_string($data['sha1'] ?? null)) {
            $sha256 = 'legacy-sha1:' . $data['sha1'];
        }

        return new self(
            path: (string) ($data['path'] ?? ''),
            sha256: $sha256,
            namespace: (string) ($data['namespace'] ?? ''),
            symbols: $symbols,
            semanticStatus: (string) ($data['semantic_status'] ?? 'unknown'),
        );
    }
}
