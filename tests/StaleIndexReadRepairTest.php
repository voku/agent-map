<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Cli\AgentMapApplication;

/**
 * An exact read brings its own index current when that is safe.
 *
 * `scope` used to answer a stale index with "Agent map is stale. Rebuild it
 * before inspecting a scope." - no index path, no stale count, no command. The
 * host then had to remember `stale` -> `refresh` -> re-issue, and an edit to an
 * unrelated file was enough to trigger it. A tool that answers a maintenance
 * errand instead of the question loses to text search, which has no freshness
 * concept and always answers something.
 *
 * Repair stays narrow: same backend only, the recorded scope only, and a loud
 * refusal with an executable command whenever those do not hold.
 */
final class StaleIndexReadRepairTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-read-repair-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o775, true);
        $this->write('src/Foo.php', "<?php\n\nnamespace Demo;\n\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n");
        $this->write('src/Other.php', "<?php\n\nnamespace Demo;\n\nclass Other\n{\n    public function keep(): void\n    {\n    }\n}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAnExactReadAnswersAQuestionOnlyTheRepairCouldAnswer(): void
    {
        $this->build();
        // The method below does not exist in the index that was just written.
        $this->write('src/Foo.php', "<?php\n\nnamespace Demo;\n\nclass Foo\n{\n    public function baz(): void\n    {\n    }\n}\n");

        [$exit, $stdout] = $this->scope('Demo\Foo::baz');

        self::assertSame(0, $exit);
        self::assertStringContainsString('Demo\Foo::baz', $stdout);
    }

    public function testTheRepairKeepsTheRecordedScopeInsteadOfTheFilesThatChanged(): void
    {
        $this->build();
        $this->write('src/Foo.php', "<?php\n\nnamespace Demo;\n\nclass Foo\n{\n    public function baz(): void\n    {\n    }\n}\n");

        $this->scope('Demo\Foo::baz');

        $index = $this->decodeIndex();
        self::assertSame(
            ['src'],
            $index['fingerprint']['semantic_scope']['paths'] ?? null,
            'Recording the changed file as the scope would stop a later refresh looking anywhere else.',
        );
    }

    public function testTheRepairDoesNotDropFilesItDidNotTouch(): void
    {
        $this->build();
        $this->write('src/Foo.php', "<?php\n\nnamespace Demo;\n\nclass Foo\n{\n    public function baz(): void\n    {\n    }\n}\n");

        $this->scope('Demo\Foo::baz');

        $paths = array_column($this->decodeIndex()['files'], 'path');
        sort($paths);
        self::assertSame(['src/Foo.php', 'src/Other.php'], $paths);
    }

    public function testTheRepairIsAnnouncedOnStandardErrorRatherThanSilently(): void
    {
        $this->build();
        $this->write('src/Foo.php', "<?php\n\nnamespace Demo;\n\nclass Foo\n{\n    public function baz(): void\n    {\n    }\n}\n");

        [, , $stderr] = $this->scopeCapturingStderr('Demo\Foo::baz');

        self::assertStringContainsString('Repairing 1 stale file(s)', $stderr);
    }

    public function testACurrentIndexIsAnsweredWithoutAnyRepair(): void
    {
        $this->build();
        $before = filemtime($this->root . '/map.json');

        [$exit, , $stderr] = $this->scopeCapturingStderr('Demo\Foo::bar');

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('Repairing', $stderr);
        self::assertSame($before, filemtime($this->root . '/map.json'));
    }

    public function testAStaleIndexCarryingAnotherBackendRefusesWithAnExecutableCommand(): void
    {
        $this->build();
        $this->write('src/Foo.php', "<?php\n\nnamespace Demo;\n\nclass Foo\n{\n    public function baz(): void\n    {\n    }\n}\n");
        $before = (string) file_get_contents($this->root . '/map.json');

        // No --backend, so this run resolves `auto` while the index is structural-only.
        [$exit, , $stderr] = $this->scopeCapturingStderr('Demo\Foo::baz', false);

        // Decide skipping from the environment, never from the output under test:
        // keying it on the refusal text would turn "the guard was deleted" into a
        // skipped test instead of a failing one.
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Without PHPStan this run resolves the structural backend the index already carries.');
        }

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('stale in 1 file(s)', $stderr);
        self::assertStringContainsString('structural-only', $stderr);
        self::assertStringContainsString('agent-map build', $stderr);
        self::assertStringContainsString('--paths=src', $stderr, 'The rebuild must reuse the scope the index recorded.');
        self::assertStringContainsString('--backend=structural', $stderr);
        self::assertSame(
            $before,
            (string) file_get_contents($this->root . '/map.json'),
            'A refusal must not have rewritten the index it refused to repair.',
        );
    }

    private function build(): void
    {
        ob_start();

        try {
            (new AgentMapApplication())->run([
                'agent-map',
                'build',
                '--root=' . $this->root,
                '--paths=src',
                '--out=' . $this->root . '/map.json',
                '--backend=structural',
            ]);
        } finally {
            ob_end_clean();
        }
    }

    /** @return array{0: int, 1: string} */
    private function scope(string $symbol): array
    {
        [$exit, $stdout] = $this->scopeCapturingStderr($symbol);

        return [$exit, $stdout];
    }

    /**
     * Run the CLI the way a host does, so STDOUT and STDERR stay separable.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function scopeCapturingStderr(string $symbol, bool $forceStructural = true): array
    {
        $command = [
            \PHP_BINARY,
            dirname(__DIR__) . '/bin/agent-map',
            'scope',
            $symbol,
            '--index=' . $this->root . '/map.json',
            '--root=' . $this->root,
        ];
        if ($forceStructural) {
            $command[] = '--backend=structural';
        }

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @return array<string, mixed> */
    private function decodeIndex(): array
    {
        $decoded = json_decode((string) file_get_contents($this->root . '/map.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->root . '/' . $relative, $contents);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
