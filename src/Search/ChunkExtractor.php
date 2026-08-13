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
 *
 * When pcntl_fork and stream_socket_pair are available in a CLI context and the file list is large
 * enough to justify the fork overhead, extraction is parallelised across worker processes (one per
 * logical CPU, capped at 8). Each worker receives a partition of the candidate file list, extracts
 * chunks with an isolated SourceMaterializer, and returns serialized results to the parent via a
 * UNIX stream socket pair. On any fork failure the parent falls back to sequential extraction
 * transparently. Environments without pcntl (Windows, shared hosting) always run sequentially.
 */
final class ChunkExtractor
{
    /**
     * Minimum candidate-file count before parallel extraction is attempted.
     * Below this threshold fork + IPC overhead exceeds any concurrency benefit.
     */
    private const PARALLEL_THRESHOLD = 50;

    /**
     * Maximum worker processes spawned regardless of detected CPU count.
     */
    private const MAX_WORKERS = 8;

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

        /** @var list<FileEntry> $candidates */
        $candidates = [];
        foreach ($index->files as $file) {
            if ($wanted !== null && !isset($wanted[$file->path])) {
                continue;
            }
            $candidates[] = $file;
        }

        $this->skippedPaths = [];

        if ($this->isParallelAvailable() && count($candidates) > self::PARALLEL_THRESHOLD) {
            $result = $this->runParallel($index->root, $candidates);
            if ($result !== null) {
                $this->skippedPaths = $result['skipped'];
                return $result['chunks'];
            }
            // Any fork failure falls through to the sequential path below.
        }

