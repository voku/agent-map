<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Search\ChunkExtractor;
use voku\AgentMap\Search\CodeChunk;
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
            static fn (CodeChunk $chunk): bool => $chunk->filePath === 'src/languages.php',
        ));

        self::assertCount(2, $chunks, 'A symbol-less file longer than one chunk must stay bounded instead of disappearing.');
        foreach ($chunks as $chunk) {
            self::assertSame(CodeChunk::KIND_FILE_BODY, $chunk->kind);
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

    public function testSchema10MigrationPreservesExistingChunksAndAcceptsFileBodies(): void
    {
        $database = $this->root . '/legacy-search.sqlite';
        $pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(
            'CREATE TABLE search_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )',
        );
        $pdo->exec("INSERT INTO search_meta (key, value) VALUES ('schema_version', '1.0')");
        $pdo->exec(
            "CREATE TABLE code_chunks (
                rowid INTEGER PRIMARY KEY,
                chunk_id TEXT NOT NULL UNIQUE,
                symbol_id TEXT NOT NULL,
                file_path TEXT NOT NULL,
                symbol_name TEXT NOT NULL,
                chunk_kind TEXT NOT NULL CHECK (
                    chunk_kind IN ('symbol_overview', 'method_body', 'function_body', 'method_fragment')
                ),
                start_line INTEGER NOT NULL,
                end_line INTEGER NOT NULL,
                source_sha256 TEXT NOT NULL,
                content_sha256 TEXT NOT NULL,
                signature TEXT NOT NULL,
                content TEXT NOT NULL
            )",
        );
        $pdo->exec(
            "INSERT INTO code_chunks
                (rowid, chunk_id, symbol_id, file_path, symbol_name, chunk_kind, start_line, end_line,
                 source_sha256, content_sha256, signature, content)
             VALUES
                (7, 'legacy#body:v1', 'method:Legacy::run', 'src/Legacy.php', 'Legacy::run', 'method_body', 10, 20,
                 'sha256:legacy-source', 'sha256:legacy-content', 'Legacy::run()', 'legacy searchable content')",
        );
        unset($pdo);

        $store = new SearchIndexStore($database);

        self::assertSame('1.1', $store->meta('schema_version'));
        $legacyRows = $store->searchLexical('legacy searchable content', 5);
        self::assertNotSame([], $legacyRows);
        self::assertSame('legacy#body:v1', $legacyRows[0]['chunk_id']);

        $fileChunk = CodeChunk::create(
            symbolId: 'file:src/data.php:segment:1',
            kind: CodeChunk::KIND_FILE_BODY,
            filePath: 'src/data.php',
            symbolName: 'src/data.php',
            startLine: 1,
            endLine: 2,
            sourceSha256: 'sha256:file-source',
            signature: 'file src/data.php',
            content: "<?php\nreturn ['needle' => 'file body accepted'];\n",
        );
        $store->replaceChunks([$fileChunk], ['src/data.php']);

        $fileRows = $store->searchLexical('file body accepted', 5);
        self::assertNotSame([], $fileRows);
        self::assertSame('src/data.php', $fileRows[0]['file_path']);
        self::assertSame(CodeChunk::KIND_FILE_BODY, $fileRows[0]['chunk_kind']);
    }
}
