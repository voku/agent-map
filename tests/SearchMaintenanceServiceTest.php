<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapPreparationService;
use voku\AgentMap\Search\ChunkExtractor;
use voku\AgentMap\Search\ChunkPolicy;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentMap\Search\SearchMaintenanceRequest;
use voku\AgentMap\Search\SearchMaintenanceService;

final class SearchMaintenanceServiceTest extends TestCase
{
    private string $root;

    private string $index;

    private MapArtifactPaths $artifacts;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5; the derived index is optional by design.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-search-owner-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', $this->source('Foo', 'run'));
        $this->index = $this->root . '/map.json';
        $this->artifacts = MapArtifactPaths::forProject($this->root, '.agent-map');

        $builder = new AgentMapBuilder(
            semanticAnalyzer: new StructuralOnlySemanticAnalyzer(),
            artifacts: $this->artifacts,
        );
        (new IndexWriter())->write($builder->build($this->root, ['src'], []), $this->index);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAbsentSearchIsReportedWithoutCreatingIt(): void
    {
        $index = (new IndexReader())->read($this->index);

        $result = (new SearchMaintenanceService())->refreshIfPresent(
            new SearchMaintenanceRequest($index, $this->artifacts),
        );

        self::assertSame('absent', $result->state);
        self::assertFileDoesNotExist($this->artifacts->searchDatabase());
    }

    public function testPreparedMapRefreshesExistingSearchAndIsIdempotent(): void
    {
        $index = (new IndexReader())->read($this->index);
        $this->seedSearch($index);
        file_put_contents($this->root . '/src/Foo.php', $this->source('Foo', 'changed'));

        $prepared = (new MapPreparationService())->refresh($this->request());
        $service = new SearchMaintenanceService();
        $result = $service->refreshIfPresent(new SearchMaintenanceRequest($prepared->index, $this->artifacts));

        self::assertSame('refreshed', $result->state);
        self::assertSame(
            $prepared->index->fingerprint === null ? 'sha256:none' : $prepared->index->fingerprint->sourceDigest,
            $result->searchSnapshot,
        );
        self::assertSame([], $result->skippedPaths);

        $again = $service->refreshIfPresent(new SearchMaintenanceRequest($prepared->index, $this->artifacts));
        self::assertSame('current', $again->state);
        self::assertSame(0, $again->prunedFiles);
    }

    public function testStaleMapIsRefusedWithoutAdvancingSearchSnapshot(): void
    {
        $index = (new IndexReader())->read($this->index);
        $this->seedSearch($index);
        $before = (new SearchIndexStore($this->artifacts->searchDatabase()))->meta('map_snapshot');
        file_put_contents($this->root . '/src/Foo.php', $this->source('Foo', 'changed'));
        $stale = (new IndexReader())->read($this->index);

        $result = (new SearchMaintenanceService())->refreshIfPresent(
            new SearchMaintenanceRequest($stale, $this->artifacts),
        );

        self::assertSame('refused', $result->state);
        self::assertSame('map_stale', $result->reason);
        self::assertSame($before, (new SearchIndexStore($this->artifacts->searchDatabase()))->meta('map_snapshot'));
    }

    public function testRemovedMapFilesArePrunedFromSearch(): void
    {
        file_put_contents($this->root . '/src/Dropped.php', $this->source('Dropped', 'drop'));
        $builder = new AgentMapBuilder(
            semanticAnalyzer: new StructuralOnlySemanticAnalyzer(),
            artifacts: $this->artifacts,
        );
        (new IndexWriter())->write($builder->build($this->root, ['src'], []), $this->index);
        $this->seedSearch((new IndexReader())->read($this->index));
        unlink($this->root . '/src/Dropped.php');

        $prepared = (new MapPreparationService())->refresh($this->request());
        $result = (new SearchMaintenanceService())->refreshIfPresent(
            new SearchMaintenanceRequest($prepared->index, $this->artifacts),
        );

        self::assertSame('refreshed', $result->state);
        self::assertSame(1, $result->prunedFiles);
        self::assertSame([], (new SearchIndexStore($this->artifacts->searchDatabase()))->searchLexical('Dropped', 10));
    }

    public function testChangedChunkPolicyForcesReconciliationEvenWhenSnapshotMatches(): void
    {
        $index = (new IndexReader())->read($this->index);
        $this->seedSearch($index);
        $store = new SearchIndexStore($this->artifacts->searchDatabase());
        $store->setMeta('chunk_policy_version', '0');

        $result = (new SearchMaintenanceService())->refreshIfPresent(
            new SearchMaintenanceRequest($index, $this->artifacts),
        );

        self::assertSame('refreshed', $result->state);
        self::assertSame((string) ChunkPolicy::VERSION, $store->meta('chunk_policy_version'));
    }

    private function request(): MapPreparationRequest
    {
        return new MapPreparationRequest(
            root: $this->root,
            indexPath: $this->index,
            outputPath: $this->index,
            format: 'json',
            paths: ['src'],
            pathsProvided: true,
            scanPaths: [],
            scanPathsProvided: false,
            excludes: [],
            excludesProvided: false,
            backend: 'structural',
            phpStanConfig: null,
            phpStanMemoryLimit: null,
            artifacts: $this->artifacts,
        );
    }

    private function seedSearch(\voku\AgentMap\Index\AgentMapIndex $index): void
    {
        $store = new SearchIndexStore($this->artifacts->searchDatabase());
        $store->replaceChunks((new ChunkExtractor())->extract($index));
        $store->setMeta(
            'map_snapshot',
            $index->fingerprint === null ? 'sha256:none' : $index->fingerprint->sourceDigest,
        );
        $store->setMeta('chunk_policy_version', (string) ChunkPolicy::VERSION);
    }

    private function source(string $class, string $method): string
    {
        return "<?php\n\nfinal class {$class}\n{\n    public function {$method}(): void\n    {\n    }\n}\n";
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
