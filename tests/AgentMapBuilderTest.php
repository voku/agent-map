<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Backend\AstBackend;
use voku\AgentMap\Backend\AstResult;
use voku\AgentMap\Backend\ParallelAstBackend;
use voku\AgentMap\Backend\TokenAstBackend;
use voku\AgentMap\Index\AgentMapBuilder;

final class AgentMapBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-builder-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Alpha.php', $this->source('Alpha'));
        file_put_contents($this->root . '/src/Beta.php', $this->source('Beta'));
        file_put_contents($this->root . '/src/Gamma.php', $this->source('Gamma'));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDefaultWorkersUsesSequentialParseNotParseMany(): void
    {
        $backend = new RecordingParallelBackend();

        (new AgentMapBuilder())->build($this->root, ['src'], [], 'fake', $backend);

        self::assertSame(['Alpha.php', 'Beta.php', 'Gamma.php'], $this->relativeCalls($backend->parseCalls));
        self::assertSame([], $backend->parseManyCalls);
    }

    public function testWorkersAboveOneDispatchesThroughParseMany(): void
    {
        $backend = new RecordingParallelBackend();

        (new AgentMapBuilder())->build($this->root, ['src'], [], 'fake', $backend, 4);

        self::assertSame([], $backend->parseCalls);
        self::assertCount(1, $backend->parseManyCalls);
        self::assertSame(4, $backend->parseManyCalls[0]['workers']);
        self::assertSame(['Alpha.php', 'Beta.php', 'Gamma.php'], $this->relativeCalls($backend->parseManyCalls[0]['files']));
    }

    public function testNonParallelBackendIgnoresWorkersAndStillWorks(): void
    {
        $index = (new AgentMapBuilder())->build($this->root, ['src'], [], 'token', new TokenAstBackend(), 8);

        self::assertCount(3, $index->files);
    }

    public function testEntriesFollowSortedFileOrderRegardlessOfParseManyResultOrder(): void
    {
        $backend = new ReversedOrderParallelBackend();

        $index = (new AgentMapBuilder())->build($this->root, ['src'], [], 'fake', $backend, 4);

        $paths = array_map(static fn ($file) => $file->path, $index->files);
        self::assertSame(['src/Alpha.php', 'src/Beta.php', 'src/Gamma.php'], $paths);
    }

    public function testParallelFailureRaisesRuntimeExceptionForFailingFile(): void
    {
        $backend = new FailingParallelBackend('Beta.php', 'boom');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('src/Beta.php');

        (new AgentMapBuilder())->build($this->root, ['src'], [], 'fake', $backend, 4);
    }

    public function testBuildingManyFilesStaysWithinAModestMemoryBudget(): void
    {
        $manyRoot = sys_get_temp_dir() . '/agent-map-builder-many-' . bin2hex(random_bytes(6));
        mkdir($manyRoot . '/src', 0o775, true);
        for ($i = 0; $i < 500; ++$i) {
            file_put_contents($manyRoot . '/src/Class' . $i . '.php', $this->source('Class' . $i));
        }

        $before = memory_get_usage(true);
        $index = (new AgentMapBuilder())->build($manyRoot, ['src'], [], 'token', new TokenAstBackend());
        $peak = memory_get_peak_usage(true);

        self::assertCount(500, $index->files);
        self::assertLessThan($before + 50 * 1024 * 1024, $peak, 'Indexing 500 small files should not need tens of MB of extra memory.');

        $this->removeDirectory($manyRoot);
    }

    /**
     * @param list<string> $absolutes
     *
     * @return list<string>
     */
    private function relativeCalls(array $absolutes): array
    {
        $relatives = array_map(fn (string $absolute): string => basename($absolute), $absolutes);
        sort($relatives);

        return $relatives;
    }

    private function source(string $class): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class {$class}
        {
            public function run(): void
            {
            }
        }
        PHP;
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

final class RecordingParallelBackend implements AstBackend, ParallelAstBackend
{
    /** @var list<string> */
    public array $parseCalls = [];

    /** @var list<array{files: list<string>, workers: int}> */
    public array $parseManyCalls = [];

    public function parse(string $file): AstResult
    {
        $this->parseCalls[] = $file;

        return new AstResult($file, true);
    }

    public function parseMany(array $files, int $workers): array
    {
        $this->parseManyCalls[] = ['files' => $files, 'workers' => $workers];

        $results = [];
        foreach ($files as $file) {
            $results[$file] = new AstResult($file, true);
        }

        return $results;
    }
}

final class ReversedOrderParallelBackend implements AstBackend, ParallelAstBackend
{
    public function parse(string $file): AstResult
    {
        return new AstResult($file, true);
    }

    public function parseMany(array $files, int $workers): array
    {
        $results = [];
        foreach (array_reverse($files) as $file) {
            $results[$file] = new AstResult($file, true);
        }

        return $results;
    }
}

final class FailingParallelBackend implements AstBackend, ParallelAstBackend
{
    public function __construct(
        private string $failingBasename,
        private string $error,
    ) {
    }

    public function parse(string $file): AstResult
    {
        return $this->resultFor($file);
    }

    public function parseMany(array $files, int $workers): array
    {
        $results = [];
        foreach ($files as $file) {
            $results[$file] = $this->resultFor($file);
        }

        return $results;
    }

    private function resultFor(string $file): AstResult
    {
        if (basename($file) === $this->failingBasename) {
            return new AstResult($file, false, $this->error);
        }

        return new AstResult($file, true);
    }
}
