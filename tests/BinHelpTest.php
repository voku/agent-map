<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BinHelpTest extends TestCase
{
    public function testBuildHelpDocumentsArtifactPathBase(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-map', 'build', '--help'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start agent-map help.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, (string) $stderr);
        self::assertIsString($stdout);
        self::assertStringContainsString(
            '--out, --index, and --database are relative to --root unless an absolute path is given.',
            $stdout,
        );
    }
}
