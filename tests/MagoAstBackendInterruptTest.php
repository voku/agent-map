<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Backend\MagoAstBackend;

final class MagoAstBackendInterruptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension not available.');
        }

        if (!is_dir('/proc')) {
            self::markTestSkipped('/proc filesystem not available (Linux-only test).');
        }

        if (!self::magoAvailable()) {
            self::markTestSkipped('mago binary not found on PATH.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-mago-interrupt-' . bin2hex(random_bytes(6));
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

    public function testSigtermDuringParseManyKillsInFlightMagoChildren(): void
    {
        // Enough files, run sequentially, so a `mago ast` child is virtually
        // guaranteed to still be running when we deliver SIGTERM shortly
        // after start.
        $files = [];
        for ($i = 0; $i < 400; ++$i) {
            $file = $this->root . '/Valid' . $i . '.php';
            file_put_contents($file, "<?php\n\nfinal class Valid{$i}\n{\n    public function run(): void\n    {\n    }\n}\n");
            $files[] = $file;
        }

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork() failed');

        if ($pid === 0) {
            // Child: run the (slow, sequential) parse and let our own
            // SIGTERM handler in MagoAstBackend clean up its mago children.
            (new MagoAstBackend())->parseMany($files, 1);
            exit(0);
        }

        usleep(150_000);
        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        usleep(200_000);
        self::assertSame(
            [],
            $this->runningMagoPidsForRoot(),
            'mago child processes spawned for this test directory should not outlive a SIGTERM to the parent.'
        );
    }

    /**
     * @return list<int>
     */
    private function runningMagoPidsForRoot(): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlinePath) {
            $cmdline = @file_get_contents($cmdlinePath);
            if ($cmdline === false) {
                continue;
            }

            if (str_contains($cmdline, 'mago') && str_contains($cmdline, $this->root)) {
                $pids[] = (int) basename(dirname($cmdlinePath));
            }
        }

        return $pids;
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
