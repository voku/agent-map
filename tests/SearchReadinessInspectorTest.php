<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Search\ChunkPolicy;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentMap\Search\SearchReadinessInspector;

final class SearchReadinessInspectorTest extends TestCase
{
    private string $root;

    private string $mapPath;

    private string $searchPath;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5; ranked Search is optional.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-search-readiness-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        $this->mapPath = $this->root . '/custom/map.json';
        $this->searchPath = $this->root . '/custom/ranked.sqlite';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testMissingCustomSearchReturnsExactOwnerRecoveryCommand(): void
    {
        $map = $this->writeMap('sha256:current');

        $readiness = (new SearchReadinessInspector())->inspect($map, $this->mapPath, $this->searchPath);

        self::assertSame('missing', $readiness->state);
        self::assertSame('search_index_missing', $readiness->reason);
        self::assertSame(
            'agent-map search-index build --root=' . $this->root
            . ' --index=' . $this->mapPath
            . ' --database=' . $this->searchPath,
            $readiness->recoveryCommand,
        );
    }

    public function testCurrentCustomSearchIsReadyWithoutMutation(): void
    {
        $map = $this->writeMap('sha256:current');
        $this->writeSearchDatabase('sha256:current');
        $before = hash_file('sha256', $this->searchPath);
        self::assertIsString($before);

        $readiness = (new SearchReadinessInspector())->inspect($map, $this->mapPath, $this->searchPath);

        self::assertTrue($readiness->isReady());
        self::assertSame('sha256:current', $readiness->searchSnapshot);
        self::assertNull($readiness->recoveryCommand);
        self::assertSame($before, hash_file('sha256', $this->searchPath));
    }

    public function testSnapshotDriftReturnsExactRefreshCommand(): void
    {
        $map = $this->writeMap('sha256:current');
        $this->writeSearchDatabase('sha256:older');

        $readiness = (new SearchReadinessInspector())->inspect($map, $this->mapPath, $this->searchPath);

        self::assertSame('stale', $readiness->state);
        self::assertSame('map_snapshot_mismatch', $readiness->reason);
        self::assertStringStartsWith('agent-map search-index refresh ', (string) $readiness->recoveryCommand);
        self::assertStringContainsString('--index=' . $this->mapPath, (string) $readiness->recoveryCommand);
        self::assertStringContainsString('--database=' . $this->searchPath, (string) $readiness->recoveryCommand);
    }

    public function testChunkPolicyDriftIsOwnerReportedAsStale(): void
    {
        $map = $this->writeMap('sha256:current');
        $this->writeSearchDatabase('sha256:current', '0');

        $readiness = (new SearchReadinessInspector())->inspect($map, $this->mapPath, $this->searchPath);

        self::assertSame('stale', $readiness->state);
        self::assertSame('chunk_policy_stale', $readiness->reason);
        self::assertStringStartsWith('agent-map search-index refresh ', (string) $readiness->recoveryCommand);
    }

    public function testEmptySearchForNonEmptyMapIsStaleEvenWhenMetadataMatches(): void
    {
        $map = $this->writeMap('sha256:current');
        $this->writeSearchDatabase('sha256:current', chunkCount: 0);

        $readiness = (new SearchReadinessInspector())->inspect($map, $this->mapPath, $this->searchPath);

        self::assertSame('stale', $readiness->state);
        self::assertSame('search_index_empty', $readiness->reason);
        self::assertStringStartsWith('agent-map search-index refresh ', (string) $readiness->recoveryCommand);
    }

    public function testFingerprintlessMapNeverTreatsSha256NoneAsCurrentnessProof(): void
    {
        $map = $this->writeMap(null);
        $this->writeSearchDatabase('sha256:none');

        $readiness = (new SearchReadinessInspector())->inspect($map, $this->mapPath, $this->searchPath);

        self::assertSame('unavailable', $readiness->state);
        self::assertSame('map_snapshot_unverifiable', $readiness->reason);
        self::assertNull($readiness->recoveryCommand);
    }

    public function testStaleMapCannotAdvertiseRankedSearchRecovery(): void
    {
        $map = $this->writeMap('sha256:current');
        $this->writeSearchDatabase('sha256:current');
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class ChangedFoo {}\n");
        $map = (new IndexReader())->read($this->mapPath);

        $readiness = (new SearchReadinessInspector())->inspect($map, $this->mapPath, $this->searchPath);

        self::assertSame('unavailable', $readiness->state);
        self::assertSame('map_stale', $readiness->reason);
        self::assertNull($readiness->recoveryCommand);
    }

    public function testCorruptDatabaseIsInvalidAndDoesNotGetMigrated(): void
    {
        $map = $this->writeMap('sha256:current');
        $directory = dirname($this->searchPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($this->searchPath, 'not sqlite');
        $before = hash_file('sha256', $this->searchPath);
        self::assertIsString($before);

        $readiness = (new SearchReadinessInspector())->inspect($map, $this->mapPath, $this->searchPath);

        self::assertSame('invalid', $readiness->state);
        self::assertSame('search_index_unreadable', $readiness->reason);
        self::assertNull($readiness->recoveryCommand);
        self::assertSame($before, hash_file('sha256', $this->searchPath));
    }

    private function writeMap(?string $snapshot): AgentMapIndex
    {
        $hash = hash_file('sha256', $this->root . '/src/Foo.php');
        self::assertIsString($hash);
        $fingerprint = $snapshot === null
            ? null
            : new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', $snapshot);
        $map = new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: 'test',
            files: [new FileEntry('src/Foo.php', 'sha256:' . $hash, '', [])],
            fingerprint: $fingerprint,
        );
        $directory = dirname($this->mapPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        (new IndexWriter())->write($map, $this->mapPath);

        return $map;
    }

    private function writeSearchDatabase(string $snapshot, ?string $chunkPolicy = null, int $chunkCount = 1): void
    {
        $chunkPolicy ??= (string) ChunkPolicy::VERSION;
        $directory = dirname($this->searchPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        $pdo = new PDO(
            'sqlite:' . $this->searchPath,
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('CREATE TABLE search_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE code_chunks (rowid INTEGER PRIMARY KEY)');
        for ($i = 0; $i < $chunkCount; ++$i) {
            $pdo->exec('INSERT INTO code_chunks DEFAULT VALUES');
        }
        $statement = $pdo->prepare('INSERT INTO search_meta (key, value) VALUES (:key, :value)');
        $statement->execute(['key' => 'map_snapshot', 'value' => $snapshot]);
        $statement->execute(['key' => 'chunk_policy_version', 'value' => $chunkPolicy]);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
