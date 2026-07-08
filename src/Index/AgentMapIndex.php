<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

final readonly class AgentMapIndex
{
    /**
     * @param list<FileEntry> $files
     */
    public function __construct(
        public string $schemaVersion,
        public string $generatedAt,
        public string $root,
        public string $backend,
        public array $files,
    ) {
    }

    /**
     * @return list<FileEntry>
     */
    public function query(string $term): array
    {
        $term = mb_strtolower($term);
        $matches = [];
        foreach ($this->files as $file) {
            if (str_contains(mb_strtolower($file->path), $term) || $this->fileHasSymbolMatch($file, $term)) {
                $matches[] = $file;
            }
        }

        return $matches;
    }

    /**
     * @return array{files_indexed: int, symbols: int, classes: int, interfaces: int, traits: int, enums: int, functions: int}
     */
    public function summaryCounts(): array
    {
        $counts = [
            'files_indexed' => count($this->files),
            'symbols' => 0,
            'classes' => 0,
            'interfaces' => 0,
            'traits' => 0,
            'enums' => 0,
            'functions' => 0,
        ];

        foreach ($this->files as $file) {
            foreach ($file->symbols as $symbol) {
                ++$counts['symbols'];
                match ($symbol->kind) {
                    'class' => ++$counts['classes'],
                    'interface' => ++$counts['interfaces'],
                    'trait' => ++$counts['traits'],
                    'enum' => ++$counts['enums'],
                    'function' => ++$counts['functions'],
                    default => null,
                };
            }
        }

        return $counts;
    }

    public function methodCount(): int
    {
        $count = 0;
        foreach ($this->files as $file) {
            foreach ($file->symbols as $symbol) {
                $count += count($symbol->methods);
            }
        }

        return $count;
    }

    /**
     * @return list<array{namespace: string, symbols: int}>
     */
    public function topNamespaces(int $limit = 5): array
    {
        $counts = [];
        foreach ($this->files as $file) {
            foreach ($file->symbols as $symbol) {
                $namespace = $this->namespaceFromFqn($symbol->fqn);
                if ($namespace === '') {
                    continue;
                }

                $counts[$namespace] = ($counts[$namespace] ?? 0) + 1;
            }
        }

        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, $limit, true) as $namespace => $symbols) {
            $rows[] = ['namespace' => (string) $namespace, 'symbols' => (int) $symbols];
        }

        return $rows;
    }

    /**
     * @return list<array{directory: string, files: int}>
     */
    public function topDirectories(int $limit = 5): array
    {
        $counts = [];
        foreach ($this->files as $file) {
            $directory = dirname($file->path);
            if ($directory === '.') {
                $directory = '/';
            }

            $counts[$directory] = ($counts[$directory] ?? 0) + 1;
        }

        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, $limit, true) as $directory => $files) {
            $rows[] = ['directory' => (string) $directory, 'files' => (int) $files];
        }

        return $rows;
    }

    /**
     * @return list<array{path: string, symbols: int}>
     */
    public function largestFiles(int $limit = 5): array
    {
        $rows = [];
        foreach ($this->files as $file) {
            $rows[] = ['path' => $file->path, 'symbols' => count($file->symbols)];
        }

        usort($rows, static fn (array $left, array $right): int => $right['symbols'] <=> $left['symbols']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * @return list<FileEntry>
     */
    public function sameNamespaceFiles(FileEntry $file, int $limit = 10): array
    {
        if ($file->namespace === '') {
            return [];
        }

        $matches = [];
        foreach ($this->files as $candidate) {
            if ($candidate->path !== $file->path && $candidate->namespace === $file->namespace) {
                $matches[] = $candidate;
            }
        }

        return array_slice($matches, 0, $limit);
    }

    /**
     * @return list<FileEntry>
     */
    public function likelyTestFiles(FileEntry $file, int $limit = 10): array
    {
        $base = preg_replace('~\.php$~', '', basename($file->path));
        if (!is_string($base) || $base === '') {
            return [];
        }

        $matches = [];
        foreach ($this->files as $candidate) {
            if ($candidate->path === $file->path) {
                continue;
            }

            if (str_contains(mb_strtolower($candidate->path), mb_strtolower($base)) && $this->looksLikeTestPath($candidate->path)) {
                $matches[] = $candidate;
            }
        }

        return array_slice($matches, 0, $limit);
    }

    public function file(string $path): ?FileEntry
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        foreach ($this->files as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return list<array{path: string, reason: string}>
     */
    public function staleEntries(): array
    {
        $stale = [];
        foreach ($this->files as $file) {
            $absolute = $this->root . '/' . $file->path;
            if (!is_file($absolute)) {
                $stale[] = ['path' => $file->path, 'reason' => 'missing'];
                continue;
            }

            if ((int) filemtime($absolute) !== $file->modifiedAt) {
                $stale[] = ['path' => $file->path, 'reason' => 'modified'];
                continue;
            }

            if (sha1_file($absolute) !== $file->sha1) {
                $stale[] = ['path' => $file->path, 'reason' => 'hash'];
            }
        }

        return $stale;
    }

    /**
     * @return array{schema_version: string, generated_at: string, root: string, backend: string, files: list<array{path: string, modified_at: int, sha1: string, namespace: string, symbols: list<array{kind: string, name: string, fqn: string, line_start: int, line_end: int, methods: list<array{name: string, visibility: string, line_start: int}>}>}>}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'generated_at' => $this->generatedAt,
            'root' => $this->root,
            'backend' => $this->backend,
            'files' => array_map(static fn (FileEntry $file): array => $file->toArray(), $this->files),
        ];
    }

    /**
     * @param array{schema_version?: mixed, generated_at?: mixed, root?: mixed, backend?: mixed, files?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        $files = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
            if (is_array($file)) {
                $files[] = FileEntry::fromArray($file);
            }
        }

        return new self(
            (string) ($data['schema_version'] ?? '1.0'),
            (string) ($data['generated_at'] ?? ''),
            (string) ($data['root'] ?? ''),
            (string) ($data['backend'] ?? 'token'),
            $files,
        );
    }

    private function fileHasSymbolMatch(FileEntry $file, string $term): bool
    {
        foreach ($file->symbols as $symbol) {
            if (str_contains(mb_strtolower($symbol->name), $term) || str_contains(mb_strtolower($symbol->fqn), $term)) {
                return true;
            }

            foreach ($symbol->methods as $method) {
                if (str_contains(mb_strtolower($method->name), $term)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function namespaceFromFqn(string $fqn): string
    {
        if (!str_contains($fqn, '\\')) {
            return '';
        }

        return substr($fqn, 0, (int) strrpos($fqn, '\\'));
    }

    private function looksLikeTestPath(string $path): bool
    {
        $lower = mb_strtolower($path);

        return str_contains($lower, '/tests/')
            || str_contains($lower, 'test')
            || str_contains($path, '_UnitCest.php')
            || str_contains($path, '_AcceptanceCest.php')
            || str_contains($path, '_ApiCest.php');
    }
}
