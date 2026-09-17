<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Extract\ExtractResult;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;

final class ParallelSymbolExtractorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/parallel-extract-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*.php');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
    }

    public function testExtractManyProducesIdenticalResultsToSingleExtract(): void
    {
        $files = [];
        for ($i = 1; $i <= 12; ++$i) {
            $path = $this->tempDir . '/Class' . $i . '.php';
            file_put_contents($path, <<<PHP
            <?php
            namespace Acme\\Demo;

            class Class{$i} extends \\stdClass
            {
                public function method{$i}(string \$arg): int
                {
                    return {$i};
                }
            }
            PHP);
            $files[] = $path;
        }

        $extractor = new SimplePhpParserSymbolExtractor();

        $sequential = [];
        foreach ($files as $file) {
            $sequential[$file] = $extractor->extract($file);
        }

        $parallel = $extractor->extractMany($files);

        self::assertCount(count($files), $parallel);
        foreach ($files as $file) {
            self::assertArrayHasKey($file, $parallel);
            $seqResult = $sequential[$file];
            $parResult = $parallel[$file];

            self::assertTrue($parResult->ok);
            self::assertSame($seqResult->ok, $parResult->ok);
            self::assertSame($seqResult->file, $parResult->file);
            self::assertCount(count($seqResult->symbols), $parResult->symbols);

            for ($j = 0; $j < count($seqResult->symbols); ++$j) {
                self::assertSame($seqResult->symbols[$j]->fqn, $parResult->symbols[$j]->fqn);
                self::assertSame($seqResult->symbols[$j]->kind, $parResult->symbols[$j]->kind);
                self::assertSame($seqResult->symbols[$j]->lineStart, $parResult->symbols[$j]->lineStart);
                self::assertCount(count($seqResult->symbols[$j]->methods), $parResult->symbols[$j]->methods);
            }
        }
    }

    public function testExtractManyHandlesEmptyList(): void
    {
        $extractor = new SimplePhpParserSymbolExtractor();
        self::assertSame([], $extractor->extractMany([]));
    }

    public function testExtractManyHandlesInvalidSyntax(): void
    {
        $validFile = $this->tempDir . '/Valid.php';
        file_put_contents($validFile, '<?php class Valid {}');

        $invalidFile = $this->tempDir . '/Invalid.php';
        file_put_contents($invalidFile, '<?php class Invalid { syntax error');

        $files = [$validFile, $invalidFile];

        $extractor = new SimplePhpParserSymbolExtractor();
        $results = $extractor->extractMany($files);

        self::assertTrue($results[$validFile]->ok);
        self::assertFalse($results[$invalidFile]->ok);
        self::assertNotNull($results[$invalidFile]->error);
    }

    public function testProcOpenParallelWorker(): void
    {
        $files = [];
        for ($i = 1; $i <= 6; ++$i) {
            $path = $this->tempDir . '/ProcOpen' . $i . '.php';
            file_put_contents($path, "<?php namespace Demo; class ProcOpen{$i} { public function f(): void {} }");
            $files[] = $path;
        }

        $extractor = new SimplePhpParserSymbolExtractor();
        $ref = new \ReflectionMethod($extractor, 'runProcOpenParallel');
        /** @var array<string, ExtractResult>|null $results */
        $results = $ref->invoke($extractor, $files);

        self::assertNotNull($results);
        self::assertCount(6, $results);
        foreach ($files as $file) {
            self::assertArrayHasKey($file, $results);
            self::assertTrue($results[$file]->ok);
            self::assertCount(1, $results[$file]->symbols);
        }
    }
}
