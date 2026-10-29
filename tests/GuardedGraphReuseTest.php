<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\MapArtifactPaths;

/**
 * Consumer proof: real canonical JSON/relations publication and SQLite graph
 * reuse must stay in sync, including negative/failure paths.
 */
final class GuardedGraphReuseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-guarded-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o775, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->root);
    }

    public function testChangedCanonicalEvidenceWithIdenticalRelationsUsesFastPath(): void
    {
        $path = $this->root . '/map.json';
        $writer = new IndexWriter();
        $old = $this->map('structural');
        $updated = $this->map('phpstan');
        self::assertNotSame($old->mapDigest(), $updated->mapDigest());

        $writer->write($old, $path);
        $pdo = $this->database($path);
        $this->blockGraphRowReplacement($pdo);

        // GraphStore::replace() would trigger the intentional SQLite abort.
        // A successful update proves the guarded reuse path was actually taken.
        $writer->write($updated, $path);

        $current = (new MapGraphIndex())->verifyCurrent($path);
        self::assertSame($updated->mapDigest(), $current->sourceRevision());
        self::assertSame(2, $current->relationCount());
        self::assertSame(['r2', 'r1'], array_map(
            static fn ($relation): string => $relation->id,
            iterator_to_array($current->relations(), false),
        ));
    }

    public function testChangedRelationFallsBackToRebuildAndRejectsOldGenerationOnFailure(): void
    {
        $path = $this->root . '/map.json';
        $writer = new IndexWriter();
        $writer->write($this->map('structural'), $path);
        $pdo = $this->database($path);
        $this->blockGraphRowReplacement($pdo);

        $changed = $this->map('phpstan', true);
        try {
            $writer->write($changed, $path);
            self::fail('A changed graph must use full replacement and hit the trigger.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('replacement blocked', $exception->getMessage());
        }

        try {
            (new MapGraphIndex())->openCurrent($path);
            self::fail('A failed graph replacement must not look current.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('stale', $exception->getMessage());
        }

        self::assertSame(1, $pdo->exec('DROP TRIGGER block_relation_delete'));
        $writer->write($changed, $path);
        $store = (new MapGraphIndex())->verifyCurrent($path);
        self::assertSame(3, $store->relationCount());
        self::assertSame($changed->mapDigest(), $store->sourceRevision());
    }

    public function testLegacyGraphWithoutDigestCannotBeReused(): void
    {
        $path = $this->root . '/map.json';
        $writer = new IndexWriter();
        $writer->write($this->map('structural'), $path);
        $pdo = $this->database($path);
        self::assertSame(1, $pdo->exec("DELETE FROM graph_meta WHERE key = 'projection_digest_v1'"));
        $this->blockGraphRowReplacement($pdo);

        try {
            $writer->write($this->map('phpstan'), $path);
            self::fail('Missing owner digest must fall back to the blocked full replacement.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('replacement blocked', $exception->getMessage());
        }

        self::assertSame(1, $pdo->exec('DROP TRIGGER block_relation_delete'));
        $writer->write($this->map('phpstan'), $path);
        self::assertSame(2, (new MapGraphIndex())->verifyCurrent($path)->relationCount());
    }

    public function testStaleCanonicalSourceBytesCannotQualifyForReuse(): void
    {
        $path = $this->root . '/map.json';
        $writer = new IndexWriter();
        $writer->write($this->map('structural'), $path);
        self::assertIsInt(file_put_contents(MapArtifactPaths::relationsFileFor($path), "\n", FILE_APPEND));
        self::assertNull((new MapGraphIndex())->captureCurrentIdentity($path));

        $pdo = $this->database($path);
        $this->blockGraphRowReplacement($pdo);
        try {
            $writer->write($this->map('phpstan'), $path);
            self::fail('Tampered canonical bytes must force full replacement.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('replacement blocked', $exception->getMessage());
        }
    }

    private function database(string $indexPath): PDO
    {
        return new PDO(
            'sqlite:' . MapArtifactPaths::graphDatabaseFor($indexPath),
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function blockGraphRowReplacement(PDO $pdo): void
    {
        $pdo->exec("CREATE TRIGGER block_relation_delete
            BEFORE DELETE ON graph_relations
            BEGIN SELECT RAISE(ABORT, 'replacement blocked'); END");
    }

    private function map(string $backend, bool $addRelation = false): AgentMapIndex
    {
        $relations = [
            new RelationEntry('r2', 'source', 'calls', ['target-b', 'target-a'], 'src/A.php', 1, 1, 'resolved'),
            new RelationEntry('r1', 'source', 'extends', ['target-a'], 'src/A.php', 2, 2, 'resolved'),
        ];
        if ($addRelation) {
            $relations[] = new RelationEntry('r3', 'other', 'calls', ['target-c'], 'src/B.php', 3, 3, 'resolved');
        }

        return new AgentMapIndex(
            schemaVersion: AgentMapIndex::SCHEMA_VERSION,
            root: $this->root,
            backend: $backend,
            files: [],
            relations: $relations,
        );
    }
}
