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
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Inspect\MapReadinessInspector;
use voku\AgentMap\MapArtifactPaths;

final class MapReadinessInspectorTest extends TestCase
{
    private string $root;

    private MapArtifactPaths $artifacts;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-readiness-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        $this->artifacts = MapArtifactPaths::forProject($this->root, $this->root . '/state/map');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testMissingMapLeavesSearchUnavailableAndCreatesNothing(): void
    {
        $readiness = (new MapReadinessInspector())->inspect($this->artifacts);

        self::assertSame('missing', $readiness->mapState);
        self::assertSame('unavailable', $readiness->searchState);
        self::assertNull($readiness->currentMap());
        self::assertFileDoesNotExist($this->artifacts->searchDatabase());
    }

    public function testInvalidMapIsReportedWithoutInspectingSearch(): void
    {
        $this->writeRawMap('not a map');

        $readiness = (new MapReadinessInspector())->inspect($this->artifacts);

        self::assertSame('invalid', $readiness->mapState);
        self::assertSame('unavailable', $readiness->searchState);
        self::assertNotNull($readiness->mapFailure);
        self::assertNull($readiness->currentMap());
    }

    public function testFreshMapIsReadyButMissingSearchIsDistinct(): void
    {
        $this->writeMap('sha256:current');

        $readiness = (new MapReadinessInspector())->inspect($this->artifacts);

        self::assertSame('ready', $readiness->mapState);
        self::assertSame('sha256:current', $readiness->mapSnapshot);
        self::assertSame('missing', $readiness->searchState);
        self::assertNotNull($readiness->currentMap());
        self::assertFalse($readiness->rankedSearchReady());
    }

    public function testChangedAndMissingSourcesMakeTheMapStale(): void
    {
        $this->writeMap('sha256:current');
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class ChangedFoo {}\n");

        $changed = (new MapReadinessInspector())->inspect($this->artifacts);
        self::assertSame('stale', $changed->mapState);
        self::assertSame([['path' => 'src/Foo.php', 'reason' => 'hash']], $changed->staleEntries);
        self::assertSame('unavailable', $changed->searchState);
        self::assertNull($changed->currentMap());

        unlink($this->root . '/src/Foo.php');
        $missing = (new MapReadinessInspector())->inspect($this->artifacts);
        self::assertSame([['path' => 'src/Foo.php', 'reason' => 'missing']], $missing->staleEntries);
    }

    public function testFreshMapWithoutFingerprintKeepsStructuralUseButNotRankedSearch(): void
    {
        $this->writeMap(null);
        $this->writeSearchDatabase('sha256:anything');

        $readiness = (new MapReadinessInspector())->inspect($this->artifacts);

        self::assertSame('ready', $readiness->mapState);
        self::assertNull($readiness->mapSnapshot);
        self::assertNotNull($readiness->currentMap());
        self::assertSame('unavailable', $readiness->searchState);
        self::assertFalse($readiness->rankedSearchReady());
    }

    public function testSearchWithoutSnapshotMetadataIsInvalid(): void
    {
        $this->writeMap('sha256:current');
        $this->writeSearchDatabase(null);

        $readiness = (new MapReadinessInspector())->inspect($this->artifacts);

        self::assertSame('invalid', $readiness->searchState);
        self::assertNull($readiness->searchSnapshot);
        self::assertNotNull($readiness->searchFailure);
    }

    public function testSearchFromAnotherMapSnapshotIsStale(): void
    {
        $this->writeMap('sha256:current');
        $this->writeSearchDatabase('sha256:older');

        $readiness = (new MapReadinessInspector())->inspect($this->artifacts);

        self::assertSame('stale', $readiness->searchState);
        self::assertSame('sha256:older', $readiness->searchSnapshot);
        self::assertFalse($readiness->rankedSearchReady());
    }

    public function testCurrentSearchIsReadyAndInspectionDoesNotMutateDatabase(): void
    {
        $this->writeMap('sha256:current');
        $this->writeSearchDatabase('sha256:current');
        $before = hash_file('sha256', $this->artifacts->searchDatabase());
        self::assertIsString($before);

        $readiness = (new MapReadinessInspector())->inspect($this->artifacts);

        self::assertSame('ready', $readiness->searchState);
        self::assertSame('sha256:current', $readiness->searchSnapshot);
        self::assertTrue($readiness->rankedSearchReady());
        self::assertSame($before, hash_file('sha256', $this->artifacts->searchDatabase()));
    }

    public function testCorruptSearchDatabaseIsInvalidRatherThanMissing(): void
    {
        $this->writeMap('sha256:current');
        $directory = dirname($this->artifacts->searchDatabase());
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($this->artifacts->searchDatabase(), 'definitely not sqlite');

        $readiness = (new MapReadinessInspector())->inspect($this->artifacts);

        self::assertSame('invalid', $readiness->searchState);
        self::assertNotNull($readiness->searchFailure);
    }

    private function writeMap(?string $snapshot): void
    {
        $hash = hash_file('sha256', $this->root . '/src/Foo.php');
        self::assertIsString($hash);
        $fingerprint = $snapshot === null
            ? null
            : new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', $snapshot);
        $index = new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: 'test',
            files: [new FileEntry('src/Foo.php', 'sha256:' . $hash, '', [])],
            fingerprint: $fingerprint,
        );

        (new IndexWriter())->write($index, $this->artifacts->indexJson());
    }

    private function writeRawMap(string $contents): void
    {
        $directory = dirname($this->artifacts->indexJson());
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($this->artifacts->indexJson(), $contents);
    }

    private function writeSearchDatabase(?string $snapshot): void
    {
        $directory = dirname($this->artifacts->searchDatabase());
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        $pdo = new PDO(
            'sqlite:' . $this->artifacts->searchDatabase(),
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('CREATE TABLE search_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        if ($snapshot !== null) {
            $statement = $pdo->prepare('INSERT INTO search_meta (key, value) VALUES (:key, :value)');
            $statement->execute(['key' => 'map_snapshot', 'value' => $snapshot]);
        }
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
