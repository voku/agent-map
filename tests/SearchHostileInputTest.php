<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Search\ChunkExtractor;
use voku\AgentMap\Search\Embedding\CorpusEmbeddingProvider;
use voku\AgentMap\Search\Embedding\EmbeddingModel;
use voku\AgentMap\Search\Embedding\EmbeddingVector;
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Search\QueryPlanner;
use voku\AgentMap\Search\SearchIndexStore;

/**
 * Inputs a user or a repository will eventually produce, and that nothing in the happy path covers.
 *
 * The point is not coverage. Every case here was written to break something specific: FTS5 has its
 * own query syntax, source files are not guaranteed to be valid UTF-8, a refresh has to cope with a
 * file that no longer exists, and a vector table is bound to exactly one model.
 */
final class SearchHostileInputTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-hostile-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Alpha.php', $this->source('Alpha', 'ordinary'));
        file_put_contents($this->root . '/src/Beta.php', $this->source('Beta', 'ordinary'));
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

    /**
     * FTS5 MATCH is a query language. Every one of these is a syntax error when passed through
     * unescaped, and a search box is exactly where they arrive.
     *
     * @return list<array{0: string}>
     */
    public static function hostileQueries(): array
    {
        return [
            ['"'],
            ['unbalanced " quote'],
            ['foo AND'],
            ['NEAR(a b)'],
            ['a OR OR b'],
            ['*'],
            ['-alpha'],
            ['^caret'],
            ['(unclosed'],
            [':::'],
            ['\\\\'],
            ['a:b:c'],
            ['💥 emoji query'],
            ['Ünïcödé Klasse'],
            [str_repeat('long ', 300)],
            ['0'],
            ['   '],
            [''],
        ];
    }

    #[DataProvider('hostileQueries')]
    public function testAHostileQueryNeverThrows(string $query): void
    {
        $store = $this->store();

        // The assertion is that neither call throws; FTS5 answers a syntax error with an exception,
        // so reaching this line at all is the result under test.
        $rows = $store->searchLexical($query, 5);
        self::assertLessThanOrEqual(5, count($rows));

        $result = (new HybridSearch())->search($this->index(), $store, $query, 5);
        self::assertSame($query, $result['query']);
        self::assertLessThanOrEqual(5, count($result['results']));
    }

    /**
     * A query that is nothing but question words has no searchable token left. It must come back
     * empty rather than matching everything through an empty MATCH expression.
     */
    public function testAQueryOfOnlyStopWordsReturnsNothing(): void
    {
        self::assertSame([], $this->store()->searchLexical('how is the code used for this', 5));
    }

    /**
     * The map is rebuilt without a file. Its chunks have to disappear with it: a search that still
     * offers a deleted file sends an agent to a path that is not there.
     */
    public function testChunksOfADeletedFileDoNotSurviveARefresh(): void
    {
        $store = $this->store();
        self::assertNotSame([], $store->searchLexical('Beta', 5));

        unlink($this->root . '/src/Beta.php');
        $rebuilt = $this->index();
        $store->replaceChunks((new ChunkExtractor())->extract($rebuilt), null);

        $remaining = [];
        foreach ($store->searchLexical('Beta', 5) as $row) {
            $remaining[] = $row['file_path'];
        }

        self::assertNotContains('src/Beta.php', $remaining);
        self::assertSame([], $store->integrityFailures());
    }

    /**
     * A partial refresh names the files it rebuilt. A file that vanished is not in that list, so the
     * caller has to remove it explicitly - which is exactly the case that silently keeps stale rows.
     */
    public function testAPartialRefreshMustStillDropAVanishedFile(): void
    {
        $store = $this->store();
        unlink($this->root . '/src/Beta.php');

        // The two calls an incremental refresh makes: replace what was re-extracted, then drop what
        // the map no longer holds.
        $index = $this->index();
        $store->replaceChunks((new ChunkExtractor())->extract($index, ['src/Alpha.php']), ['src/Alpha.php']);
        self::assertSame(1, $store->pruneMissingPaths(['src/Alpha.php']));

        $paths = [];
        foreach ($store->searchLexical('Beta', 5) as $row) {
            $paths[] = $row['file_path'];
        }

        self::assertNotContains('src/Beta.php', $paths, 'a deleted file must not survive an incremental refresh');
    }

    /** Source files are bytes. A latin-1 comment is not valid UTF-8 and must not poison the index. */
    public function testSourceThatIsNotValidUtf8DoesNotBreakIndexingOrSearch(): void
    {
        file_put_contents($this->root . '/src/Latin.php', $this->source('Latin', "caf\xE9 r\xE9sum\xE9"));

        $store = new SearchIndexStore($this->root . '/.agent-map/search.sqlite');
        $store->replaceChunks((new ChunkExtractor())->extract($this->index()), null);

        self::assertGreaterThan(0, $store->chunkCount());
        self::assertSame([], $store->integrityFailures());
        self::assertLessThanOrEqual(5, count($store->searchLexical('Latin', 5)));
    }

    public function testALimitOfZeroReturnsNothingInsteadOfEverything(): void
    {
        $result = (new HybridSearch())->search($this->index(), $this->store(), 'Alpha', 0);

        self::assertSame([], $result['results']);
    }

    /** Encoding guards exist so a malformed vector cannot reach the index unnoticed. */
    public function testVectorEncodingRejectsWrongDimensionsAndNonFiniteValues(): void
    {
        $this->expectExceptionMessageMatches('/dimensions/');
        EmbeddingVector::encode([1.0, 2.0], 3);
    }

    public function testVectorEncodingRejectsNan(): void
    {
        $this->expectExceptionMessageMatches('/non-finite/');
        EmbeddingVector::encode([1.0, NAN, 0.5], 3);
    }

    public function testNormalizingAZeroVectorDoesNotDivideByZero(): void
    {
        self::assertSame([0.0, 0.0], EmbeddingVector::normalize([0.0, 0.0]));
    }

    /** Two models are two vector spaces. Mixing them silently would make every distance meaningless. */
    public function testChangingTheModelFingerprintDropsTheOldVectors(): void
    {
        $store = $this->store();
        if (!$store->enableVectorSupport()) {
            self::markTestSkipped('sqlite-vec is not loadable here.');
        }

        $first = new EmbeddingModel('test', 'a', '1', 4);
        $store->prepareVectorTable($first);
        $chunkId = $store->allChunkContents()[0]['chunk_id'];
        $store->storeVectors([$chunkId => [1.0, 0.0, 0.0, 0.0]], $first);
        self::assertSame(1, $store->vectorCount());

        $second = new EmbeddingModel('test', 'b', '1', 4);
        $store->prepareVectorTable($second);

        self::assertSame(0, $store->vectorCount(), 'vectors from another model must not survive');
    }

    public function testAnEmptyCorpusStillProducesAUsableQueryVector(): void
    {
        $provider = new CorpusEmbeddingProvider();
        $provider->fit([]);

        $vector = $provider->embedQuery('anything at all');

        self::assertCount($provider->model()->dimensions, $vector);
        foreach ($vector as $value) {
            self::assertTrue(is_finite($value));
        }
    }

    public function testThePlannerSurvivesInputThatIsNotIdentifierShaped(): void
    {
        $planner = new QueryPlanner();

        foreach (['', '   ', '💥', '::', '\\\\\\\\', str_repeat('a', 5000)] as $query) {
            $plan = $planner->plan($query);
            self::assertSame($query, $plan['free_text']);
        }
    }

    /**
     * Building the same map twice must produce the same index. If it does not, "rebuild it" stops
     * being a safe instruction and every snapshot comparison becomes a coin toss.
     */
    public function testTwoFullBuildsProduceTheSameIndex(): void
    {
        $index = $this->index();
        $store = new SearchIndexStore($this->root . '/.agent-map/search.sqlite');

        $store->replaceChunks((new ChunkExtractor())->extract($index), null);
        $first = $store->chunkCount();
        $firstHits = $store->searchLexical('Alpha', 5);

        $store->replaceChunks((new ChunkExtractor())->extract($index), null);

        self::assertSame($first, $store->chunkCount(), 'a second build must not duplicate or lose chunks');
        self::assertEquals($firstHits, $store->searchLexical('Alpha', 5));
        self::assertSame([], $store->integrityFailures());
    }

    /**
     * The extractor verifies the file hash through the materializer. When a file changed after the
     * map was built its chunks cannot be produced - and the caller has to be able to tell that from
     * "this file has no symbols", instead of quietly indexing less than it thinks.
     */
    public function testAFileThatChangedAfterTheMapWasBuiltIsReportedNotSilentlySkipped(): void
    {
        $index = $this->index();
        file_put_contents($this->root . '/src/Beta.php', $this->source('Beta', 'changed after the map was built'));

        $extractor = new ChunkExtractor();
        $chunks = $extractor->extract($index);

        $paths = [];
        foreach ($chunks as $chunk) {
            $paths[$chunk->filePath] = true;
        }

        self::assertArrayHasKey('src/Alpha.php', $paths);
        self::assertArrayNotHasKey('src/Beta.php', $paths, 'a stale file must not be indexed from the wrong source');
        self::assertSame(['src/Beta.php'], $extractor->skippedPaths(), 'and the skip has to be visible to the caller');
    }

    public function testPreparingAVectorTableWithoutTheExtensionFailsLoudly(): void
    {
        $store = $this->store();
        if ($store->enableVectorSupport()) {
            self::markTestSkipped('sqlite-vec is loadable here, so the failure path cannot be reached.');
        }

        $this->expectExceptionMessageMatches('/vector channel is not available/');
        $store->prepareVectorTable(new EmbeddingModel('test', 'a', '1', 4));
    }

    public function testAQueryVectorOfTheWrongSizeIsRejectedBeforeItReachesTheIndex(): void
    {
        $store = $this->store();
        if (!$store->enableVectorSupport()) {
            self::markTestSkipped('sqlite-vec is not loadable here.');
        }

        $model = new EmbeddingModel('test', 'a', '1', 4);
        $store->prepareVectorTable($model);

        $this->expectExceptionMessageMatches('/dimensions/');
        $store->searchSemantic([1.0, 2.0], $model, 3);
    }

    /**
     * A refresh must re-extract the files that moved, not every file the map holds. Comparing the
     * map digest alone cannot tell them apart: one edited file changes it, and the whole repository
     * would be rebuilt for that.
     */
    public function testOnlyChangedFilesAreConsideredStaleByTheIndex(): void
    {
        $store = $this->store();
        $before = $store->sourceHashesByPath();
        self::assertArrayHasKey('src/Alpha.php', $before);
        self::assertArrayHasKey('src/Beta.php', $before);

        file_put_contents($this->root . '/src/Beta.php', $this->source('Beta', 'edited'));
        $index = $this->index();

        $changed = [];
        foreach ($index->files as $file) {
            if (($before[$file->path] ?? null) !== $file->sha256) {
                $changed[] = $file->path;
            }
        }

        self::assertSame(['src/Beta.php'], $changed, 'one edited file must not mark the whole repository stale');
    }

    /**
     * The cache is keyed by content, not by chunk id: re-indexing text that was embedded before must
     * not call the provider again, which is what keeps a refresh cheap on a large repository.
     */
    public function testUnchangedContentIsNotEmbeddedTwice(): void
    {
        $store = $this->store();
        if (!$store->enableVectorSupport()) {
            self::markTestSkipped('sqlite-vec is not loadable here.');
        }

        $model = new EmbeddingModel('test', 'a', '1', 4);
        $store->prepareVectorTable($model);

        $documents = $store->chunkContentsForPaths(null);
        self::assertNotSame([], $documents);

        $hashes = array_map(static fn (array $row): string => $row['content_sha256'], $documents);
        self::assertSame([], $store->cachedEmbeddings($hashes, $model), 'nothing is cached before the first embedding');

        $blobs = [];
        foreach ($documents as $row) {
            $blobs[$row['content_sha256']] = EmbeddingVector::encode([1.0, 0.0, 0.0, 0.0], 4);
        }
        $store->cacheEmbeddings($blobs, $model);

        self::assertCount(count($blobs), $store->cachedEmbeddings($hashes, $model));
        self::assertSame(
            [],
            $store->cachedEmbeddings($hashes, new EmbeddingModel('test', 'b', '1', 4)),
            'another model must not read the first model\'s vectors',
        );
    }

    private function index(): AgentMapIndex
    {
        return (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))
            ->build($this->root, ['src'], []);
    }

    private function store(): SearchIndexStore
    {
        $store = new SearchIndexStore($this->root . '/.agent-map/search.sqlite');
        $store->replaceChunks((new ChunkExtractor())->extract($this->index()), null);

        return $store;
    }

    private function source(string $class, string $comment): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class {$class}
        {
            public function run(): void
            {
                // {$comment}
            }
        }
        PHP;
    }
}
