<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\CliOptions;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapWatcher;

final class MapWatcherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-watch-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/A.php', "<?php\nfinal class A { public function one(): int { return 1; } }\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/{src,.agent-map}/*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        foreach (['/src', '/.agent-map', ''] as $directory) {
            @rmdir($this->root . $directory);
        }
    }

    public function testRefreshesOnlyAfterChangesSettle(): void
    {
        $options = CliOptions::parse(['watch', '--root=' . $this->root, '--paths=src', '--backend=structural']);
        $request = new MapPreparationRequest(
            root: $options->root,
            indexPath: $options->index,
            outputPath: $options->out,
            format: 'json',
            paths: $options->paths,
            pathsProvided: true,
            scanPaths: [],
            scanPathsProvided: false,
            excludes: [],
            excludesProvided: false,
            backend: 'structural',
            phpStanConfig: null,
            phpStanMemoryLimit: null,
            artifacts: $options->artifacts,
        );

        $sleeps = 0;
        $root = $this->root;
        $watcher = new MapWatcher(sleepMilliseconds: static function () use (&$sleeps, $root): void {
            ++$sleeps;
            if ($sleeps === 2) {
                // A save burst: two files touched across consecutive polls.
                file_put_contents($root . '/src/A.php', "<?php\nfinal class A { public function one(): int { return 1; } public function two(): int { return 2; } }\n");
                touch($root . '/src/A.php', time() + 5);
            }
            if ($sleeps === 3) {
                file_put_contents($root . '/src/B.php', "<?php\nfinal class B {}\n");
            }
        });

        $log = [];
        $refreshes = $watcher->watch(
            $request,
            50,
            static function (string $line) use (&$log): void {
                $log[] = $line;
            },
            static fn (): bool => false,
            maxCycles: 6,
        );

        self::assertSame(2, $refreshes, implode("\n", $log));
        self::assertStringContainsString('2 changed', $log[1] ?? '', implode("\n", $log));
        self::assertStringContainsString('two', (string) file_get_contents($options->out));
        self::assertStringContainsString('"fqn":"B"', (string) file_get_contents($options->out));
    }

    public function testStopsWhenAskedAndSurvivesRefreshFailure(): void
    {
        $options = CliOptions::parse(['watch', '--root=' . $this->root, '--paths=src', '--backend=structural']);
        $request = new MapPreparationRequest(
            $options->root, $options->index, $options->out, 'json', $options->paths, true, [], false, [], false,
            'structural', null, null, $options->artifacts,
        );

        $log = [];
        $refreshes = (new MapWatcher(sleepMilliseconds: static function (): void {
        }))->watch(
            $request,
            50,
            static function (string $line) use (&$log): void {
                $log[] = $line;
            },
            static fn (): bool => true,
        );

        self::assertSame(1, $refreshes);
        self::assertCount(1, $log);
    }

    public function testWatchOptionsParse(): void
    {
        $options = CliOptions::parse(['watch', '--root=.', '--interval=250']);
        self::assertSame('watch', $options->command);
        self::assertSame(250, $options->watchIntervalMilliseconds);

        $this->expectException(\InvalidArgumentException::class);
        CliOptions::parse(['watch', '--interval=10']);
    }

    /**
     * End to end through bin/agent-map: build, react to an added file, react
     * to a removed file, refuse a second watcher, stop cleanly on SIGTERM.
     */
    public function testWatchProcessEndToEnd(): void
    {
        if (!function_exists('proc_open') || !function_exists('posix_kill')) {
            self::markTestSkipped('proc_open/posix required');
        }

        $bin = dirname(__DIR__) . '/bin/agent-map';
        $command = [PHP_BINARY, '-d', 'xdebug.mode=off', $bin, 'watch', '--root=' . $this->root, '--paths=src', '--backend=structural', '--interval=50'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $index = $this->root . '/.agent-map/php-symbols.json';
        try {
            $this->waitFor(static fn (): bool => is_file($index), 'initial build');

            file_put_contents($this->root . '/src/C.php', "<?php\nfinal class C {}\n");
            $this->waitFor(static fn (): bool => str_contains((string) file_get_contents($index), '"fqn":"C"'), 'added file indexed');

            unlink($this->root . '/src/C.php');
            $this->waitFor(static fn (): bool => !str_contains((string) file_get_contents($index), '"fqn":"C"'), 'removed file dropped');

            $second = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $secondPipes);
            self::assertIsResource($second);
            $secondError = stream_get_contents($secondPipes[2]);
            self::assertSame(1, proc_close($second));
            self::assertStringContainsString('already running', (string) $secondError);
        } finally {
            $status = proc_get_status($process);
            posix_kill($status['pid'], SIGTERM);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(20_000);
            }
            self::assertFalse(proc_get_status($process)['running'], 'watch must stop on SIGTERM');
            proc_close($process);
        }
    }

    private function waitFor(callable $condition, string $what): void
    {
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            clearstatcache();
            if ($condition()) {
                return;
            }
            usleep(50_000);
        }
        self::fail('Timed out waiting for: ' . $what);
    }
}
