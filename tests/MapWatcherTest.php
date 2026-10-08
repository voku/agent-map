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
}
