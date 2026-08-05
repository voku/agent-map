<?php

declare(strict_types=1);

namespace voku\AgentMap\Search;

use voku\AgentMap\Context\SourceMaterializer;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;

/**
 * Turns canonical map symbols into searchable chunks.
 *
 * Deliberately not a parser. Every chunk comes from a symbol the map already recorded, with the
 * line range the map already resolved, read through the materializer that already verifies the file
 * hash. A second parsing pipeline would be a second answer to "what is in this file", and the two
 * would disagree the first time one of them was upgraded.
 */
final class ChunkExtractor
{
    /** @var list<string> */
    private array $skippedPaths = [];

    public function __construct(
        private readonly SourceMaterializer $materializer = new SourceMaterializer(),
    ) {
    }

    /**
     * Files whose source no longer matches the map, from the last extract() call.
     *
     * Silently producing fewer chunks would look identical to a file that simply has no symbols, so
     * the caller can tell the difference and say so.
     *
     * @return list<string>
     */
    public function skippedPaths(): array
    {
        return $this->skippedPaths;
    }

    /**
     * @param list<string>|null $onlyPaths restrict extraction to these files, for incremental refresh
     *
     * @return list<CodeChunk>
     */
    public function extract(AgentMapIndex $index, ?array $onlyPaths = null): array
    {
        $wanted = $onlyPaths === null ? null : array_fill_keys($onlyPaths, true);

        $this->skippedPaths = [];
        $chunks = [];
        foreach ($index->files as $file) {
            if ($wanted !== null && !isset($wanted[$file->path])) {
                continue;
            }

            $before = count($chunks);
            foreach ($file->symbols as $symbol) {
                if ($symbol->kind === 'function') {
                    $chunk = $this->functionChunk($index->root, $file, $symbol);
                    if ($chunk !== null) {
                        $chunks[] = $chunk;
                    }
                    continue;
                }

                $overview = $this->overviewChunk($index->root, $file, $symbol);
                if ($overview !== null) {
                    $chunks[] = $overview;
                }

                foreach ($symbol->methods as $method) {
                    $chunk = $this->methodChunk($index->root, $file, $symbol, $method);
                    if ($chunk !== null) {
                        $chunks[] = $chunk;
                    }
                }
            }

            if ($file->symbols !== [] && count($chunks) === $before) {
                $this->skippedPaths[] = $file->path;
            }
        }

        return $chunks;
    }

    /**
     * The declaration plus its method signatures, without any body: this is what answers "what is
     * this class for", and keeping bodies out of it stops one large class from dominating every
     * lexical hit.
     */
    private function overviewChunk(string $root, FileEntry $file, SymbolEntry $symbol): ?CodeChunk
    {
        $signature = $this->symbolSignature($symbol);
        $lines = [$signature];
        foreach ($symbol->methods as $method) {
            $lines[] = '    ' . $this->methodSignature($method);
        }

        $head = $this->slice($root, $file, $symbol->lineStart, min($symbol->lineEnd, $symbol->lineStart + 20));
        if ($head === null) {
            return null;
        }

        return CodeChunk::create(
            symbolId: $symbol->id(),
            kind: CodeChunk::KIND_SYMBOL_OVERVIEW,
            filePath: $file->path,
            symbolName: $symbol->fqn,
            startLine: $symbol->lineStart,
            endLine: $symbol->lineEnd,
            sourceSha256: $file->sha256,
            signature: $signature,
            content: $head['content'] . "\n" . implode("\n", $lines),
        );
    }

    private function methodChunk(string $root, FileEntry $file, SymbolEntry $symbol, MethodEntry $method): ?CodeChunk
    {
        $endLine = min($method->lineEnd, $method->lineStart + ChunkPolicy::MAX_BODY_LINES);
        $slice = $this->slice($root, $file, $method->lineStart, $endLine);
        if ($slice === null) {
            return null;
        }

        return CodeChunk::create(
            symbolId: $symbol->methodId($method),
            kind: CodeChunk::KIND_METHOD_BODY,
            filePath: $file->path,
            symbolName: $symbol->fqn . '::' . $method->name,
            startLine: $method->lineStart,
            endLine: $method->lineEnd,
            sourceSha256: $file->sha256,
            signature: $this->methodSignature($method),
            content: $slice['content'],
        );
    }

    private function functionChunk(string $root, FileEntry $file, SymbolEntry $symbol): ?CodeChunk
    {
        $endLine = min($symbol->lineEnd, $symbol->lineStart + ChunkPolicy::MAX_BODY_LINES);
        $slice = $this->slice($root, $file, $symbol->lineStart, $endLine);
        if ($slice === null) {
            return null;
        }

        return CodeChunk::create(
            symbolId: $symbol->id(),
            kind: CodeChunk::KIND_FUNCTION_BODY,
            filePath: $file->path,
            symbolName: $symbol->fqn,
            startLine: $symbol->lineStart,
            endLine: $symbol->lineEnd,
            sourceSha256: $file->sha256,
            signature: $this->symbolSignature($symbol),
            content: $slice['content'],
        );
    }

    /**
     * @return array{start: int, end: int, content: string}|null null when the file moved on since the
     *                                                          map was built; the caller refreshes the
     *                                                          map rather than indexing stale source
     */
    private function slice(string $root, FileEntry $file, int $startLine, int $endLine): ?array
    {
        try {
            return $this->materializer->materialize($root, $file, $startLine, $endLine, false);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function symbolSignature(SymbolEntry $symbol): string
    {
        $signature = $symbol->kind . ' ' . $symbol->fqn;
        if ($symbol->extends !== []) {
            $signature .= ' extends ' . implode(', ', $symbol->extends);
        }
        if ($symbol->implements !== []) {
            $signature .= ' implements ' . implode(', ', $symbol->implements);
        }

        return $signature;
    }

    private function methodSignature(MethodEntry $method): string
    {
        $returnType = $method->displayReturnType();

        return $method->visibility
            . ($method->static ? ' static' : '')
            . ' ' . $method->name
            . '(' . implode(', ', $method->displayParameters()) . ')'
            . ($returnType === null ? '' : ': ' . $returnType);
    }
}
