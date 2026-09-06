<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentGraph\Graph\GraphRelation;
use voku\AgentGraph\Graph\GraphValidationException;
use voku\AgentGraph\Sqlite\SqliteRelationStore;
use voku\AgentMap\Discovery\GraphAdjacency;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
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

    public function testSharedAdjacencyPreservesMapOwnerRelationsAndOrder(): void
    {
        $map = $this->map();
        $adjacency = new GraphAdjacency($map);

        self::assertSame($map->incoming('target-a'), $adjacency->incoming('target-a'));
        self::assertSame($map->outgoing('source'), $adjacency->outgoing('source'));
    }

    public function testIndexWriterMaterializesAgentGraphSqliteWithParity(): void
    {
        $map = $this->map();
        $indexFile = $this->root . '/php-symbols.json';

        (new IndexWriter())->write($map, $indexFile, 'json');

        $graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);
        self::assertFileExists($graphFile);

        $store = new SqliteRelationStore($graphFile);
        self::assertSame($this->ids($map->incoming('target-a')), $this->graphIds($store->incoming('target-a')));
        self::assertSame($this->ids($map->outgoing('source')), $this->graphIds($store->outgoing('source')));
        self::assertSame([], $store->integrityFailures());
    }

    public function testInvalidNextGraphLeavesPreviousPublishedGenerationUntouched(): void
    {
        $writer = new IndexWriter();
        $indexFile = $this->root . '/php-symbols.json';
        $relationsFile = MapArtifactPaths::relationsFileFor($indexFile);
        $graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);
        $writer->write($this->map(), $indexFile, 'json');

        $beforeIndex = file_get_contents($indexFile);
        $beforeRelations = file_get_contents($relationsFile);
        self::assertIsString($beforeIndex);
        self::assertIsString($beforeRelations);

        $invalid = new AgentMapIndex(
            schemaVersion: AgentMapIndex::SCHEMA_VERSION,
            root: $this->root,
            backend: 'structural',
            files: [],
            relations: [
                new RelationEntry('duplicate', 'new-source', 'calls', ['a'], 'src/New.php', 1, 1, 'resolved'),
                new RelationEntry('duplicate', 'new-source', 'calls', ['b'], 'src/New.php', 2, 2, 'resolved'),
            ],
        );

        try {
            $writer->write($invalid, $indexFile, 'json');
            self::fail('Expected graph validation to reject duplicate relation ids.');
        } catch (GraphValidationException) {
            self::assertSame($beforeIndex, file_get_contents($indexFile));
            self::assertSame($beforeRelations, file_get_contents($relationsFile));
        }

        $store = new SqliteRelationStore($graphFile);
        self::assertSame(['r2', 'r1'], $this->graphIds($store->outgoing('source')));
        self::assertSame([], glob($this->root . '/*.tmp-*') ?: []);
        self::assertSame([], glob($this->root . '/*.backup-*') ?: []);
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
    private function ids(array $relations): array
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
