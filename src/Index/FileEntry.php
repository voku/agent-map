<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use InvalidArgumentException;

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
        $path = (string) ($data['path'] ?? '');
        $normalizedPath = str_replace('\\', '/', $path);
        if ($normalizedPath === ''
            || str_starts_with($normalizedPath, '/')
            || preg_match('/^[A-Za-z]:/', $normalizedPath) === 1
            || str_contains($normalizedPath, "\0")) {
            throw new InvalidArgumentException('Invalid agent-map index file path; expected a normalized project-relative path.');
        }

        $segments = [];
        foreach (explode('/', $normalizedPath) as $segment) {
            if ($segment === '..' || $segment === '') {
                throw new InvalidArgumentException('Invalid agent-map index file path; expected a normalized project-relative path.');
            }
            if ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        $normalizedPath = implode('/', $segments);
        if ($normalizedPath === '') {
            throw new InvalidArgumentException('Invalid agent-map index file path; expected a normalized project-relative path.');
        }

        $symbols = [];
        foreach (is_array($data['symbols'] ?? null) ? $data['symbols'] : [] as $symbol) {
            if (is_array($symbol)) {
                $symbols[] = SymbolEntry::fromArray($symbol);
            }
        }

        return new self(
            path: $normalizedPath,
            sha256: (string) ($data['sha256'] ?? ''),
            namespace: (string) ($data['namespace'] ?? ''),
            symbols: $symbols,
            semanticStatus: (string) ($data['semantic_status'] ?? 'unknown'),
        );
    }
}
