<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `refresh --index=X` must act on X and, when it cannot, say how to repair X.
 *
 * Two failures of that promise: the refreshed map was written to the artifact
 * root's default filename rather than the index the caller named, leaving that
 * index stale after a run that reported success; and a refusal that could not
 * merge two semantic backends described the remedy in prose while holding
 * every argument the remedy needed.
 */
final class RefreshTargetsTheNamedIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-remedy-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o775, true)) {
            throw new RuntimeException('Unable to create fixture root.');
        }
        file_put_contents(
            $this->root . '/src/Greeter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Demo;\n\nfinal class Greeter\n{\n    public function greet(): string\n    {\n        return 'hi';\n    }\n}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testARefreshThatCannotMergeBackendsHandsBackAnExecutableFullBuild(): void
    {
        $index = $this->root . '/map.json';
        [$exit] = $this->executeAgentMap(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index]);
        self::assertSame(0, $exit);

        $this->rewriteBackend($index, 'simple-php-code-parser+some-other-backend');
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n}\n");

        [$exit, , $stderr] = $this->executeAgentMap(['refresh', '--root=' . $this->root, '--paths=src', '--index=' . $index]);

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString('simple-php-code-parser+some-other-backend', $stderr);
        // The remedy has to be runnable as printed: every argument the full
        // build needs, not a sentence describing that a full build is needed.
        self::assertStringContainsString('agent-map build --root=' . $this->root, $stderr);
        self::assertStringContainsString('--paths=src', $stderr);
        self::assertStringContainsString('--out=' . $index, $stderr);
    }

    public function testTheRemedyItPrintsActuallyRepairsTheIndex(): void
    {
        $index = $this->root . '/map.json';
        $this->executeAgentMap(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index]);
        $this->rewriteBackend($index, 'simple-php-code-parser+some-other-backend');
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n}\n");

        [, , $stderr] = $this->executeAgentMap(['refresh', '--root=' . $this->root, '--paths=src', '--index=' . $index]);

        $command = null;
        foreach (explode("\n", $stderr) as $line) {
            if (str_starts_with(trim($line), 'agent-map build ')) {
                $command = trim($line);
                break;
            }
        }
        self::assertIsString($command, 'the refusal printed no build command: ' . $stderr);

        // Run exactly what it printed. Nothing may be inferred, reordered or added.
        $arguments = array_slice(explode(' ', $command), 1);
        [$exit, , $buildStderr] = $this->executeAgentMap($arguments);
        self::assertSame(0, $exit, $buildStderr);

        [$exit, $stdout] = $this->executeAgentMap(['refresh', '--root=' . $this->root, '--paths=src', '--index=' . $index]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('up to date', $stdout);
    }

    public function testARefreshWritesBackToTheIndexItWasPointedAt(): void
    {
        $index = $this->root . '/map.json';
        $this->executeAgentMap(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index]);
        file_put_contents(
            $this->root . '/src/Greeter.php',
            "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n    public function greet(): string\n    {\n        return 'hi';\n    }\n\n    public function farewell(): void\n    {\n    }\n}\n",
        );

        [$exit, $stdout, $stderr] = $this->executeAgentMap(['refresh', '--root=' . $this->root, '--paths=src', '--index=' . $index]);

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString($index, $stdout);
        // The named index carries the new declaration, and no second index was
        // created beside it: a success message about a file the caller never
        // named is indistinguishable from a refresh that did nothing.
        self::assertStringContainsString('farewell', (string) file_get_contents($index));
        self::assertFileDoesNotExist($this->root . '/php-symbols.json');
    }

    public function testAnExplicitOutStillWins(): void
    {
        $index = $this->root . '/map.json';
        $out = $this->root . '/elsewhere.json';
        $this->executeAgentMap(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index]);
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n}\n");

        [$exit, , $stderr] = $this->executeAgentMap([
            'refresh', '--root=' . $this->root, '--paths=src', '--index=' . $index, '--out=' . $out,
        ]);

        self::assertSame(0, $exit, $stderr);
        self::assertFileExists($out);
    }

    public function testAMatchingBackendStillRefreshesNormally(): void
    {
        $index = $this->root . '/map.json';
        $this->executeAgentMap(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index]);
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n}\n");

        [$exit, $stdout, $stderr] = $this->executeAgentMap(['refresh', '--root=' . $this->root, '--paths=src', '--index=' . $index]);

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('Refreshed', $stdout);
    }

    private function rewriteBackend(string $index, string $backend): void
    {
        $decoded = json_decode((string) file_get_contents($index), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Unable to decode fixture index.');
        }
        $decoded['backend'] = $backend;
        file_put_contents($index, json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function executeAgentMap(array $arguments): array
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
