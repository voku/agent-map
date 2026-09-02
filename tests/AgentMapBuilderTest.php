<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentMap\Build\SemanticAnalysisResult;
use voku\AgentMap\Build\SemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Extract\ExtractResult;
use voku\AgentMap\Extract\SymbolExtractor;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;

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

    public function testBuildParsesEachFileSequentially(): void
    {
        $extractor = new RecordingExtractor();

        (new AgentMapBuilder(extractor: $extractor, semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src'], []);

        self::assertSame(['Alpha.php', 'Beta.php', 'Gamma.php'], $this->relativeCalls($extractor->extractCalls));
    }

    public function testBuildProducesSchemaTwoHashesStatusesAndFingerprint(): void
    {
        $index = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src'], []);

        self::assertSame('2.0', $index->schemaVersion);
        self::assertSame('simple-php-code-parser+structural-only', $index->backend);
        self::assertStringStartsWith('sha256:', $index->files[0]->sha256);
        self::assertSame('no_semantic_records', $index->files[0]->semanticStatus);
        self::assertNotNull($index->fingerprint);
        self::assertSame('none', $index->fingerprint->phpStanVersion);
        self::assertStringStartsWith('sha256:', $index->fingerprint->sourceDigest);
        self::assertNotNull($index->fingerprint->semanticScope);
        self::assertSame(['src'], $index->fingerprint->semanticScope->paths);
        self::assertSame([], $index->fingerprint->semanticScope->excludes);
    }

    public function testMergeKeepsUntouchedFilesAndDropsDeletedOnes(): void
    {
        $builder = new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer());
        $previous = $builder->build($this->root, ['src'], []);

        file_put_contents($this->root . '/src/Beta.php', $this->source('Beta') . "\n// changed\n");
        file_put_contents($this->root . '/src/Delta.php', $this->source('Delta'));
        unlink($this->root . '/src/Gamma.php');

        $merged = $builder->build($this->root, ['src/Beta.php', 'src/Delta.php'], [], null, null, $previous);

        self::assertSame(
            ['src/Alpha.php', 'src/Beta.php', 'src/Delta.php'],
            array_map(static fn (FileEntry $file): string => $file->path, $merged->files),
        );
        self::assertNotSame(
            $this->fileByPath($previous, 'src/Beta.php')->sha256,
            $this->fileByPath($merged, 'src/Beta.php')->sha256,
        );
        self::assertSame(
            $this->fileByPath($previous, 'src/Alpha.php')->sha256,
            $this->fileByPath($merged, 'src/Alpha.php')->sha256,
        );
    }

    public function testMergeRejectsDifferentSemanticBackend(): void
    {
        $previous = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src'], []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot incrementally merge map backend');

        (new AgentMapBuilder(semanticAnalyzer: new RecordingSemanticAnalyzer()))
            ->build($this->root, ['src/Alpha.php'], [], null, null, $previous);
    }

    private function fileByPath(AgentMapIndex $index, string $path): FileEntry
    {
        foreach ($index->files as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        throw new RuntimeException('File missing from index: ' . $path);
    }

    public function testPhpStanConfigurationDiscoveryPrefersPhpstanNeon(): void
    {
        file_put_contents($this->root . '/phpstan.neon.dist', "parameters:
    level: 1
");
        file_put_contents($this->root . '/phpstan.neon', "parameters:
    level: 2
");
        $semantic = new RecordingSemanticAnalyzer();

        (new AgentMapBuilder(semanticAnalyzer: $semantic))->build($this->root, ['src'], [], null, '512M');

        self::assertSame($this->root . '/phpstan.neon', $semantic->configurationFile);
        self::assertSame('512M', $semantic->memoryLimit);
    }

    public function testBuildKeepsDirectoryScopeWhenMapExcludesFiles(): void
    {
        $semantic = new RecordingSemanticAnalyzer();

        (new AgentMapBuilder(semanticAnalyzer: $semantic))->build(
            $this->root,
            ['src'],
            ['~(^|/)ignored(/|$)~'],
        );

        self::assertSame(['src'], $semantic->analyseDirectories);
    }

    public function testMissingExplicitPhpStanConfigurationFailsClearly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PHPStan configuration not found');

        (new AgentMapBuilder(semanticAnalyzer: new RecordingSemanticAnalyzer()))->build($this->root, ['src'], [], 'missing.neon');
    }

    public function testStructuralOnlyRejectsExplicitPhpStanOptions(): void
    {
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n    level: 1\n");
        $builder = new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer());
        $cases = [
            ['phpstan.neon', null, []],
            [null, '512M', []],
            [null, null, ['vendor/library']],
        ];

        foreach ($cases as [$configuration, $memoryLimit, $scanPaths]) {
            try {
                $builder->build($this->root, ['src'], [], $configuration, $memoryLimit, null, $scanPaths);
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'PHPStan semantic capability is unavailable; PHPStan-specific build options require phpstan/phpstan.',
                    $exception->getMessage(),
                );
                continue;
            }

            self::fail('Expected explicit PHPStan build options to require the PHPStan capability.');
        }
    }

    public function testFailureRaisesRuntimeExceptionForFailingFile(): void
    {
        $extractor = new FailingExtractor('Beta.php', 'boom');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('src/Beta.php');

        (new AgentMapBuilder(extractor: $extractor, semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src'], []);
    }

    public function testBuildingManyFilesStaysWithinAModestMemoryBudget(): void
    {
        $manyRoot = sys_get_temp_dir() . '/agent-map-builder-many-' . bin2hex(random_bytes(6));
        mkdir($manyRoot . '/src', 0o775, true);
        for ($i = 0; $i < 500; ++$i) {
            file_put_contents($manyRoot . '/src/Class' . $i . '.php', $this->source('Class' . $i));
        }

        $before = memory_get_usage(true);
        $index = (new AgentMapBuilder(extractor: new RecordingExtractor(), semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($manyRoot, ['src'], []);
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

final class RecordingExtractor implements SymbolExtractor
{
    /** @var list<string> */
    public array $extractCalls = [];

    public function extract(string $file): ExtractResult
    {
        $this->extractCalls[] = $file;

        return new ExtractResult($file, true);
    }
}

final class FailingExtractor implements SymbolExtractor
{
    public function __construct(
        private string $failingBasename,
        private string $error,
    ) {
    }

    public function extract(string $file): ExtractResult
    {
        if (basename($file) === $this->failingBasename) {
            return new ExtractResult($file, false, [], $this->error);
        }

        return new ExtractResult($file, true);
    }
}

final class RecordingSemanticAnalyzer implements SemanticAnalyzer
{
    public ?string $configurationFile = null;
    public ?string $memoryLimit = null;

    /** @var list<string> */
    public array $analyseDirectories = [];

    public function backend(): string
    {
        return 'recording';
    }

    public function analyse(
        string $root,
        array $relativeFiles,
        ?string $configurationFile = null,
        ?string $memoryLimit = null,
        array $analyseDirectories = [],
        array $scanDirectories = [],
    ): SemanticAnalysisResult
    {
        $this->configurationFile = $configurationFile;
        $this->memoryLimit = $memoryLimit;
        $this->analyseDirectories = $analyseDirectories;

        return new SemanticAnalysisResult([], [], 'test-recording', 'sha256:recording');
    }
}
