<?php

declare(strict_types=1);

namespace voku\AgentMap\Index;

use RuntimeException;

final readonly class AgentMapIndex
{
    /**
     * @param list<FileEntry> $files
     * @param list<RelationEntry> $relations
     * @param list<DiagnosticEntry> $diagnostics
     */
    public function __construct(
        public string $schemaVersion,
        public string $root,
        public string $backend,
        public array $files,
        public array $relations = [],
        public array $diagnostics = [],
        public ?AnalysisFingerprint $fingerprint = null,
    ) {
    }

    public function query(string $term): QueryMatch
    {
        $lower = strtolower($term);
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
                'file' => new FileEntry($file->path, $file->sha256, $file->namespace, $symbols === [] ? $file->symbols : $symbols, $file->semanticStatus),
                'score' => $this->fileMatchScore($file, $lower, $normalizedTerm),
            ];
        }

        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['file']->path <=> $right['file']->path);
        if ($matches === []) {
            return new QueryMatch([], 'none');
        }

        return new QueryMatch(
            array_map(static fn (array $match): FileEntry => $match['file'], $matches),
            $hasLiteral ? ($hasNormalizedOnly ? 'mixed' : 'exact') : 'normalized',
        );
    }

    public function resolveMethod(string $target): ResolvedMethod
    {
        $target = ltrim(trim($target), '\\');
        $separator = strrpos($target, '::');
        if ($separator === false) {
            throw new RuntimeException('Method target must use Class::method syntax: ' . $target);
        }
        $className = substr($target, 0, $separator);
        $methodName = substr($target, $separator + 2);
        if ($className === '' || $methodName === '') {
            throw new RuntimeException('Method target must use Class::method syntax: ' . $target);
        }

        $qualified = str_contains($className, '\\');
        $matches = [];
        foreach ($this->files as $file) {
            foreach ($file->symbols as $symbol) {
                if (!in_array($symbol->kind, ['class', 'interface', 'trait', 'enum'], true)) {
                    continue;
                }
                if ($qualified ? $symbol->fqn !== $className : $symbol->name !== $className) {
                    continue;
                }
                foreach ($symbol->methods as $method) {
                    if ($method->name !== $methodName) {
                        continue;
                    }
                    if ($symbol->reconciliationStatus === 'conflict' || $method->reconciliationStatus === 'conflict') {
                        throw new RuntimeException('Cannot use conflicted map symbol as edit target: ' . $symbol->fqn . '::' . $methodName);
                    }
                    $matches[] = new ResolvedMethod($symbol->methodId($method), $file, $symbol, $method);
                }
            }
        }

        if ($matches === []) {
            $suggestions = [];
            foreach ($this->files as $file) {
                foreach ($file->symbols as $symbol) {
                    foreach ($symbol->methods as $method) {
                        if ($method->name === $methodName) {
                            $suggestions[] = $symbol->fqn . '::' . $methodName;
                        }
                    }
                }
            }
            sort($suggestions, SORT_STRING);
            throw new RuntimeException('Method target not found: ' . $target . ($suggestions !== [] ? "\nCandidates:\n- " . implode("\n- ", array_slice($suggestions, 0, 10)) : ''));
        }
        if (count($matches) > 1) {
            $candidates = array_map(static fn (ResolvedMethod $match): string => $match->owner->fqn . '::' . $match->method->name, $matches);
            sort($candidates, SORT_STRING);
            throw new RuntimeException('Method target is ambiguous: ' . $target . "\nUse a fully-qualified class name:\n- " . implode("\n- ", $candidates));
        }

        return $matches[0];
    }

    /** @return list<RelationEntry> */
    public function incoming(string $symbolId, ?string $kind = null): array
    {
        $matches = [];
        foreach ($this->relations as $relation) {
            if (($kind === null || $relation->kind === $kind) && in_array($symbolId, $relation->targetIds, true)) {
                $matches[] = $relation;
            }
        }

        return $matches;
    }

    /** @return list<RelationEntry> */
    public function outgoing(string $symbolId, ?string $kind = null): array
    {
        $matches = [];
        foreach ($this->relations as $relation) {
            if ($relation->sourceId === $symbolId && ($kind === null || $relation->kind === $kind)) {
                $matches[] = $relation;
            }
        }

        return $matches;
    }

    public function resolvedMethodById(string $id): ?ResolvedMethod
    {
        foreach ($this->files as $file) {
            foreach ($file->symbols as $symbol) {
                foreach ($symbol->methods as $method) {
                    if ($symbol->methodId($method) === $id) {
                        return new ResolvedMethod($id, $file, $symbol, $method);
                    }
                }
            }
        }

        return null;
    }

    /** @return array{file: FileEntry, symbol: SymbolEntry}|null */
    public function symbolById(string $id): ?array
    {
        if (str_starts_with($id, 'type:')) {
            $fqn = substr($id, 5);
            foreach ($this->files as $file) {
                foreach ($file->symbols as $symbol) {
                    if (ltrim($symbol->fqn, '\\') === ltrim($fqn, '\\')) {
                        return ['file' => $file, 'symbol' => $symbol];
                    }
                }
            }
            return null;
        }

        foreach ($this->files as $file) {
            foreach ($file->symbols as $symbol) {
                if ($symbol->id() === $id) {
                    return ['file' => $file, 'symbol' => $symbol];
                }
            }
        }

        return null;
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

    /** @return list<array{path: string, reason: string}> */
    public function staleEntries(): array
    {
        $stale = [];
        foreach ($this->files as $file) {
            $absolute = $this->root . '/' . $file->path;
            clearstatcache(true, $absolute);
            if (!is_file($absolute)) {
                $stale[] = ['path' => $file->path, 'reason' => 'missing'];
                continue;
            }
            $hash = hash_file('sha256', $absolute);
            if (!is_string($hash) || !hash_equals($file->sha256, 'sha256:' . $hash)) {
                $stale[] = ['path' => $file->path, 'reason' => 'hash'];
            }
        }

        return $stale;
    }

    /** @return array{files_indexed: int, symbols: int, classes: int, interfaces: int, traits: int, enums: int, functions: int} */
    public function summaryCounts(): array
    {
        $counts = ['files_indexed' => count($this->files), 'symbols' => 0, 'classes' => 0, 'interfaces' => 0, 'traits' => 0, 'enums' => 0, 'functions' => 0];
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

    /** @return list<array{namespace: string, symbols: int}> */
    public function topNamespaces(int $limit = 5): array
    {
        $counts = [];
        foreach ($this->files as $file) {
            foreach ($file->symbols as $symbol) {
                $namespace = str_contains($symbol->fqn, '\\') ? substr($symbol->fqn, 0, (int) strrpos($symbol->fqn, '\\')) : '';
                if ($namespace !== '') {
                    $counts[$namespace] = ($counts[$namespace] ?? 0) + 1;
                }
            }
        }
        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, $limit, true) as $namespace => $symbols) {
            $rows[] = ['namespace' => (string) $namespace, 'symbols' => (int) $symbols];
        }
        return $rows;
    }

    /** @return list<array{directory: string, files: int}> */
    public function topDirectories(int $limit = 5): array
    {
        $counts = [];
        foreach ($this->files as $file) {
            $directory = dirname($file->path);
            $directory = $directory === '.' ? '/' : $directory;
            $counts[$directory] = ($counts[$directory] ?? 0) + 1;
        }
        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, $limit, true) as $directory => $files) {
            $rows[] = ['directory' => (string) $directory, 'files' => (int) $files];
        }
        return $rows;
    }

    /** @return list<array{path: string, symbols: int}> */
    public function largestFiles(int $limit = 5): array
    {
        $rows = array_map(static fn (FileEntry $file): array => ['path' => $file->path, 'symbols' => count($file->symbols)], $this->files);
        usort($rows, static fn (array $left, array $right): int => $right['symbols'] <=> $left['symbols'] ?: $left['path'] <=> $right['path']);
        return array_slice($rows, 0, $limit);
    }

    /** @return list<FileEntry> */
    public function likelyTestFiles(FileEntry $file, int $limit = 10): array
    {
        $base = (string) preg_replace('/(?:Test|Cest)$/i', '', pathinfo($file->path, PATHINFO_FILENAME));
        $matches = [];
        foreach ($this->files as $candidate) {
            if ($candidate->path === $file->path || !$this->looksLikeTestPath($candidate->path)) {
                continue;
            }
            $candidateBase = (string) preg_replace('/(?:Test|Cest)$/i', '', pathinfo($candidate->path, PATHINFO_FILENAME));
            if ($candidateBase === $base || str_contains($candidateBase, (string) $base) || str_contains((string) $base, $candidateBase)) {
                $matches[] = $candidate;
            }
        }
        usort($matches, static fn (FileEntry $left, FileEntry $right): int => $left->path <=> $right->path);
        return array_slice($matches, 0, $limit);
    }

    /**
     * @param list<FileEntry> $files
     * @return list<FileEntry>
     */
    public function likelyTestFilesFor(array $files, int $limit = 10): array
    {
        $sourcePaths = array_fill_keys(array_map(static fn (FileEntry $file): string => $file->path, $files), true);
        $matches = [];
        foreach ($files as $file) {
            foreach ($this->likelyTestFiles($file, $limit) as $candidate) {
                if (!isset($sourcePaths[$candidate->path])) {
                    $matches[$candidate->path] = $candidate;
                }
                if (count($matches) >= $limit) {
                    return array_values($matches);
                }
            }
        }
        return array_values($matches);
    }

    /** @return list<FileEntry> */
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
        usort($matches, static fn (FileEntry $left, FileEntry $right): int => $left->path <=> $right->path);
        return array_slice($matches, 0, $limit);
    }

    /**
     * @param list<FileEntry> $files
     * @return list<FileEntry>
     */
    public function sameNamespaceFilesFor(array $files, int $limit = 10): array
    {
        $sourcePaths = array_fill_keys(array_map(static fn (FileEntry $file): string => $file->path, $files), true);
        $matches = [];
        foreach ($files as $file) {
            foreach ($this->sameNamespaceFiles($file, $limit) as $candidate) {
                if (!isset($sourcePaths[$candidate->path])) {
                    $matches[$candidate->path] = $candidate;
                }
                if (count($matches) >= $limit) {
                    return array_values($matches);
                }
            }
        }
        return array_values($matches);
    }

    public function mapDigest(): string
    {
        $data = $this->toArray();
        unset($data['root']);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return 'sha256:' . hash('sha256', $json);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'root' => $this->root,
            'backend' => $this->backend,
            'fingerprint' => $this->fingerprint?->toArray(),
            'files' => array_map(static fn (FileEntry $file): array => $file->toArray(), $this->files),
            'relations' => array_map(static fn (RelationEntry $relation): array => $relation->toArray(), $this->relations),
            'diagnostics' => array_map(static fn (DiagnosticEntry $diagnostic): array => $diagnostic->toArray(), $this->diagnostics),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $files = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
            if (is_array($file)) {
                $files[] = FileEntry::fromArray($file);
            }
        }
        $relations = [];
        foreach (is_array($data['relations'] ?? null) ? $data['relations'] : [] as $relation) {
            if (is_array($relation)) {
                $relations[] = RelationEntry::fromArray($relation);
            }
        }
        $diagnostics = [];
        foreach (is_array($data['diagnostics'] ?? null) ? $data['diagnostics'] : [] as $diagnostic) {
            if (is_array($diagnostic)) {
                $diagnostics[] = DiagnosticEntry::fromArray($diagnostic);
            }
        }
        $fingerprint = is_array($data['fingerprint'] ?? null) ? AnalysisFingerprint::fromArray($data['fingerprint']) : null;

        return new self(
            schemaVersion: (string) ($data['schema_version'] ?? '1.0'),
            root: (string) ($data['root'] ?? ''),
            backend: (string) ($data['backend'] ?? 'simple'),
            files: $files,
            relations: $relations,
            diagnostics: $diagnostics,
            fingerprint: $fingerprint,
        );
    }

    /** @return array<string, SymbolEntry> */
    private function matchingSymbols(FileEntry $file, string $term, bool $normalized = false): array
    {
        $matches = [];
        foreach ($file->symbols as $symbol) {
            $symbolMatches = $this->matchesText($symbol->name, $term, $normalized) || $this->matchesText($symbol->fqn, $term, $normalized);
            $methods = [];
            foreach ($symbol->methods as $method) {
                if ($this->matchesText($method->name, $term, $normalized)) {
                    $methods[] = $method;
                }
            }
            if (!$symbolMatches && $methods === []) {
                continue;
            }
            $matches[$symbol->fqn] = $symbolMatches ? $symbol : $this->withMethods($symbol, $methods);
        }
        return $matches;
    }

    /**
     * @param array<string, SymbolEntry> $literal
     * @param array<string, SymbolEntry> $normalized
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

    /** @param list<MethodEntry> $methods */
    private function withMethods(SymbolEntry $symbol, array $methods): SymbolEntry
    {
        return new SymbolEntry(
            kind: $symbol->kind,
            name: $symbol->name,
            fqn: $symbol->fqn,
            lineStart: $symbol->lineStart,
            lineEnd: $symbol->lineEnd,
            methods: $methods,
            extends: $symbol->extends,
            implements: $symbol->implements,
            parameters: $symbol->parameters,
            nativeReturnType: $symbol->nativeReturnType,
            phpDocReturnType: $symbol->phpDocReturnType,
            resolvedReturnType: $symbol->resolvedReturnType,
            attributes: $symbol->attributes,
            uses: $symbol->uses,
            templates: $symbol->templates,
            reconciliationStatus: $symbol->reconciliationStatus,
        );
    }

    private function fileMatchScore(FileEntry $file, string $lower, string $normalizedTerm): int
    {
        $score = max($this->matchScore($file->path, $lower, 8000), $this->matchScore($file->path, $normalizedTerm, 5000, true));
        foreach ($file->symbols as $symbol) {
            $score = max($score, $this->matchScore($symbol->name, $lower, 10000), $this->matchScore($symbol->fqn, $lower, 9500), $this->matchScore($symbol->name, $normalizedTerm, 7000, true), $this->matchScore($symbol->fqn, $normalizedTerm, 6500, true));
            foreach ($symbol->methods as $method) {
                $score = max($score, $this->matchScore($method->name, $lower, 9000), $this->matchScore($method->name, $normalizedTerm, 6000, true));
            }
        }
        return $score;
    }

    private function matchScore(string $value, string $term, int $base, bool $normalized = false): int
    {
        $candidate = $normalized ? $this->normalize($value) : strtolower($value);
        if ($candidate === '' || $term === '') {
            return 0;
        }
        if ($candidate === $term) {
            return $base + 1000;
        }
        $position = strpos($candidate, $term);
        if ($position === false) {
            return 0;
        }
        return $base + ($position === 0 ? 600 : 0) - min(500, $position * 10) - min(99, max(0, strlen($candidate) - strlen($term)));
    }

    private function matchesText(string $value, string $term, bool $normalized = false): bool
    {
        $candidate = $normalized ? $this->normalize($value) : strtolower($value);
        return $term !== '' && str_contains($candidate, $term);
    }

    private function normalize(string $value): string
    {
        return (string) preg_replace('~[^a-z0-9]+~', '', strtolower($value));
    }

    private function looksLikeTestPath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));
        $segments = explode('/', $normalized);
        $fileName = array_pop($segments);

        return in_array('tests', $segments, true)
            || in_array('test', $segments, true)
            || str_ends_with($fileName, 'test.php')
            || str_ends_with($fileName, 'cest.php');
    }
}
