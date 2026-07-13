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
     * Finds files by literal (case-insensitive) substring and by a separator-normalized form.
     *
     * The passes are deliberately combined instead of treating normalized matching as a fallback:
     * a literal DTO match must not hide the owning class merely because its PHP name uses
     * underscores (`M365EntraApp` vs `M365_EntraApp`). Method-only matches retain just the
     * matching methods, keeping command output suitable for choosing a small source range.
     */
    public function query(string $term): QueryMatch
    {
        $lower = mb_strtolower($term);
        $normalizedTerm = $this->normalize($term);
        if ($lower === '' || $normalizedTerm === '') {
            return new QueryMatch([], 'none');
        }

        $matches = [];
        $hasLiteral = false;
        $hasNormalizedOnly = false;
        foreach ($this->files as $file) {
            $literalPath = $this->matchesText($file->path, $lower);
            $normalizedPath = $this->matchesText($file->path, $normalizedTerm, true);
            $literalSymbols = $this->matchingSymbols($file, $lower);
            $normalizedSymbols = $this->matchingSymbols($file, $normalizedTerm, true);
            if (!$literalPath && !$normalizedPath && $literalSymbols === [] && $normalizedSymbols === []) {
                continue;
            }

            $isLiteral = $literalPath || $literalSymbols !== [];
            $isNormalized = $normalizedPath || $normalizedSymbols !== [];
            $hasLiteral = $hasLiteral || $isLiteral;
            $hasNormalizedOnly = $hasNormalizedOnly || ($isNormalized && !$isLiteral);
            $symbols = $this->mergeSymbolMatches($literalSymbols, $normalizedSymbols);

            $matches[] = [
                'file' => new FileEntry(
                    $file->path,
                    $file->modifiedAt,
                    $file->sha1,
                    $file->namespace,
                    $symbols === [] ? $file->symbols : $symbols,
                ),
                'score' => $this->fileMatchScore($file, $lower, $normalizedTerm),
            ];
        }

        usort(
            $matches,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['file']->path <=> $right['file']->path,
        );

        if ($matches === []) {
            return new QueryMatch([], 'none');
        }

        $matchType = $hasLiteral ? ($hasNormalizedOnly ? 'mixed' : 'exact') : 'normalized';

        return new QueryMatch(array_map(static fn (array $match): FileEntry => $match['file'], $matches), $matchType);
    }

    /**
     * @return array<string, SymbolEntry>
     */
    private function matchingSymbols(FileEntry $file, string $term, bool $normalized = false): array
    {
        $matches = [];
        foreach ($file->symbols as $symbol) {
            $symbolMatches = $this->matchesText($symbol->name, $term, $normalized)
                || $this->matchesText($symbol->fqn, $term, $normalized);
            $methods = [];
            foreach ($symbol->methods as $method) {
                if ($this->matchesText($method->name, $term, $normalized)) {
                    $methods[] = $method;
                }
            }

            if (!$symbolMatches && $methods === []) {
                continue;
            }

            $matches[$symbol->fqn] = $symbolMatches ? $symbol : new SymbolEntry(
                kind: $symbol->kind,
                name: $symbol->name,
                fqn: $symbol->fqn,
                lineStart: $symbol->lineStart,
                lineEnd: $symbol->lineEnd,
                methods: $methods,
                extends: $symbol->extends,
                implements: $symbol->implements,
                params: $symbol->params,
                returnType: $symbol->returnType,
                attributes: $symbol->attributes,
                uses: $symbol->uses,
            );
        }

        return $matches;
    }

    /**
     * @param array<string, SymbolEntry> $literal
     * @param array<string, SymbolEntry> $normalized
     *
     * @return list<SymbolEntry>
     */
    private function mergeSymbolMatches(array $literal, array $normalized): array
    {
        foreach ($normalized as $fqn => $symbol) {
            if (!isset($literal[$fqn]) || count($symbol->methods) > count($literal[$fqn]->methods)) {
                $literal[$fqn] = $symbol;
            }
        }

        return array_values($literal);
    }

    private function fileMatchScore(FileEntry $file, string $lower, string $normalizedTerm): int
    {
        $score = max(
            $this->matchScore($file->path, $lower, 8_000),
            $this->matchScore($file->path, $normalizedTerm, 5_000, true),
        );
        foreach ($file->symbols as $symbol) {
            $score = max(
                $score,
                $this->matchScore($symbol->name, $lower, 10_000),
                $this->matchScore($symbol->fqn, $lower, 9_500),
                $this->matchScore($symbol->name, $normalizedTerm, 7_000, true),
                $this->matchScore($symbol->fqn, $normalizedTerm, 6_500, true),
            );
            foreach ($symbol->methods as $method) {
                $score = max(
                    $score,
                    $this->matchScore($method->name, $lower, 9_000),
                    $this->matchScore($method->name, $normalizedTerm, 6_000, true),
                );
            }
        }

        return $score;
    }

    private function matchScore(string $value, string $term, int $base, bool $normalized = false): int
    {
        $candidate = $normalized ? $this->normalize($value) : mb_strtolower($value);
        if ($candidate === '' || $term === '') {
            return 0;
        }

        if ($candidate === $term) {
            return $base + 1_000;
        }

        $position = mb_strpos($candidate, $term);
        if ($position === false) {
            return 0;
        }

        return $base
            + ($position === 0 ? 600 : 0)
            - min(500, $position * 10)
            - min(99, max(0, mb_strlen($candidate) - mb_strlen($term)));
    }

    private function matchesText(string $value, string $term, bool $normalized = false): bool
    {
        $candidate = $normalized ? $this->normalize($value) : mb_strtolower($value);

        return $term !== '' && str_contains($candidate, $term);
    }

    private function normalize(string $value): string
    {
        return (string) preg_replace('~[^a-z0-9]+~', '', mb_strtolower($value));
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

    /**
     * @param list<FileEntry> $files
     *
     * @return list<FileEntry>
     */
    public function likelyTestFilesFor(array $files, int $limit = 10): array
    {
        $sourcePaths = array_fill_keys(array_map(static fn (FileEntry $file): string => $file->path, $files), true);
        $matches = [];
        foreach ($files as $file) {
            foreach ($this->likelyTestFiles($file, $limit) as $candidate) {
                if (isset($sourcePaths[$candidate->path])) {
                    continue;
                }

                $matches[$candidate->path] = $candidate;
                if (count($matches) >= $limit) {
                    return array_values($matches);
                }
            }
        }

        return array_values($matches);
    }

    /**
     * @param list<FileEntry> $files
     *
     * @return list<FileEntry>
     */
    public function sameNamespaceFilesFor(array $files, int $limit = 10): array
    {
        $sourcePaths = array_fill_keys(array_map(static fn (FileEntry $file): string => $file->path, $files), true);
        $matches = [];
        foreach ($files as $file) {
            foreach ($this->sameNamespaceFiles($file, $limit) as $candidate) {
                if (isset($sourcePaths[$candidate->path])) {
                    continue;
                }

                $matches[$candidate->path] = $candidate;
                if (count($matches) >= $limit) {
                    return array_values($matches);
                }
            }
        }

        return array_values($matches);
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
     * @return array{schema_version: string, generated_at: string, root: string, backend: string, files: list<array{path: string, modified_at: int, sha1: string, namespace: string, symbols: list<array{kind: string, name: string, fqn: string, line_start: int, line_end: int, extends: list<string>, implements: list<string>, uses: list<string>, params: list<string>, return_type: ?string, attributes: list<string>, methods: list<array{name: string, visibility: string, line_start: int, line_end: int, static: bool, params: list<string>, return_type: ?string, attributes: list<string>}>}>}>}
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
            (string) ($data['backend'] ?? 'simple'),
            $files,
        );
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
