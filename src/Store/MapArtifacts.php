<?php

declare(strict_types=1);

namespace voku\AgentMap\Store;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;

final readonly class MapArtifacts
{
    /**
     * @param array<string, mixed> $files
     * @param array<string, mixed> $symbols
     * @param array<string, mixed> $relations
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public array $files,
        public array $symbols,
        public array $relations,
        public array $diagnostics,
    ) {
    }

    public static function fromIndex(AgentMapIndex $index): self
    {
        $files = [];
        $symbols = [];
        $relations = [];

        foreach ($index->files as $file) {
            $absolute = rtrim($index->root, '/') . '/' . $file->path;
            $sha256 = hash_file('sha256', $absolute);
            if (!is_string($sha256)) {
                throw new RuntimeException('Unable to hash indexed file: ' . $file->path);
            }

            $symbolIds = [];
            foreach ($file->symbols as $symbol) {
                $symbolId = self::symbolId($symbol);
                $symbolIds[] = $symbolId;
                $symbols[] = self::symbolPayload($file->path, $symbol, $symbolId);
                $relations[] = self::relation(
                    sourceId: 'file:' . $file->path,
                    kind: 'defines',
                    targetIds: [$symbolId],
                    file: $file->path,
                    lineStart: $symbol->lineStart,
                    lineEnd: $symbol->lineEnd,
                );

                foreach ($symbol->methods as $method) {
                    $methodId = self::methodId($symbol, $method);
                    $symbolIds[] = $methodId;
                    $symbols[] = self::methodPayload($file->path, $symbol, $method, $methodId, $symbolId);
                    $relations[] = self::relation(
                        sourceId: $symbolId,
                        kind: 'declares_method',
                        targetIds: [$methodId],
                        file: $file->path,
                        lineStart: $method->lineStart,
                        lineEnd: $method->lineEnd,
                    );
                }

                foreach ($symbol->extends as $target) {
                    $relations[] = self::relation($symbolId, 'extends', ['type:' . ltrim($target, '\\')], $file->path, $symbol->lineStart, $symbol->lineEnd);
                }
                foreach ($symbol->implements as $target) {
                    $relations[] = self::relation($symbolId, 'implements', ['type:' . ltrim($target, '\\')], $file->path, $symbol->lineStart, $symbol->lineEnd);
                }
                foreach ($symbol->uses as $target) {
                    $relations[] = self::relation($symbolId, 'uses_trait', ['type:' . ltrim($target, '\\')], $file->path, $symbol->lineStart, $symbol->lineEnd);
                }
            }

            sort($symbolIds, SORT_STRING);
            $files[] = [
                'path' => $file->path,
                'sha256' => 'sha256:' . $sha256,
                'namespace' => $file->namespace,
                'symbol_ids' => array_values(array_unique($symbolIds)),
                'structural_status' => 'parsed',
                'semantic_status' => 'pending',
            ];
        }

        usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        usort($symbols, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        usort($relations, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return new self(
            files: ['schema_version' => '2.0', 'files' => $files],
            symbols: ['schema_version' => '2.0', 'symbols' => $symbols],
            relations: ['schema_version' => '2.0', 'relations' => $relations],
            diagnostics: ['schema_version' => '2.0', 'diagnostics' => []],
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function payloads(): array
    {
        return [
            'files' => $this->files,
            'symbols' => $this->symbols,
            'relations' => $this->relations,
            'diagnostics' => $this->diagnostics,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function symbolPayload(string $file, SymbolEntry $symbol, string $id): array
    {
        return [
            'id' => $id,
            'kind' => $symbol->kind,
            'name' => $symbol->name,
            'fqn' => ltrim($symbol->fqn, '\\'),
            'owner_id' => null,
            'file' => $file,
            'context_start_line' => $symbol->lineStart,
            'line_start' => $symbol->lineStart,
            'line_end' => $symbol->lineEnd,
            'extends' => $symbol->extends,
            'implements' => $symbol->implements,
            'uses' => $symbol->uses,
            'parameters' => $symbol->params,
            'native_return_type' => $symbol->returnType,
            'phpdoc_return_type' => null,
            'resolved_return_type' => $symbol->returnType,
            'attributes' => $symbol->attributes,
            'reconciliation_status' => 'structural_only',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function methodPayload(string $file, SymbolEntry $owner, MethodEntry $method, string $id, string $ownerId): array
    {
        return [
            'id' => $id,
            'kind' => 'method',
            'name' => $method->name,
            'fqn' => ltrim($owner->fqn, '\\') . '::' . $method->name,
            'owner_id' => $ownerId,
            'file' => $file,
            'context_start_line' => $method->lineStart,
            'line_start' => $method->lineStart,
            'line_end' => $method->lineEnd,
            'visibility' => $method->visibility,
            'static' => $method->static,
            'parameters' => $method->params,
            'native_return_type' => $method->returnType,
            'phpdoc_return_type' => null,
            'resolved_return_type' => $method->returnType,
            'attributes' => $method->attributes,
            'reconciliation_status' => 'structural_only',
        ];
    }

    private static function symbolId(SymbolEntry $symbol): string
    {
        return $symbol->kind . ':' . ltrim($symbol->fqn, '\\');
    }

    private static function methodId(SymbolEntry $owner, MethodEntry $method): string
    {
        return 'method:' . ltrim($owner->fqn, '\\') . '::' . $method->name;
    }

    /**
     * @param non-empty-list<string> $targetIds
     * @return array<string, mixed>
     */
    private static function relation(string $sourceId, string $kind, array $targetIds, string $file, int $lineStart, int $lineEnd): array
    {
        sort($targetIds, SORT_STRING);
        $identity = implode("\0", [$sourceId, $kind, implode(',', $targetIds), $file, (string) $lineStart, (string) $lineEnd]);

        return [
            'id' => 'relation:' . hash('sha256', $identity),
            'source_id' => $sourceId,
            'kind' => $kind,
            'target_ids' => $targetIds,
            'file' => $file,
            'line_start' => $lineStart,
            'line_end' => $lineEnd,
            'resolution' => 'structural_only',
        ];
    }
}
