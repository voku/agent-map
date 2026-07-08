<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\SymbolEntry;

final class AgentMapIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-index-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/EvidenceValidator.php', '<?php echo 1;');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSerializesAndReadsJson(): void
    {
        $index = $this->index();
        $file = $this->root . '/.agent-map/php-symbols.json';

        (new IndexWriter())->write($index, $file);
        $read = (new IndexReader())->read($file);

        self::assertSame('1.0', $read->schemaVersion);
        self::assertSame('src/EvidenceValidator.php', $read->files[0]->path);
    }

    public function testQueryFindsByClassNameAndMethodName(): void
    {
        $index = $this->index();

        self::assertCount(1, $index->query('EvidenceValidator'));
        self::assertCount(1, $index->query('validateAgentHistoryReference'));
    }

    public function testFileLookupWorks(): void
    {
        $file = $this->index()->file('src/EvidenceValidator.php');

        self::assertNotNull($file);
        self::assertSame('voku\AgentLearning', $file->namespace);
    }

    public function testFileLookupPreservesLeadingDotDirectory(): void
    {
        $entry = new FileEntry('.tools/AgentHook.php', 1, 'hash', 'Demo', []);
        $index = new AgentMapIndex('1.0', 'now', $this->root, 'token', [$entry]);

        self::assertSame($entry, $index->file('.tools/AgentHook.php'));
        self::assertSame($entry, $index->file('./.tools/AgentHook.php'));
    }

    public function testStaleDetectsModifiedAndMissingFiles(): void
    {
        $index = $this->index();
        touch($this->root . '/src/EvidenceValidator.php', time() + 5);

        self::assertSame([['path' => 'src/EvidenceValidator.php', 'reason' => 'modified']], $index->staleEntries());

        unlink($this->root . '/src/EvidenceValidator.php');
        self::assertSame([['path' => 'src/EvidenceValidator.php', 'reason' => 'missing']], $index->staleEntries());
    }

    public function testSummaryAndStatsHelpers(): void
    {
        $index = $this->index();

        self::assertSame([
            'files_indexed' => 1,
            'symbols' => 1,
            'classes' => 1,
            'interfaces' => 0,
            'traits' => 0,
            'enums' => 0,
            'functions' => 0,
        ], $index->summaryCounts());
        self::assertSame(2, $index->methodCount());
        self::assertSame([['namespace' => 'voku\AgentLearning', 'symbols' => 1]], $index->topNamespaces());
        self::assertSame([['directory' => 'src', 'files' => 1]], $index->topDirectories());
        self::assertSame([['path' => 'src/EvidenceValidator.php', 'symbols' => 1]], $index->largestFiles());
    }

    public function testLikelyTestFilesIncludesCodeceptionCests(): void
    {
        file_put_contents($this->root . '/src/EvidenceValidator_UnitCest.php', '<?php echo 1;');
        $production = new FileEntry('src/EvidenceValidator.php', 1, 'a', 'Demo', []);
        $test = new FileEntry('src/EvidenceValidator_UnitCest.php', 1, 'b', 'Demo', []);
        $index = new AgentMapIndex('1.0', 'now', $this->root, 'token', [$production, $test]);

        self::assertSame([$test], $index->likelyTestFiles($production));
    }

    private function index(): AgentMapIndex
    {
        $path = $this->root . '/src/EvidenceValidator.php';

        return new AgentMapIndex(
            '1.0',
            '2026-07-07T12:00:00+02:00',
            $this->root,
            'token',
            [
                new FileEntry(
                    'src/EvidenceValidator.php',
                    (int) filemtime($path),
                    (string) sha1_file($path),
                    'voku\AgentLearning',
                    [
                        new SymbolEntry(
                            'class',
                            'EvidenceValidator',
                            'voku\AgentLearning\EvidenceValidator',
                            10,
                            132,
                            [
                                new MethodEntry('validate', 'public', 42),
                                new MethodEntry('validateAgentHistoryReference', 'private', 100),
                            ],
                        ),
                    ],
                ),
            ],
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
