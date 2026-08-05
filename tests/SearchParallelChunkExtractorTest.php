<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Search\ChunkExtractor;
use voku\AgentMap\Search\CodeChunk;

/**
 * Tests for the parallel chunk-extraction path of ChunkExtractor.
 *
 * Correctness contract: parallel extraction must produce output that is 100 % identical in content
 * (same set of chunk IDs) to sequential extraction. Order may differ between the two modes because
 * workers return results in fork-completion order, so the comparison is set-based.
 *
 * The tests that exercise the parallel path are skipped automatically when pcntl is unavailable
 * (e.g. native Windows PHP or environments where the extension is disabled), so the suite stays
 * green everywhere.
 */
final class SearchParallelChunkExtractorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-parallel-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);

        // Write enough PHP files so that the parallel threshold (50 files) is comfortably exceeded,
        // making it possible to assert on the parallel code path.
        for ($i = 1; $i <= 60; ++$i) {
            $class = 'Worker' . $i;
            file_put_contents(
                $this->root . '/src/' . $class . '.php',
                <<<PHP
                <?php
                declare(strict_types=1);
                namespace Demo\Workers;
                final class {$class}
                {
                    public function run(int \$n): int
                    {
                        return \$n * {$i};
                    }
                    public function reset(): void {}
                }
                PHP,
            );
        }

        // A file with no parseable symbols (empty namespace, no class) — exercises the skipped-path
        // branch without polluting the chunk list.
        file_put_contents($this->root . '/src/helpers.php', "<?php\n// intentionally empty helper\n");
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    // ──────────────────────────────────────────────────────────────────────────────────────────────
    // Parallel path — only run when pcntl is available
    // ──────────────────────────────────────────────────────────────────────────────────────────────

    public function testParallelExtractionProducesSameChunkIdsAsSequential(): void
    {
        if (!$this->isPcntlAvailable()) {
            self::markTestSkipped('pcntl extension is not available; parallel path cannot be exercised.');
        }

        $index = $this->buildIndex();

        // Sequential baseline.
        $sequential = (new ChunkExtractor())->extract($index);
        $seqIds = $this->sortedIds($sequential);

        // Parallel run: the index has 60 PHP files with symbols, well above the 50-file threshold.
        $extractor = new ChunkExtractor();
        $parallel  = $extractor->extract($index);
        $parIds    = $this->sortedIds($parallel);

        self::assertSame($seqIds, $parIds, 'Parallel and sequential extraction must produce identical chunk IDs.');
    }

    public function testParallelChunkContentMatchesSequentialContent(): void
    {
        if (!$this->isPcntlAvailable()) {
            self::markTestSkipped('pcntl extension is not available; parallel path cannot be exercised.');
        }

        $index = $this->buildIndex();

        $seqChunks  = (new ChunkExtractor())->extract($index);
        $parChunks  = (new ChunkExtractor())->extract($index);

        $seqById = [];
        foreach ($seqChunks as $chunk) {
            $seqById[$chunk->chunkId] = $chunk;
        }
        $parById = [];
        foreach ($parChunks as $chunk) {
            $parById[$chunk->chunkId] = $chunk;
        }

        self::assertSame(array_keys($seqById), array_keys($parById));

        foreach ($seqById as $id => $seqChunk) {
            $parChunk = $parById[$id] ?? null;
            self::assertNotNull($parChunk, 'Chunk missing in parallel output: ' . $id);
            self::assertSame($seqChunk->content, $parChunk->content, 'Content mismatch for ' . $id);
            self::assertSame($seqChunk->contentSha256, $parChunk->contentSha256, 'Fingerprint mismatch for ' . $id);
            self::assertSame($seqChunk->filePath, $parChunk->filePath, 'File-path mismatch for ' . $id);
        }
    }

    public function testSkippedPathsAreReportedByParallelExtractor(): void
    {
        if (!$this->isPcntlAvailable()) {
            self::markTestSkipped('pcntl extension is not available; parallel path cannot be exercised.');
        }

        $index = $this->buildIndex();

        // Sequential skipped paths.
        $seqExtractor = new ChunkExtractor();
        $seqExtractor->extract($index);
        $seqSkipped = $seqExtractor->skippedPaths();

        // Parallel skipped paths.
        $parExtractor = new ChunkExtractor();
        $parExtractor->extract($index);
        $parSkipped = $parExtractor->skippedPaths();

        sort($seqSkipped);
        sort($parSkipped);

        self::assertSame($seqSkipped, $parSkipped, 'skippedPaths() must agree between sequential and parallel runs.');
    }

    // ──────────────────────────────────────────────────────────────────────────────────────────────
    // Edge cases — always run (sequential path, no pcntl required)
    // ──────────────────────────────────────────────────────────────────────────────────────────────

    public function testEmptyIndexYieldsNoChunksAndNoSkips(): void
    {
        $emptyRoot = sys_get_temp_dir() . '/agent-map-empty-' . bin2hex(random_bytes(6));
        mkdir($emptyRoot . '/src', 0o775, true);

        try {
            $index = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))
                ->build($emptyRoot, ['src'], []);

            $extractor = new ChunkExtractor();
            $chunks    = $extractor->extract($index);

            self::assertSame([], $chunks);
            self::assertSame([], $extractor->skippedPaths());
        } finally {
            @rmdir($emptyRoot . '/src');
            @rmdir($emptyRoot);
        }
    }

    public function testSingleFileExtractsCorrectly(): void
    {
        // Use a fresh root with exactly one PHP file — well below the parallel threshold, so this
        // exercises the sequential path deterministically.
        $singleRoot = sys_get_temp_dir() . '/agent-map-single-' . bin2hex(random_bytes(6));
        mkdir($singleRoot . '/src', 0o775, true);
        file_put_contents($singleRoot . '/src/Ping.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Demo;
        final class Ping
        {
            public function ping(): string { return 'pong'; }
        }
        PHP);

        try {
            $index = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))
                ->build($singleRoot, ['src'], []);

            $extractor = new ChunkExtractor();
            $chunks    = $extractor->extract($index);

            self::assertNotSame([], $chunks);

            $ids = array_map(static fn (CodeChunk $c): string => $c->chunkId, $chunks);
            self::assertContains('class:Demo\\Ping#overview:v1', $ids);
            self::assertContains('method:Demo\\Ping::ping#body:v1', $ids);
        } finally {
            @unlink($singleRoot . '/src/Ping.php');
            @rmdir($singleRoot . '/src');
            @rmdir($singleRoot);
        }
    }

    public function testOnlyPathsFilterIsRespectedInSequentialMode(): void
    {
        $index = $this->buildIndex();

        $allChunks = (new ChunkExtractor())->extract($index);
        // Pick one concrete path from the index.
        $onePath = $allChunks[0]->filePath ?? null;
        if ($onePath === null) {
            self::markTestSkipped('Index produced no chunks to filter on.');
        }

        $filtered = (new ChunkExtractor())->extract($index, [$onePath]);

        foreach ($filtered as $chunk) {
            self::assertSame($onePath, $chunk->filePath, 'Only chunks for the requested path must be returned.');
        }
    }

    // ──────────────────────────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────────────────────────

    private function buildIndex(): AgentMapIndex
    {
        return (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))
            ->build($this->root, ['src'], []);
    }

    private function isPcntlAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('stream_socket_pair')
            && defined('STREAM_PF_UNIX');
    }

    /**
     * @param list<CodeChunk> $chunks
     * @return list<string>
     */
    private function sortedIds(array $chunks): array
    {
        $ids = array_map(static fn (CodeChunk $c): string => $c->chunkId, $chunks);
        sort($ids);
        return $ids;
    }
}
