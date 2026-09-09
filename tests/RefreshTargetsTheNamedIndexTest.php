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
        $this->executeAgentMap(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']);
        $this->rewriteBackend($index, 'simple-php-code-parser+some-other-backend');
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n}\n");

        [, , $stderr] = $this->executeAgentMap(['refresh', '--root=' . $this->root, '--paths=src', '--index=' . $index]);

        // Run it through a real shell, so quoting is judged by the thing that
        // will actually judge it. Nothing is inferred, reordered or added.
        [$exit, , $buildStderr] = $this->executeShell($this->printedBuildCommand($stderr));
        self::assertSame(0, $exit, $buildStderr);

        [$exit, $stdout] = $this->executeAgentMap(['refresh', '--root=' . $this->root, '--paths=src', '--index=' . $index]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('up to date', $stdout);
    }

    public function testTheRemedyRebuildsTheIndexsOwnScopeRatherThanWideningIt(): void
    {
        // A nested coverage is the case the refresh search scope widens to its
        // first path segment. A remedy that widens the index is not a repair.
        if (!mkdir($this->root . '/src/Feature', 0o775, true)) {
            throw new RuntimeException('Unable to create nested fixture directory.');
        }
        rename($this->root . '/src/Greeter.php', $this->root . '/src/Feature/Greeter.php');
        file_put_contents(
            $this->root . '/src/Outsider.php',
            "<?php\n\nnamespace Demo;\n\nfinal class Outsider\n{\n}\n",
        );

        $index = $this->root . '/map.json';
        $this->executeAgentMap([
            'build', '--root=' . $this->root, '--paths=src/Feature', '--out=' . $index, '--backend=structural',
        ]);
        $this->rewriteBackend($index, 'simple-php-code-parser+some-other-backend');
        file_put_contents($this->root . '/src/Feature/Greeter.php', "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n}\n");

        [, , $stderr] = $this->executeAgentMap(['refresh', '--root=' . $this->root, '--index=' . $index]);
        [$exit, , $buildStderr] = $this->executeShell($this->printedBuildCommand($stderr));
        self::assertSame(0, $exit, $buildStderr);

        $rebuilt = json_decode((string) file_get_contents($index), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($rebuilt);
        self::assertSame(
            ['src/Feature/Greeter.php'],
            array_column($rebuilt['files'], 'path'),
            'the remedy pulled a file into the index that its own scope never covered',
        );
    }

    public function testTheRemedyKeepsAStructuralOnlyIndexStructuralOnly(): void
    {
        $index = $this->root . '/map.json';
        $this->executeAgentMap(['build', '--root=' . $this->root, '--paths=src', '--out=' . $index, '--backend=structural']);
        self::assertSame('simple-php-code-parser+structural-only', $this->backendOf($index));

        // The real-world mismatch, with nothing rewritten: an index deliberately
        // built structural-only, refreshed by a run whose default backend
        // resolves to PHPStan. The remedy is judged on whether it restores the
        // backend this index was built with rather than the one that refused.
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n}\n");
        [, , $stderr] = $this->executeAgentMap(['refresh', '--root=' . $this->root, '--paths=src', '--index=' . $index]);
        $command = $this->printedBuildCommand($stderr);
        self::assertStringContainsString('--backend=structural', $command);

        [$exit, , $buildStderr] = $this->executeShell($command);
        self::assertSame(0, $exit, $buildStderr);
        self::assertSame('simple-php-code-parser+structural-only', $this->backendOf($index));
    }

    public function testAPathWithSpacesIsQuotedSoTheRemedyStillRuns(): void
    {
        $spaced = $this->root . '/a project';
        if (!mkdir($spaced . '/src', 0o775, true)) {
            throw new RuntimeException('Unable to create spaced fixture root.');
        }
        file_put_contents($spaced . '/src/Greeter.php', "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n}\n");

        $index = $spaced . '/map.json';
        $this->executeAgentMap(['build', '--root=' . $spaced, '--paths=src', '--out=' . $index, '--backend=structural']);
        $this->rewriteBackend($index, 'simple-php-code-parser+some-other-backend');
        file_put_contents($spaced . '/src/Greeter.php', "<?php\n\nnamespace Demo;\n\nfinal class Greeter\n{\n    public function hi(): void\n    {\n    }\n}\n");

        [, , $stderr] = $this->executeAgentMap(['refresh', '--root=' . $spaced, '--paths=src', '--index=' . $index]);
        [$exit, , $buildStderr] = $this->executeShell($this->printedBuildCommand($stderr));

        self::assertSame(0, $exit, $buildStderr);
        self::assertFileExists($index);
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

    /** The build line the refusal printed, taken verbatim. */
    private function printedBuildCommand(string $stderr): string
    {
        foreach (explode("\n", $stderr) as $line) {
            if (str_starts_with(trim($line), 'agent-map build ')) {
                return trim($line);
            }
        }

        self::fail('the refusal printed no build command: ' . $stderr);
    }

    private function backendOf(string $index): string
    {
        $decoded = json_decode((string) file_get_contents($index), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['backend'] ?? null)) {
            throw new RuntimeException('Unable to read fixture backend.');
        }

        return $decoded['backend'];
    }

    /**
     * Run the printed line through a real shell, with only `agent-map` resolved
     * to this checkout's binary. Everything after it is tokenized by `sh`, so
     * quoting is judged by the thing that will judge it in a terminal.
     *
     * @return array{int, string, string}
     */
    private function executeShell(string $command): array
    {
        $arguments = substr($command, strlen('agent-map '));
        $script = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/agent-map') . ' ' . $arguments;

        $process = proc_open(['sh', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start a shell.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Unable to read shell output.');
        }

        return [$exit, $stdout, $stderr];
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
