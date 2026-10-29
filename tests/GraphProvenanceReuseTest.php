<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MapGraphIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\MapArtifactPaths;

final class GraphProvenanceReuseTest extends TestCase
{
    private string $root;
    private string $indexFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-reuse-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o775, true));
        $this->indexFile = $this->root . '/index.json';
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

    public function testChangedCanonicalEvidenceReusesIdenticalGraphWithoutDeletingRows(): void
    {
        $before = $this->map('sha256:before');
        $after = $this->map('sha256:after');
        self::assertNotSame($before->mapDigest(), $after->mapDigest());

        $writer = new IndexWriter();
        $writer->write($before, $this->indexFile);
        $pdo = $this->forbidGraphReplacement();

        // A real row DELETE would fail; the guarded reuse path must not delete graph rows.
        $writer->write($after, $this->indexFile);

        self::assertSame($after->mapDigest(), (new MapGraphIndex())->verifyCurrent($this->indexFile)->sourceRevision());
        self::assertSame(2, (new MapGraphIndex())->openCurrent($this->indexFile)->relationCount());
        unset($pdo);
    }

    public function testChangedRelationForcesFullReplacement(): void
    {
        $writer = new IndexWriter();
        $writer->write($this->map('sha256:before'), $this->indexFile);

        $pdo = $this->forbidGraphReplacement();
        $changed = $this->map('sha256:after', true);

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('forbidden-replace');
        try {
            $writer->write($changed, $this->indexFile);
        } finally {
            unset($pdo);
        }
    }

    public function testTamperedPreviousCanonicalFingerprintForcesReplacement(): void
    {
        $writer = new IndexWriter();
        $writer->write($this->map('sha256:before'), $this->indexFile);
        self::assertIsInt(file_put_contents(MapArtifactPaths::relationsFileFor($this->indexFile), "\n", FILE_APPEND));
        $pdo = $this->forbidGraphReplacement();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('forbidden-replace');
        try {
            $writer->write($this->map('sha256:after'), $this->indexFile);
        } finally {
            unset($pdo);
        }
    }

    public function testOldStoreWithoutProjectionDigestForcesReplacement(): void
    {
        $writer = new IndexWriter();
        $writer->write($this->map('sha256:before'), $this->indexFile);
        $pdo = new PDO('sqlite:' . MapArtifactPaths::graphDatabaseFor($this->indexFile));
        self::assertSame(1, $pdo->exec("DELETE FROM graph_meta WHERE key = 'projection_digest_v1'"));
        unset($pdo);
        $pdo = $this->forbidGraphReplacement();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('forbidden-replace');
        try {
            $writer->write($this->map('sha256:after'), $this->indexFile);
        } finally {
            unset($pdo);
        }
    }

    public function testStaleGenerationMarkerForcesReplacement(): void
    {
        $writer = new IndexWriter();
        $writer->write($this->map('sha256:before'), $this->indexFile);
        self::assertIsInt(file_put_contents(
            MapArtifactPaths::graphGenerationFor($this->indexFile),
            "{\"schema_version\":\"1\",\"map_digest\":\"sha256:stale\"}\n",
        ));
        $pdo = $this->forbidGraphReplacement();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('forbidden-replace');
        try {
            $writer->write($this->map('sha256:after'), $this->indexFile);
        } finally {
            unset($pdo);
        }
    }

    private function forbidGraphReplacement(): PDO
    {
        $pdo = new PDO(
            'sqlite:' . MapArtifactPaths::graphDatabaseFor($this->indexFile),
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec("CREATE TRIGGER block_replacement BEFORE DELETE ON graph_relations
            BEGIN SELECT RAISE(ABORT, 'forbidden-replace'); END");

        return $pdo;
    }

    private function map(string $fileHash, bool $changeRelation = false): AgentMapIndex
    {
        return new AgentMapIndex(
            schemaVersion: AgentMapIndex::SCHEMA_VERSION,
            root: $this->root,
            backend: 'simple-php-code-parser+structural-only',
            files: [new FileEntry('src/Sample.php', $fileHash, '', [])],
            relations: [
                new RelationEntry('r1', 'source', 'calls', [$changeRelation ? 'new-target' : 'target'], 'src/Sample.php', 1, 2, 'resolved'),
                new RelationEntry('r2', 'source', 'uses', ['other'], 'src/Sample.php', 3, 4, 'resolved'),
            ],
        );
    }
}
