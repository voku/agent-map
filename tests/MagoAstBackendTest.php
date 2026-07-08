<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Backend\MagoAstBackend;

final class MagoAstBackendTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!self::magoAvailable()) {
            self::markTestSkipped('mago binary not found on PATH.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-mago-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        foreach (glob($this->root . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->root);
    }

    public function testParseManyMatchesSequentialParseForValidFiles(): void
    {
        $files = $this->writeValidFiles(6);
        $backend = new MagoAstBackend();

        $sequential = [];
        foreach ($files as $file) {
            $sequential[$file] = $backend->parse($file)->ok;
        }

        $parallel = $backend->parseMany($files, 3);

        self::assertCount(count($files), $parallel);
        foreach ($files as $file) {
            self::assertSame($sequential[$file], $parallel[$file]->ok, $file);
            self::assertTrue($parallel[$file]->ok, $file);
        }
    }

    public function testParseManyReportsFailureForInvalidSyntax(): void
    {
        $files = $this->writeValidFiles(3);
        $invalid = $this->root . '/Broken.php';
        file_put_contents($invalid, '<?php class Broken {');
        $files[] = $invalid;

        $results = (new MagoAstBackend())->parseMany($files, 4);

        self::assertFalse($results[$invalid]->ok);
        self::assertNotNull($results[$invalid]->error);
        foreach (array_slice($files, 0, 3) as $file) {
            self::assertTrue($results[$file]->ok, $file);
        }
    }

    public function testParseManyReturnsResultForEveryRequestedFileEvenWithMoreWorkersThanFiles(): void
    {
        $files = $this->writeValidFiles(2);

        $results = (new MagoAstBackend())->parseMany($files, 16);

        self::assertCount(2, $results);
    }

    public function testParallelDispatchIsFasterThanSequentialForManySmallFiles(): void
    {
        $files = $this->writeValidFiles(24);
        $backend = new MagoAstBackend();

        $sequentialStart = microtime(true);
        foreach ($files as $file) {
            $backend->parse($file);
        }
        $sequentialElapsed = microtime(true) - $sequentialStart;

        $parallelStart = microtime(true);
        $backend->parseMany($files, 8);
        $parallelElapsed = microtime(true) - $parallelStart;

        self::assertLessThan(
            $sequentialElapsed,
            $parallelElapsed,
            "Parallel parseMany (workers=8) took {$parallelElapsed}s, sequential parse() took {$sequentialElapsed}s; expected the worker pool to win on a 24-file batch."
        );
    }

    /**
     * @return list<string>
     */
    private function writeValidFiles(int $count): array
    {
        $files = [];
        for ($i = 0; $i < $count; ++$i) {
            $file = $this->root . '/Valid' . $i . '.php';
            file_put_contents($file, "<?php\n\nfinal class Valid{$i}\n{\n    public function run(): void\n    {\n    }\n}\n");
            $files[] = $file;
        }

        return $files;
    }

    private static function magoAvailable(): bool
    {
        $process = @proc_open(['mago', 'version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }
}
