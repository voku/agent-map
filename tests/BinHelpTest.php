<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BinHelpTest extends TestCase
{
    public function testBuildHelpDocumentsArtifactPathBase(): void
    {
        [$exit, $stdout, $stderr] = $this->run(['build', '--help']);

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString(
            '--out, --index, and --database are relative to --root unless an absolute path is given.',
            $stdout,
        );
    }

    public function testLiteralHelpArgumentDoesNotAppendHelpToNormalCommandOutput(): void
    {
        $root = sys_get_temp_dir() . '/agent-map-bin-help-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root, 0o775, true));
        file_put_contents($root . '/Example.php', "<?php\nfinal class Example {}\n");

        try {
            [$exit, $stdout, $stderr] = $this->run([
                'build',
                'help',
                '--root=' . $root,
                '--paths=.',
                '--out=map.json',
            ]);

            self::assertSame(0, $exit, $stderr);
            self::assertStringContainsString('Wrote 1 file(s)', $stdout);
            self::assertStringNotContainsString('Artifact paths:', $stdout);
        } finally {
            @unlink($root . '/map.json');
            @unlink($root . '/Example.php');
            @rmdir($root);
        }
    }

    /** @param list<string> $arguments
     *  @return array{int, string, string}
     */
    private function run(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-map', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start agent-map.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Unable to read agent-map output.');
        }

        return [$exit, $stdout, $stderr];
    }
}
