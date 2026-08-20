<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Dogfood\ReplayBackendContract;
use voku\AgentMap\Search\SearchIndexStore;

/**
 * End-to-end publication contract of the replay harness, against a throwaway local checkout.
 *
 * No network and no upstream clone: the point is not to reproduce the frozen replays but to prove
 * that a published report agrees with the map that produced it, and that a failed run leaves no
 * evidence behind.
 */
final class DogfoodNavigationReplayEvidenceTest extends TestCase
{
    private string $workspace;

    private string $checkout;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('The replay harness needs SQLite FTS5 to build its search index.');
        }

        $probe = [];
        $probeStatus = 0;
        exec('git --version 2>&1', $probe, $probeStatus);
        if ($probeStatus !== 0) {
            self::markTestSkipped('The replay harness needs git to freeze a checkout.');
        }

        $this->workspace = sys_get_temp_dir() . '/agent-map-dogfood-replay-' . bin2hex(random_bytes(8));
        $this->checkout = $this->workspace . '/frozen';
        mkdir($this->checkout . '/src', 0o775, true);
        mkdir($this->checkout . '/tests', 0o775, true);

        file_put_contents(
            $this->checkout . '/src/Greeter.php',
            <<<'CODE'
                <?php

                declare(strict_types=1);

                namespace Demo;

                final class Greeter
                {
                    public function greet(string $name): string
                    {
                        return $this->decorate('Hello ' . $name);
                    }

                    private function decorate(string $message): string
                    {
                        return '[' . $message . ']';
                    }
                }

                CODE,
        );
        file_put_contents(
            $this->checkout . '/tests/GreeterTest.php',
            <<<'CODE'
                <?php

                declare(strict_types=1);

                namespace Demo\Tests;

                use Demo\Greeter;

                final class GreeterTest
                {
                    public function testGreetDecoratesTheMessage(): void
                    {
                        (new Greeter())->greet('world');
                    }
                }

                CODE,
        );

        $this->git('init -q');
        $this->git('add -A');
        $this->git('-c user.email=dogfood@example.com -c user.name=dogfood commit -q -m "frozen"');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function testStructuralReplayPublishesEvidenceThatMatchesItsMap(): void
    {
        $report = $this->workspace . '/reports/demo-structural.json';

        $result = $this->replay(ReplayBackendContract::REQUEST_STRUCTURAL, $this->writeFixture(), $report);

        self::assertSame(0, $result['status'], $result['output']);
        $envelope = $this->envelopeOf($report);
        self::assertSame('structural', $envelope['requested_backend']);
        self::assertSame('simple-php-code-parser+structural-only', $envelope['backend']);
        self::assertSame(0, $envelope['call_relations'], 'a structural map carries no call relations');
    }

    public function testPhpStanReplayPublishesEvidenceThatProvesThePhpStanBackend(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('phpstan/phpstan is not installed; PHPStan evidence cannot be produced here.');
        }

        $report = $this->workspace . '/reports/demo-phpstan.json';

        $result = $this->replay(ReplayBackendContract::REQUEST_PHPSTAN, $this->writeFixture(), $report);

        self::assertSame(0, $result['status'], $result['output']);
        $envelope = $this->envelopeOf($report);
        self::assertSame('phpstan', $envelope['requested_backend']);
        self::assertSame('simple-php-code-parser+phpstan', $envelope['backend']);
        self::assertTrue(ReplayBackendContract::isSatisfiedBy(
            (string) $envelope['requested_backend'],
            (string) $envelope['backend'],
        ));
    }

    public function testFailedRunCannotLeaveAnEarlierReportBehind(): void
    {
        $report = $this->workspace . '/reports/demo-phpstan.json';
        mkdir(dirname($report), 0o775, true);
        file_put_contents($report, '{"observation_envelope":{"requested_backend":"phpstan","backend":"simple-php-code-parser+phpstan"}}');

        // A fixture that no longer matches the checkout: the harness must refuse before measuring.
        $fixture = $this->writeFixture(str_repeat('0', 40));
        $result = $this->replay(ReplayBackendContract::REQUEST_PHPSTAN, $fixture, $report);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Frozen checkout mismatch', $result['output']);
        self::assertFileDoesNotExist($report, 'a failed run must not leave older evidence at its destination');
        self::assertSame([], glob(dirname($report) . '/*.tmp-*') ?: []);
    }

    public function testEarlyFailureAlsoClearsAnEarlierReport(): void
    {
        $report = $this->workspace . '/reports/demo-phpstan.json';
        mkdir(dirname($report), 0o775, true);
        file_put_contents($report, '{"observation_envelope":{"requested_backend":"phpstan","backend":"simple-php-code-parser+phpstan"}}');

        // Fails before the checkout is even inspected: the ordering, not the specific failure, is
        // what keeps a preflight or fixture error from leaving publishable-looking evidence.
        $result = $this->replay(
            ReplayBackendContract::REQUEST_PHPSTAN,
            $this->workspace . '/missing-fixture.json',
            $report,
        );

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Cannot read replay fixture', $result['output']);
        self::assertFileDoesNotExist($report);
    }

    public function testUnknownBackendRequestIsRejected(): void
    {
        $result = $this->replay('semantic', $this->writeFixture(), $this->workspace . '/reports/demo-semantic.json');

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('--backend must be structural or phpstan', $result['output']);
    }

    private function writeFixture(?string $baseCommit = null): string
    {
        $fixture = [
            'schema_version' => '1.0',
            'id' => 'demo',
            'shape' => 'local-method',
            'repository' => 'local://demo',
            'base_commit' => $baseCommit ?? $this->head(),
            'fix_commit' => 'unknown',
            'task' => [
                'source' => 'synthetic',
                'title' => 'Greeter::greet decorates twice',
                'text' => "Greeter::greet decorates twice\n\n`Demo\\Greeter::greet` returns a doubled prefix.",
            ],
            'source_paths' => ['src', 'tests'],
            'verified' => [
                'edit_files' => ['src/Greeter.php'],
                'edit_ranges' => [['file' => 'src/Greeter.php', 'start' => 9, 'end' => 12, 'note' => 'Greeter::greet']],
                'test_files' => ['tests/GreeterTest.php'],
            ],
        ];

        $path = $this->workspace . '/demo.json';
        file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeOf(string $report): array
    {
        self::assertFileExists($report);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $envelope = $decoded['observation_envelope'] ?? null;
        self::assertIsArray($envelope);

        return $envelope;
    }

    /**
     * @return array{status: int, output: string}
     */
    private function replay(string $backend, string $fixture, string $report): array
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../tools/dogfood/navigation-replay.php')
            . ' --replay=' . escapeshellarg($fixture)
            . ' --repo=' . escapeshellarg($this->checkout)
            . ' --artifacts=' . escapeshellarg($this->workspace . '/artifacts/' . $backend)
            . ' --backend=' . escapeshellarg($backend)
            . ' --json=' . escapeshellarg($report)
            . ' 2>&1';

        $output = [];
        $status = 0;
        exec($command, $output, $status);

        return ['status' => $status, 'output' => implode("\n", $output)];
    }

    private function head(): string
    {
        $output = [];
        exec('git -C ' . escapeshellarg($this->checkout) . ' rev-parse HEAD', $output);

        return trim(implode('', $output));
    }

    private function git(string $arguments): void
    {
        $output = [];
        $status = 0;
        exec('git -C ' . escapeshellarg($this->checkout) . ' ' . $arguments . ' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
