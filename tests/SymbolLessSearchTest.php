<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Search\ChunkExtractor;
use voku\AgentMap\Search\SearchIndexStore;

final class SymbolLessSearchTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5; the derived index is optional by design.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-symbol-less-search-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);

        $lines = ["<?php\n", "return [\n"];
        for ($i = 1; $i <= 410; ++$i) {
            $lines[] = sprintf("    'filler_%03d' => 'value_%03d',\n", $i, $i);
        }
        $lines[] = "    'de' => 'decomposed german umlaut replacement',\n";
        $lines[] = "];\n";

        file_put_contents($this->root . '/src/languages.php', implode('', $lines));
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testMappedPhpFileWithoutSymbolsRemainsSearchableInBoundedSegments(): void
    {
        $index = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))
            ->build($this->root, ['src'], []);

        self::assertCount(1, $index->files);
        self::assertSame('src/languages.php', $index->files[0]->path);
        self::assertSame([], $index->files[0]->symbols, 'The fixture must exercise a mapped file with no declarations.');

        $chunks = array_values(array_filter(
            (new ChunkExtractor())->extract($index),
            static fn ($chunk): bool => $chunk->filePath === 'src/languages.php',
        ));

        self::assertCount(2, $chunks, 'A symbol-less file longer than one chunk must stay bounded instead of disappearing.');
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(400, $chunk->endLine - $chunk->startLine + 1);
            self::assertStringStartsWith('sha256:', $chunk->contentSha256);
        }

        $store = new SearchIndexStore($this->root . '/.agent-loop/map/search.sqlite');
        $store->replaceChunks($chunks, null);
        $rows = $store->searchLexical('decomposed german umlaut replacement', 5);

        self::assertNotSame([], $rows);
        self::assertSame('src/languages.php', $rows[0]['file_path']);
        self::assertGreaterThan(400, $rows[0]['start_line']);
    }
}
