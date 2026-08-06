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
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Search\QueryPlanner;
use voku\AgentMap\Search\Embedding\SqliteVecBinary;
use voku\AgentMap\Search\SearchIndexStore;

final class SearchIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5; the derived index is optional by design.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-search-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/RetryHandler.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class RetryHandler
        {
            public function execute(int $attempts): bool
            {
                // waits between attempts before giving up on the remote call
                return $attempts > 0;
            }

            public function reset(): void
            {
            }
        }
        PHP);
        file_put_contents($this->root . '/src/Mailer.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class Mailer
        {
            public function send(string $recipient): bool
            {
                return $recipient !== '';
            }
        }
        PHP);
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

    public function testChunksAreDerivedFromCanonicalSymbolsAndKeepStableIds(): void
    {
        $chunks = (new ChunkExtractor())->extract($this->index());

        $ids = array_map(static fn (CodeChunk $chunk): string => $chunk->chunkId, $chunks);
        self::assertContains('class:Demo\\RetryHandler#overview:v1', $ids);
        self::assertContains('method:Demo\\RetryHandler::execute#body:v1', $ids);
        self::assertContains('method:Demo\\Mailer::send#body:v1', $ids);

        foreach ($chunks as $chunk) {
            self::assertStringStartsWith('sha256:', $chunk->contentSha256);
            self::assertGreaterThan(0, $chunk->startLine);
        }
    }

    public function testAFingerprintSurvivesReformattingButNotAContentChange(): void
    {
        $first = CodeChunk::fingerprint('method:Demo\\A::b', "line one\nline two\n");
        $reformatted = CodeChunk::fingerprint('method:Demo\\A::b', "line one\r\nline two\r\n   ");
        $changed = CodeChunk::fingerprint('method:Demo\\A::b', "line one\nline three\n");

        self::assertSame($first, $reformatted, 'line endings must not invalidate a cached embedding');
        self::assertNotSame($first, $changed);
    }

    public function testLexicalSearchFindsAChunkByItsVocabulary(): void
    {
        $store = $this->store();
        $rows = $store->searchLexical('waits between attempts remote call', 5);

        self::assertNotSame([], $rows);
        self::assertSame('method:Demo\\RetryHandler::execute#body:v1', $rows[0]['chunk_id']);
    }

    public function testHybridResultsCarryProvenanceAndDeclareTheMissingSemanticChannel(): void
    {
        $index = $this->index();
        $result = (new HybridSearch())->search($index, $this->store($index), 'RetryHandler', 5);

        self::assertSame('1.0', $result['schema_version']);
        self::assertTrue($result['degraded'], 'without an embedding provider the missing channel must be declared, not hidden');
        self::assertSame('semantic_channel_unavailable', $result['degraded_reason']);
        self::assertSame($result['map_snapshot'], $result['search_index_snapshot']);
        self::assertNotSame([], $result['results']);

        $first = $result['results'][0];
        self::assertSame(['structural', 'lexical', 'semantic'], array_keys($first['channel_ranks']));
        self::assertNull($first['channel_ranks']['semantic']);
        self::assertNotSame([], $first['reasons']);
    }

    public function testReplacingOneFileLeavesNoStaleLexicalRows(): void
    {
        $index = $this->index();
        $store = $this->store($index);
        $before = $store->chunkCount();

        $store->replaceChunks([], ['src/Mailer.php']);

        self::assertLessThan($before, $store->chunkCount());
        self::assertSame([], $store->integrityFailures(), 'the external-content FTS index must stay coherent');
        self::assertSame([], $store->searchLexical('recipient', 5));
    }

    public function testTheWholeIndexCanBeRebuiltFromTheMap(): void
    {
        $index = $this->index();
        $store = $this->store($index);
        $store->replaceChunks([], null);
        self::assertSame(0, $store->chunkCount());

        $store->replaceChunks((new ChunkExtractor())->extract($index), null);

        self::assertGreaterThan(0, $store->chunkCount());
        self::assertSame([], $store->integrityFailures());
    }

    public function testTheQueryPlannerSendsOnlySymbolShapedTermsToTheStructuralChannel(): void
    {
        $planner = new QueryPlanner();

        self::assertSame([], $planner->plan('how are retry timeouts handled')['structural_terms']);
        self::assertSame(['RetryHandler'], $planner->plan('where is RetryHandler')['structural_terms']);
        self::assertSame(
            ['Demo\\RetryHandler::execute'],
            $planner->plan('Demo\\RetryHandler::execute')['structural_terms'],
        );
    }

    public function testTheShippedSqliteVecBinaryIsResolvedAndChecksumVerified(): void
    {
        $platform = SqliteVecBinary::platform();
        if ($platform === null) {
            self::assertNull(SqliteVecBinary::resolve(), 'an unsupported platform must report no binary, not a wrong one');

            return;
        }

        $path = SqliteVecBinary::resolve();
        self::assertIsString($path, 'the package ships a binary for ' . $platform);
        self::assertFileExists($path);
        self::assertStringContainsString($platform, $path);
        self::assertNotNull(SqliteVecBinary::version());
    }

    public function testACorruptedBinaryIsRefusedRatherThanLoaded(): void
    {
        $platform = SqliteVecBinary::platform();
        if ($platform === null) {
            self::markTestSkipped('No binary is shipped for this platform.');
        }

        $tampered = $this->root . '/tampered-vec0.so';
        file_put_contents($tampered, 'not a shared object');
        putenv(SqliteVecBinary::ENVIRONMENT_OVERRIDE . '=' . $tampered);

        try {
            // An explicit override is the operator's own decision and is taken at face value; the
            // checksum guards what the package itself ships.
            self::assertSame($tampered, SqliteVecBinary::resolve());

            $store = new SearchIndexStore($this->root . '/.agent-map/tampered.sqlite');
            self::assertFalse($store->enableVectorSupport(), 'a file that is not a loadable extension must not enable the channel');
        } finally {
            putenv(SqliteVecBinary::ENVIRONMENT_OVERRIDE);
        }
    }

    public function testVectorSearchReturnsNeighboursWhenTheChannelIsAvailable(): void
    {
        $store = $this->store();
        if (!$store->enableVectorSupport()) {
            self::markTestSkipped('sqlite-vec is not loadable here; the channel is optional by design.');
        }

        $provider = new \voku\AgentMap\Search\Embedding\CorpusEmbeddingProvider();
        $documents = $store->allChunkContents();
        $provider->fit(array_map(static fn (array $row): string => $row['content'], $documents));
        $store->prepareVectorTable($provider->model());

        $vectors = [];
        foreach ($documents as $row) {
            $vectors[$row['chunk_id']] = $provider->embedQuery($row['content']);
        }
        $store->storeVectors($vectors, $provider->model());

        self::assertSame(count($documents), $store->vectorCount());

        $hits = $store->searchSemantic($provider->embedQuery('retry attempts'), $provider->model(), 3);
        self::assertNotSame([], $hits);
        foreach ($hits as $hit) {
            self::assertGreaterThanOrEqual(0.0, $hit['distance']);
        }
    }

    public function testShortIdentifiersAndStopWordsInQueryPlannerAndSearchStore(): void
    {
        $planner = new QueryPlanner();
        $plan = $planner->plan('Accounting für D3?');

        self::assertContains('Accounting', $plan['structural_terms']);
        self::assertContains('D3', $plan['structural_terms']);

        $stopWords = QueryPlanner::getStopWords();
        self::assertContains('für', $stopWords);
        self::assertContains('about', $stopWords);
        // Domain words that no natural-language list carries, but that identify nothing in code.
        self::assertContains('class', $stopWords);
        self::assertContains('method', $stopWords);

        $store = $this->store();

        // The German stop word must not decide the result: the same query with and without it has to
        // rank the same chunks, which is what dropping it before the FTS MATCH is for.
        self::assertSame(
            array_column($store->searchLexical('retry handler attempts', 5), 'chunk_id'),
            array_column($store->searchLexical('retry für handler attempts', 5), 'chunk_id'),
        );
        self::assertNotEmpty($store->searchLexical('retry handler attempts', 5));

        // "No answer" has to stay reachable: this corpus knows nothing about accounting, and a
        // lexical channel that still returned something would be inventing a lead.
        self::assertSame([], $store->searchLexical('Accounting für D3?', 5));
    }

    private function index(): AgentMapIndex
    {
        return (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))
            ->build($this->root, ['src'], []);
    }

    private function store(?AgentMapIndex $index = null): SearchIndexStore
    {
        $index ??= $this->index();
        $store = new SearchIndexStore($this->root . '/.agent-map/search.sqlite');
        $store->replaceChunks((new ChunkExtractor())->extract($index), null);
        $store->setMeta('map_snapshot', $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest);

        return $store;
    }
}