        return $this->runSequential($index->root, $candidates);
    }

    // ──────────────────────────────────────────────────────────────────────────────────────────────
    // Sequential path
    // ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Extracts chunks for all $candidates using a single process.
     *
     * This is the canonical extraction path and is always used as the fallback when process
     * concurrency is unavailable or a fork fails.
     *
     * @param list<FileEntry> $candidates
     * @return list<CodeChunk>
     */
    private function runSequential(string $root, array $candidates): array
    {
        $chunks = [];
        foreach ($candidates as $file) {
            $before = count($chunks);
            if ($file->symbols === []) {
        $fileChunks = $this->fileChunks($root, $file);
        if ($fileChunks === null) {
            $this->skippedPaths[] = $file->path;
        } else {
            foreach ($fileChunks as $chunk) {
                $chunks[] = $chunk;
            }
        }
        continue;
    }

            foreach ($file->symbols as $symbol) {
                if ($symbol->kind === 'function') {
                    $chunk = $this->functionChunk($root, $file, $symbol);
                    if ($chunk !== null) {
                        $chunks[] = $chunk;
                    }
                    continue;
                }

                $overview = $this->overviewChunk($root, $file, $symbol);
                if ($overview !== null) {
                    $chunks[] = $overview;
                }

                foreach ($symbol->methods as $method) {
                    $chunk = $this->methodChunk($root, $file, $symbol, $method);
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

    // ──────────────────────────────────────────────────────────────────────────────────────────────
    // Parallel path
    // ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Spawns worker processes to extract chunks in parallel.
     *
     * Returns null if any fork fails (caller falls back to sequential). On success returns an
     * aggregated array of all chunks and skipped paths in partition order.
     *
     * Protocol:
     *   1. For each partition create a UNIX stream socket pair.
     *   2. Fork. Child writes serialized {chunks, skipped} to its socket end and exits(0).
     *   3. Parent reads from its socket end after all children are spawned.
     *   4. Parent reaps all children via pcntl_waitpid.
     *
     * @param list<FileEntry> $candidates
     * @return array{chunks: list<CodeChunk>, skipped: list<string>}|null
     */
    private function runParallel(string $root, array $candidates): ?array
    {
        $workerCount = max(1, min(self::MAX_WORKERS, $this->detectCpuCount(), count($candidates)));
        $partitions  = $this->partitionFiles($candidates, $workerCount);

        /**
         * Track spawned workers as {pid, socket} pairs. The socket is the parent's end of each
         * UNIX stream pair; it is closed inside the child before that child writes to its own end.
         *
         * @var list<array{pid: int, socket: resource}> $spawned
         */
        $spawned = [];

        foreach ($partitions as $partition) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                // Close all already-collected parent sockets before reaping.
                foreach ($spawned as $w) {
                    fclose($w['socket']);
                }
                $this->reapAll($spawned);
                return null;
            }
            [$childSocket, $parentSocket] = $pair;

            $pid = pcntl_fork();
            if ($pid === -1) {
                // Close the pair we just created, and all previously-collected parent sockets.
                fclose($childSocket);
                fclose($parentSocket);
                foreach ($spawned as $w) {
                    fclose($w['socket']);
                }
                $this->reapAll($spawned);
                return null;
            }

            if ($pid === 0) {
                // ── CHILD ────────────────────────────────────────────────────────────────────────
                // Close the parent's socket end so only the child holds a reference.
                fclose($parentSocket);

                // Use a fresh ChunkExtractor so the SourceMaterializer cache is isolated from both
                // the parent and every sibling worker.
                $worker  = new self(new SourceMaterializer());
                $chunks  = $worker->runSequential($root, $partition);
                $skipped = $worker->skippedPaths();

                $payload = serialize(['chunks' => $chunks, 'skipped' => $skipped]);
                fwrite($childSocket, $payload);
                fclose($childSocket);
                exit(0);
                // ── END CHILD ────────────────────────────────────────────────────────────────────
            }

            // Parent: close the child's socket end; keep the parent end for reading.
            fclose($childSocket);
            $spawned[] = ['pid' => $pid, 'socket' => $parentSocket];
        }

        // Collect results in spawn order (preserves file-list ordering).
        $allChunks  = [];
        $allSkipped = [];

        foreach ($spawned as $worker) {
            $raw = '';
            while (!feof($worker['socket'])) {
                $block = fread($worker['socket'], 65_536);
                if ($block === false || $block === '') {
                    break;
                }
                $raw .= $block;
            }
            fclose($worker['socket']);

            if ($raw === '') {
                continue;
            }

            $decoded = @unserialize($raw);
            if (!is_array($decoded)) {
                continue;
            }

            foreach (is_array($decoded['chunks'] ?? null) ? $decoded['chunks'] : [] as $chunk) {
                if ($chunk instanceof CodeChunk) {
                    $allChunks[] = $chunk;
                }
            }
            foreach (is_array($decoded['skipped'] ?? null) ? $decoded['skipped'] : [] as $path) {
                if (is_string($path)) {
                    $allSkipped[] = $path;
                }
            }
        }

        $this->reapAll($spawned);

        return ['chunks' => $allChunks, 'skipped' => $allSkipped];
    }

    // ──────────────────────────────────────────────────────────────────────────────────────────────
    // Concurrency helpers
    // ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Returns true only when both pcntl_fork/pcntl_waitpid and stream_socket_pair with UNIX
     * domain sockets are callable. This is reliably true in CLI PHP on Linux/macOS (including
     * WSL2) and reliably false on Windows or in environments where pcntl is disabled.
     */
    private function isParallelAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('stream_socket_pair')
            && defined('STREAM_PF_UNIX');
    }

    /**
     * Detects the number of logical CPU cores.
     *
     * Tries (in order): /proc/cpuinfo processor-count (Linux/WSL), `nproc` shell command, `sysctl`
     * on macOS/BSD, then falls back to 1 so the caller always gets a usable value.
     */
    private function detectCpuCount(): int
    {
        // /proc/cpuinfo is the fastest probe on Linux/WSL; no subprocess needed.
        if (is_readable('/proc/cpuinfo')) {
            $info = @file_get_contents('/proc/cpuinfo');
            if (is_string($info)) {
                $count = substr_count($info, "\nprocessor\t:");
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // `nproc` — portable on Linux even outside /proc environments.
        $nproc = @shell_exec('nproc 2>/dev/null');
        if (is_string($nproc)) {
            $count = (int) trim($nproc);
            if ($count > 0) {
                return $count;
            }
        }

        // `sysctl` — macOS / BSD.
        $sysctl = @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null');
        if (is_string($sysctl)) {
            $count = (int) trim($sysctl);
            if ($count > 0) {
                return $count;
            }
        }

        return 1;
    }

    /**
     * Splits $files into at most $workers non-empty, roughly equal partitions.
     *
     * @param list<FileEntry> $files
     * @return list<list<FileEntry>>
     */
    private function partitionFiles(array $files, int $workers): array
    {
        $total = count($files);
        if ($total === 0) {
            return [];
        }

        // Guard: never exceed the number of available files and ensure at least 1 worker.
        $workers    = max(1, min($workers, $total));
        $baseSize   = intdiv($total, $workers);
        $remainder  = $total % $workers;
        $partitions = [];
        $offset     = 0;

        for ($i = 0; $i < $workers; ++$i) {
            $size = $baseSize + ($i < $remainder ? 1 : 0);
            if ($size > 0) {
                $partitions[] = array_slice($files, $offset, $size);
            }
            $offset += $size;
        }

        return $partitions;
    }

    /**
     * Reaps all spawned child processes via pcntl_waitpid.
     *
     * This must be called AFTER all parent-side sockets have already been closed (either in the
     * normal collect loop, or explicitly in the error-bail paths). Calling fclose() here would
     * attempt to close already-closed resources.
     *
     * @param list<array{pid: int, socket: resource}> $spawned
     */
    private function reapAll(array $spawned): void
    {
        foreach ($spawned as $worker) {
            $status = 0;
            pcntl_waitpid($worker['pid'], $status);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────────────────────────
    // Chunk builders (shared by sequential and parallel paths)
    // ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
 * @return list<CodeChunk>|null null when the mapped source is stale
 */
    private function fileChunks(string $root, FileEntry $file): ?array
    {
        $chunks = [];
        $startLine = 1;
        $segment = 1;

        while (true) {
            $requestedEndLine = $startLine + ChunkPolicy::MAX_FILE_LINES - 1;
            $slice = $this->slice($root, $file, $startLine, $requestedEndLine);
            if ($slice === null) {
                return null;
            }
            if ($slice['content'] === '') {
                break;
            }

            $symbolId = 'file:' . $file->path . ':segment:' . $segment;
            $chunks[] = CodeChunk::create(
                symbolId: $symbolId,
                kind: CodeChunk::KIND_FILE_BODY,
                filePath: $file->path,
                symbolName: $file->path,
                startLine: $slice['start'],
                endLine: $slice['end'],
                sourceSha256: $file->sha256,
                signature: 'file ' . $file->path,
                content: $slice['content'],
            );

            if ($slice['end'] < $requestedEndLine) {
                break;
            }
            $startLine = $requestedEndLine + 1;
            ++$segment;
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
