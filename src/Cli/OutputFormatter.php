<?php

declare(strict_types=1);

namespace voku\AgentMap\Cli;

use HelgeSverre\Toon\Toon;
use RuntimeException;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;

final readonly class OutputFormatter
{
    /**
     * @param array<string, mixed> $payload
     */
    public function render(array $payload, string $format): string
    {
        return match ($format) {
            'json' => $this->json($payload),
            'markdown' => $this->markdown($payload),
            'toon' => Toon::encode($payload) . "\n",
            default => $this->text($payload),
        };
    }

    /**
     * @param list<FileEntry> $files
     * @return list<array{path: string, namespace: string, symbols: list<array{kind: string, name: string, fqn: string, line_start: int, line_end: int, extends: list<string>, implements: list<string>, uses: list<string>, params: list<string>, return_type: ?string, attributes: list<string>, methods: list<array{name: string, visibility: string, line_start: int, line_end: int, static: bool, params: list<string>, return_type: ?string, attributes: list<string>}>, methods_omitted: int}>, symbols_omitted: int}>
     */
    public function filesPayload(array $files, int $symbolLimit = 10, int $methodLimit = 10): array
    {
        return array_map(fn (FileEntry $file): array => $this->filePayload($file, $symbolLimit, $methodLimit), $files);
    }

    /**
     * @return array{path: string, namespace: string, symbols: list<array{kind: string, name: string, fqn: string, line_start: int, line_end: int, extends: list<string>, implements: list<string>, uses: list<string>, params: list<string>, return_type: ?string, attributes: list<string>, methods: list<array{name: string, visibility: string, line_start: int, line_end: int, static: bool, params: list<string>, return_type: ?string, attributes: list<string>}>, methods_omitted: int}>, symbols_omitted: int}
     */
    public function filePayload(FileEntry $file, int $symbolLimit = 10, int $methodLimit = 10): array
    {
        $symbols = array_slice($file->symbols, 0, $symbolLimit);

        return [
            'path' => $file->path,
            'namespace' => $file->namespace,
            'symbols' => array_map(fn (SymbolEntry $symbol): array => $this->symbolPayload($symbol, $methodLimit), $symbols),
            'symbols_omitted' => max(0, count($file->symbols) - count($symbols)),
        ];
    }

    /**
     * @return array{kind: string, name: string, fqn: string, line_start: int, line_end: int, extends: list<string>, implements: list<string>, uses: list<string>, params: list<string>, return_type: ?string, attributes: list<string>, methods: list<array{name: string, visibility: string, line_start: int, line_end: int, static: bool, params: list<string>, return_type: ?string, attributes: list<string>}>, methods_omitted: int}
     */
    public function symbolPayload(SymbolEntry $symbol, int $methodLimit = 10): array
    {
        $methods = $methodLimit === 0 ? [] : array_slice($symbol->methods, 0, $methodLimit);

        return [
            'kind' => $symbol->kind,
            'name' => $symbol->name,
            'fqn' => $symbol->fqn,
            'line_start' => $symbol->lineStart,
            'line_end' => $symbol->lineEnd,
            'extends' => $symbol->extends,
            'implements' => $symbol->implements,
            'uses' => $symbol->uses,
            'params' => $symbol->params,
            'return_type' => $symbol->returnType,
            'attributes' => $symbol->attributes,
            'methods' => array_map(static fn (MethodEntry $method): array => [
                'name' => $method->name,
                'visibility' => $method->visibility,
                'line_start' => $method->lineStart,
                'line_end' => $method->lineEnd,
                'static' => $method->static,
                'params' => $method->params,
                'return_type' => $method->returnType,
                'attributes' => $method->attributes,
            ], $methods),
            'methods_omitted' => max(0, count($symbol->methods) - count($methods)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode output JSON.');
        }

        return $json . "\n";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function text(array $payload): string
    {
        return match ($payload['type'] ?? '') {
            'summary' => $this->summaryText($payload),
            'stats' => $this->statsText($payload),
            'related' => $this->relatedText($payload),
            'changed' => $this->changedText($payload),
            'query' => $this->matchTypeNote($payload) . $this->filesText(is_array($payload['files'] ?? null) ? $payload['files'] : [], (bool) ($payload['include_namespace'] ?? false)),
            default => $this->filesText(is_array($payload['files'] ?? null) ? $payload['files'] : [], (bool) ($payload['include_namespace'] ?? false)),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function matchTypeNote(array $payload): string
    {
        $matchType = $payload['match_type'] ?? null;
        if ($matchType === 'mixed') {
            return 'NOTE: includes case/separator-normalized matches alongside literal results'
                . " (e.g. isDevUser ~ is_dev_user ~ ->is_dev_user). Verify the normalized hints.\n\n";
        }

        if ($matchType !== 'normalized') {
            return '';
        }

        return 'NOTE: no exact match for "' . (string) ($payload['query'] ?? '') . '" — showing case/separator-normalized'
            . " matches instead (e.g. isDevUser ~ is_dev_user ~ ->is_dev_user). Verify these are the symbol you meant.\n\n";
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function markdown(array $payload): string
    {
        $title = (string) ($payload['title'] ?? 'Agent Map');
        return '## ' . $title . "\n\n" . str_replace("\n", "\n", $this->text($payload));
    }

    /**
     * @param array<int|string, mixed> $files
     */
    private function filesText(array $files, bool $includeNamespace): string
    {
        $out = '';
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $out .= (string) ($file['path'] ?? '') . "\n";
            if ($includeNamespace && ($file['namespace'] ?? '') !== '') {
                $out .= '  namespace ' . (string) $file['namespace'] . "\n\n";
            }

            foreach (is_array($file['symbols'] ?? null) ? $file['symbols'] : [] as $symbol) {
                if (!is_array($symbol)) {
                    continue;
                }

                $symbolName = $includeNamespace ? (string) ($symbol['name'] ?? '') : (string) ($symbol['fqn'] ?? '');
                $kind = (string) ($symbol['kind'] ?? '');
                $suffix = $kind === 'function' ? $this->signatureSuffix($symbol) : $this->classRelationsSuffix($symbol);
                $out .= $this->attributesLine('  ', $symbol);
                $out .= '  ' . $kind . ' ' . $symbolName . $suffix . $this->lineRangeSuffix($symbol) . "\n";
                foreach (is_array($symbol['methods'] ?? null) ? $symbol['methods'] : [] as $method) {
                    if (is_array($method)) {
                        $static = ($method['static'] ?? false) ? 'static ' : '';
                        $out .= $this->attributesLine('    ', $method);
                        $out .= '    ' . $this->visibilityMarker((string) ($method['visibility'] ?? 'public')) . ' ' . $static . (string) ($method['name'] ?? '') . $this->signatureSuffix($method) . $this->lineRangeSuffix($method) . "\n";
                    }
                }

                $omitted = (int) ($symbol['methods_omitted'] ?? 0);
                if ($omitted > 0) {
                    $out .= '    ... ' . $omitted . " more method(s)\n";
                }
            }

            $symbolsOmitted = (int) ($file['symbols_omitted'] ?? 0);
            if ($symbolsOmitted > 0) {
                $out .= '  ... ' . $symbolsOmitted . " more symbol(s)\n";
            }
        }

        return $out === '' ? "No matches\n" : $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function summaryText(array $payload): string
    {
        $out = "Agent Map Summary\n\n";
        $out .= 'Files indexed: ' . (int) ($payload['files_indexed'] ?? 0) . "\n";
        $out .= 'Symbols: ' . (int) ($payload['symbols'] ?? 0) . "\n";
        $out .= 'Classes: ' . (int) ($payload['classes'] ?? 0) . "\n";
        $out .= 'Interfaces: ' . (int) ($payload['interfaces'] ?? 0) . "\n";
        $out .= 'Traits: ' . (int) ($payload['traits'] ?? 0) . "\n";
        $out .= 'Enums: ' . (int) ($payload['enums'] ?? 0) . "\n";
        $out .= 'Functions: ' . (int) ($payload['functions'] ?? 0) . "\n\n";

        $out .= "Top namespaces:\n";
        foreach (is_array($payload['top_namespaces'] ?? null) ? $payload['top_namespaces'] : [] as $row) {
            if (is_array($row)) {
                $out .= '  ' . str_pad((string) ($row['namespace'] ?? ''), 36) . ' ' . (int) ($row['symbols'] ?? 0) . " symbols\n";
            }
        }

        $out .= "\nTop directories:\n";
        foreach (is_array($payload['top_directories'] ?? null) ? $payload['top_directories'] : [] as $row) {
            if (is_array($row)) {
                $out .= '  ' . str_pad((string) ($row['directory'] ?? ''), 36) . ' ' . (int) ($row['files'] ?? 0) . " files\n";
            }
        }

        $out .= "\nInteresting entrypoints:\n";
        foreach (is_array($payload['entrypoints'] ?? null) ? $payload['entrypoints'] : [] as $entrypoint) {
            $out .= '  ' . (string) $entrypoint . "\n";
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function statsText(array $payload): string
    {
        $out = 'Files: ' . (int) ($payload['files'] ?? 0) . "\n";
        $out .= 'Symbols: ' . (int) ($payload['symbols'] ?? 0) . "\n";
        $out .= 'Methods: ' . (int) ($payload['methods'] ?? 0) . "\n";
        $out .= 'Index size: ' . (string) ($payload['index_size'] ?? 'unknown') . "\n";
        $out .= "Largest files:\n";
        foreach (is_array($payload['largest_files'] ?? null) ? $payload['largest_files'] : [] as $row) {
            if (is_array($row)) {
                $out .= '  ' . str_pad((string) ($row['path'] ?? ''), 40) . ' ' . (int) ($row['symbols'] ?? 0) . " symbols\n";
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function relatedText(array $payload): string
    {
        $out = 'Query: ' . (string) ($payload['query'] ?? '') . "\n\n";
        $out .= $this->matchTypeNote($payload);
        foreach (['primary' => 'Primary', 'likely_tests' => 'Likely tests', 'same_namespace' => 'Same namespace', 'mentions' => 'Mentions'] as $key => $label) {
            $out .= $label . ":\n";
            $out .= $this->filesText(is_array($payload[$key] ?? null) ? $payload[$key] : [], false) . "\n";
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function changedText(array $payload): string
    {
        $out = "Changed PHP files:\n\n";
        $out .= $this->filesText(is_array($payload['files'] ?? null) ? $payload['files'] : [], false);
        foreach (is_array($payload['unindexed'] ?? null) ? $payload['unindexed'] : [] as $path) {
            $out .= (string) $path . "\n  not indexed\n";
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function attributesLine(string $indent, array $entry): string
    {
        $attributes = is_array($entry['attributes'] ?? null) ? $entry['attributes'] : [];
        if ($attributes === []) {
            return '';
        }

        return $indent . '#[' . implode(', ', array_map('strval', $attributes)) . "]\n";
    }

    /**
     * @param array<string, mixed> $symbol
     */
    private function classRelationsSuffix(array $symbol): string
    {
        $extends = is_array($symbol['extends'] ?? null) ? $symbol['extends'] : [];
        $implements = is_array($symbol['implements'] ?? null) ? $symbol['implements'] : [];
        $uses = is_array($symbol['uses'] ?? null) ? $symbol['uses'] : [];

        $suffix = '';
        if ($extends !== []) {
            $suffix .= ' extends ' . implode(', ', array_map('strval', $extends));
        }

        if ($implements !== []) {
            $suffix .= ' implements ' . implode(', ', array_map('strval', $implements));
        }

        if ($uses !== []) {
            $suffix .= ' uses ' . implode(', ', array_map('strval', $uses));
        }

        return $suffix;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function lineRangeSuffix(array $entry): string
    {
        $start = $entry['line_start'] ?? null;
        $end = $entry['line_end'] ?? null;
        if (!is_int($start) || !is_int($end) || $start <= 0) {
            return '';
        }

        return $start === $end ? "  #L{$start}" : "  #L{$start}-{$end}";
    }

    /**
     * @param array<string, mixed> $signature
     */
    private function signatureSuffix(array $signature): string
    {
        $params = is_array($signature['params'] ?? null) ? implode(', ', array_map('strval', $signature['params'])) : '';
        $returnType = $signature['return_type'] ?? null;

        return '(' . $params . ')' . ($returnType !== null ? ': ' . $returnType : '');
    }

    private function visibilityMarker(string $visibility): string
    {
        return match ($visibility) {
            'private' => '-',
            'protected' => '#',
            default => '+',
        };
    }
}
