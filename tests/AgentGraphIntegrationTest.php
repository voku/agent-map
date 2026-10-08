<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PDO;
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
        $generationFile = MapArtifactPaths::graphGenerationFor($indexFile);
        self::assertFileExists($graphFile);
        self::assertFileExists($generationFile);

        $generation = json_decode((string) file_get_contents($generationFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1', $generation['schema_version'] ?? null);
        self::assertSame($map->mapDigest(), $generation['map_digest'] ?? null);

        $store = (new MapGraphIndex())->openCurrent($indexFile);
        self::assertSame($map->mapDigest(), $store->sourceRevision());
        self::assertSame($this->ownerIds($map->incoming('target-a')), $this->graphIds($store->incoming('target-a')));
        self::assertSame($this->ownerIds($map->outgoing('source')), $this->graphIds($store->outgoing('source')));
        self::assertSame(['target-b', 'target-a'], $store->outgoing('source', 'calls')[0]->targetIds);
        self::assertSame(['r2', 'r1', 'r3'], $this->graphIds(iterator_to_array($store->relations(), false)));
        self::assertSame([], $store->integrityFailures());
    }

    public function testCurrentGraphReadsDoNotMutateDerivedDatabase(): void
    {
        $indexFile = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($this->map(), $indexFile, 'json');
        $graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);

        $before = hash_file('sha256', $graphFile);
        self::assertIsString($before);

        $graphIndex = new MapGraphIndex();
        self::assertSame($this->map()->mapDigest(), $graphIndex->openCurrent($indexFile)->sourceRevision());
        self::assertSame([], $graphIndex->verifyCurrent($indexFile)->integrityFailures());

        self::assertSame($before, hash_file('sha256', $graphFile));
    }

    public function testLegacyVersionOneGraphIsReadOnlyFailClosedAndRecoversOnRebuild(): void
    {
        $map = $this->map();
        $indexFile = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($map, $indexFile, 'json');
        $graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);
        self::assertTrue(unlink($graphFile));

        // Recreate an actual v1 relation/target schema alongside the current map artifacts.
        $pdo = new PDO('sqlite:' . $graphFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE graph_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE graph_relations (
            relation_id TEXT PRIMARY KEY, relation_position INTEGER NOT NULL UNIQUE,
            source_id TEXT NOT NULL, kind TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE graph_relation_targets (
            relation_id TEXT NOT NULL, target_id TEXT NOT NULL, target_position INTEGER NOT NULL,
            PRIMARY KEY (relation_id, target_position), UNIQUE (relation_id, target_id),
            FOREIGN KEY (relation_id) REFERENCES graph_relations(relation_id) ON DELETE CASCADE
        )');
        $pdo->exec("INSERT INTO graph_meta (key, value) VALUES ('schema_version', '1')");
        $pdo->exec("INSERT INTO graph_relations VALUES ('legacy', 0, 'source', 'calls')");
        $pdo->exec("INSERT INTO graph_relation_targets VALUES ('legacy', 'target-a', 0)");
        unset($pdo);

        $before = hash_file('sha256', $graphFile);
        self::assertIsString($before);

        $graphIndex = new MapGraphIndex();
        try {
            $graphIndex->openCurrent($indexFile);
            self::fail('A version 1 derived graph must not be upgraded by a read-only open.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('rebuild the agent-map index', $exception->getMessage());
            self::assertStringContainsString('schema version is incompatible', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }

        self::assertSame($before, hash_file('sha256', $graphFile));

        // Owners rebuild their disposable derived graph; no read-only migration is attempted.
        $graphIndex->rebuild($map, $indexFile);
        $upgraded = $graphIndex->verifyCurrent($indexFile);
        self::assertSame(['r2', 'r1', 'r3'], $this->graphIds($upgraded->incoming('target-a')));
        self::assertSame([], $upgraded->integrityFailures());
    }

    public function testGenerationMarkerChangeMakesOlderGraphFailClosed(): void
    {
        $indexFile = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($this->map(), $indexFile, 'json');
        $generationFile = MapArtifactPaths::graphGenerationFor($indexFile);
        self::assertIsInt(file_put_contents($generationFile, "{\"schema_version\":\"1\",\"map_digest\":\"sha256:changed\"}\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Derived graph index is stale');
        (new MapGraphIndex())->openCurrent($indexFile);
    }

    public function testCanonicalArtifactChangeFailsExplicitFingerprintVerification(): void
    {
        $indexFile = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($this->map(), $indexFile, 'json');
        $relationsFile = MapArtifactPaths::relationsFileFor($indexFile);
        self::assertIsInt(file_put_contents($relationsFile, "\n", FILE_APPEND));

        $graphIndex = new MapGraphIndex();
        self::assertSame($this->map()->mapDigest(), $graphIndex->openCurrent($indexFile)->sourceRevision());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed canonical artifact fingerprint verification');
        $graphIndex->verifyCurrent($indexFile);
    }

    public function testFullIntegrityScanRemainsAvailableAsExplicitVerification(): void
    {
        $indexFile = $this->root . '/php-symbols.json';
        (new IndexWriter())->write($this->map(), $indexFile, 'json');
        $graphFile = MapArtifactPaths::graphDatabaseFor($indexFile);

        $pdo = new PDO('sqlite:' . $graphFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        self::assertSame(1, $pdo->exec("DELETE FROM graph_relations WHERE relation_id = 'r1'"));
        unset($pdo);

        $graphIndex = new MapGraphIndex();
        self::assertSame('sha256:', substr((string) $graphIndex->openCurrent($indexFile)->sourceFingerprint(), 0, 7));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Derived graph index failed integrity checks');
        $graphIndex->verifyCurrent($indexFile);
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
