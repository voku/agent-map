<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\GraphProjectionFactory;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\MapArtifactPaths;

final class AgentGraphIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-graph-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o775, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testProjectionStreamsOnlyStructuralRelationIdentity(): void
    {
        $relations = iterator_to_array((new GraphProjectionFactory())->relations($this->map()), false);

        self::assertCount(3, $relations);
        self::assertSame('r2', $relations[0]->id);
        self::assertSame('source', $relations[0]->sourceId);
        self::assertSame('calls', $relations[0]->kind);
        self::assertSame(['target-b', 'target-a'], $relations[0]->targetIds);
        self::assertSame(['r2', 'r1', 'r3'], $this->graphIds($relations));
    }

    public function testIndexWriterBuildsCurrentSqliteGraphWithExactLookupParity(): void
    {
        $map = $this->map();
        $indexFile = $this->root . '/php-symbols.json';

        (new IndexWriter())->write($map, $indexFile, 'json');

        $graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);
        self::assertFileExists($graphFile);

        $store = (new MapGraphIndex())->openCurrent($indexFile);
        self::assertSame($this->ownerIds($map->incoming('target-a')), $this->graphIds($store->incoming('target-a')));
        self::assertSame($this->ownerIds($map->outgoing('source')), $this->graphIds($store->outgoing('source')));
        self::assertSame(['target-b', 'target-a'], $store->outgoing('source', 'calls')[0]->targetIds);
        self::assertSame(['r2', 'r1', 'r3'], $this->graphIds(iterator_to_array($store->relations(), false)));
        self::assertSame([], $store->integrityFailures());
    }

    public function testCanonicalArtifactChangeMakesOlderGraphFailClosed(): void
    {
        $indexFile = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($this->map(), $indexFile, 'json');
        $relationsFile = MapArtifactPaths::relationsFileFor($indexFile);
        self::assertIsInt(file_put_contents($relationsFile, "\n", FILE_APPEND));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Derived graph index is stale');
        (new MapGraphIndex())->openCurrent($indexFile);
    }

    private function map(): AgentMapIndex
    {
        return new AgentMapIndex(
            schemaVersion: AgentMapIndex::SCHEMA_VERSION,
            root: $this->root,
            backend: 'structural',
            files: [],
            relations: [
                new RelationEntry('r2', 'source', 'calls', ['target-b', 'target-a'], 'src/A.php', 1, 1, 'resolved'),
                new RelationEntry('r1', 'source', 'extends', ['target-a'], 'src/A.php', 2, 2, 'resolved'),
                new RelationEntry('r3', 'other', 'calls', ['target-a'], 'src/B.php', 1, 1, 'resolved'),
            ],
        );
    }

    /**
     * @param list<RelationEntry> $relations
     * @return list<string>
     */
    private function ownerIds(array $relations): array
    {
        return array_map(static fn (RelationEntry $relation): string => $relation->id, $relations);
    }

    /**
     * @param list<GraphRelation> $relations
     * @return list<string>
     */
    private function graphIds(array $relations): array
    {
        return array_map(static fn (GraphRelation $relation): string => $relation->id, $relations);
    }
}
