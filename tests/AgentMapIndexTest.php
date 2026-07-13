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

        $classMatch = $index->query('EvidenceValidator');
        self::assertSame('exact', $classMatch->matchType);
        self::assertCount(1, $classMatch->files);

        $methodMatch = $index->query('validateAgentHistoryReference');
        self::assertSame('exact', $methodMatch->matchType);
        self::assertCount(1, $methodMatch->files);
    }

    public function testQueryFallsBackToNormalizedMatchAcrossCaseAndSeparators(): void
    {
        $index = $this->index();

        // Literal method name is camelCase; querying the snake_case spelling has no literal
        // substring hit, so this only succeeds via the case/separator-insensitive fallback.
        $match = $index->query('validate_agent_history_reference');

        self::assertSame('normalized', $match->matchType);
        self::assertCount(1, $match->files);
    }

    public function testQueryCombinesLiteralAndNormalizedMatches(): void
    {
        $dto = new FileEntry(
            'modules/AntragCreateDataTransferObjectM365EntraAppAnpassen.php',
            1,
            'dto',
            'Demo',
            [new SymbolEntry('class', 'AntragCreateDataTransferObjectM365EntraAppAnpassen', 'Demo\\AntragCreateDataTransferObjectM365EntraAppAnpassen', 1, 5)],
        );
        $module = new FileEntry(
            'modules/ModuleM365_EntraAppAnpassen.php',
            1,
            'module',
            'Demo',
            [new SymbolEntry('class', 'ModuleM365_EntraAppAnpassen', 'Demo\\ModuleM365_EntraAppAnpassen', 1, 5)],
        );
        $index = new AgentMapIndex('1.0', 'now', $this->root, 'simple', [$dto, $module]);

        $match = $index->query('M365EntraAppAnpassen');

        self::assertSame('mixed', $match->matchType);
        self::assertSame([$dto->path, $module->path], array_map(static fn (FileEntry $file): string => $file->path, $match->files));
    }

    public function testMethodQueryKeepsOnlyTheMatchingMethod(): void
    {
        $match = $this->index()->query('validateAgentHistoryReference');

        self::assertSame('exact', $match->matchType);
        self::assertCount(1, $match->files);
        self::assertCount(1, $match->files[0]->symbols);
        self::assertSame(['validateAgentHistoryReference'], array_map(static fn (MethodEntry $method): string => $method->name, $match->files[0]->symbols[0]->methods));
    }

    public function testQueryReportsNoneWhenNothingMatchesEvenNormalized(): void
    {
        $index = $this->index();

        $match = $index->query('TotallyUnrelatedSymbolName');

        self::assertSame('none', $match->matchType);
        self::assertSame([], $match->files);
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
        $index = new AgentMapIndex('1.0', 'now', $this->root, 'simple', [$entry]);

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
        $index = new AgentMapIndex('1.0', 'now', $this->root, 'simple', [$production, $test]);

        self::assertSame([$test], $index->likelyTestFiles($production));
    }

    public function testLikelyTestFilesForCombinesRelatedProductionFiles(): void
    {
        $first = new FileEntry('src/FirstService.php', 1, 'a', 'Demo', []);
        $second = new FileEntry('src/SecondService.php', 1, 'b', 'Demo', []);
        $firstTest = new FileEntry('tests/FirstServiceTest.php', 1, 'c', 'Demo\\Tests', []);
        $secondTest = new FileEntry('tests/SecondServiceTest.php', 1, 'd', 'Demo\\Tests', []);
        $index = new AgentMapIndex('1.0', 'now', $this->root, 'simple', [$first, $second, $firstTest, $secondTest]);

        self::assertSame([$firstTest, $secondTest], $index->likelyTestFilesFor([$first, $second]));
    }

    private function index(): AgentMapIndex
    {
        $path = $this->root . '/src/EvidenceValidator.php';

        return new AgentMapIndex(
            '1.0',
            '2026-07-07T12:00:00+02:00',
            $this->root,
            'simple',
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
