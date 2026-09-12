<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\CliApplication;
use voku\AgentMap\Discovery\TestDiscovery;
use voku\AgentMap\Index\AgentMapBuilder;

final class TestDiscoveryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/agent-map-test-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/src', 0o775, true);
        mkdir($this->tempDir . '/tests', 0o775, true);

        file_put_contents($this->tempDir . '/src/OrderService.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class OrderService
        {
            public function process(): void
            {
            }

            public function cancel(): void
            {
            }
        }
        PHP);

        file_put_contents($this->tempDir . '/tests/OrderServiceTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Tests;

        use Demo\OrderService;

        final class OrderServiceTest
        {
            public function testProcessWorks(): void
            {
                $service = new OrderService();
                $service->process();
            }
        }
        PHP);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    public function testDiscoverByExactMethod(): void
    {
        $builder = new AgentMapBuilder();
        $map = $builder->build($this->tempDir, ['src', 'tests'], []);

        $discovery = new TestDiscovery();
        $report = $discovery->discover($map, 'Demo\OrderService::process');

        self::assertSame('symbol', $report->targetKind);
        self::assertCount(1, $report->targets);
        self::assertSame('Demo\OrderService::process', $report->targets[0]['symbol']);

        // Check direct callers
        self::assertNotEmpty($report->testCalls);
        self::assertSame('Demo\Tests\OrderServiceTest::testProcessWorks', $report->testCalls[0]['test_symbol']);
        self::assertSame('tests/OrderServiceTest.php', $report->testCalls[0]['test_file']);

        // Check companion test files
        self::assertNotEmpty($report->companionTestFiles);
        self::assertSame('tests/OrderServiceTest.php', $report->companionTestFiles[0]['path']);

        // Check suggested commands
        self::assertNotEmpty($report->suggestedTestCommands);
        self::assertStringContainsString('phpunit', $report->suggestedTestCommands[0]);

        $arr = $report->toArray();
        self::assertTrue($arr['tested']);
        self::assertSame(1, $arr['direct_test_count']);
    }

    public function testDiscoverByClass(): void
    {
        $builder = new AgentMapBuilder();
        $map = $builder->build($this->tempDir, ['src', 'tests'], []);

        $discovery = new TestDiscovery();
        $report = $discovery->discover($map, 'Demo\OrderService');

        self::assertSame('symbol', $report->targetKind);
        self::assertNotEmpty($report->targets);
        self::assertNotEmpty($report->testCalls);
        self::assertNotEmpty($report->companionTestFiles);
    }

    public function testDiscoverByFilePath(): void
    {
        $builder = new AgentMapBuilder();
        $map = $builder->build($this->tempDir, ['src', 'tests'], []);

        $discovery = new TestDiscovery();
        $report = $discovery->discover($map, 'src/OrderService.php');

        self::assertSame('file', $report->targetKind);
        self::assertNotEmpty($report->targets);
        self::assertNotEmpty($report->companionTestFiles);
    }

    public function testCliRoutingAndFormats(): void
    {
        $builder = new AgentMapBuilder();
        $indexFile = $this->tempDir . '/index.json';
        $map = $builder->build($this->tempDir, ['src', 'tests'], []);
        file_put_contents($indexFile, json_encode($map->toArray(), JSON_THROW_ON_ERROR));

        $cli = new CliApplication($this->tempDir);

        // Test text format
        ob_start();
        $status = $cli->run(['agent-map', 'tests', 'Demo\OrderService::process', '--index=' . $indexFile]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $status);
        self::assertStringContainsString('Test Discovery: Demo\OrderService::process', $output);
        self::assertStringContainsString('Direct Test Callers:', $output);
        self::assertStringContainsString('Demo\Tests\OrderServiceTest::testProcessWorks', $output);

        // Test markdown format
        ob_start();
        $status = $cli->run(['agent-map', 'tests', 'Demo\OrderService::process', '--index=' . $indexFile, '--format=markdown']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $status);
        self::assertStringContainsString('# Test Discovery: `Demo\OrderService::process`', $output);
        self::assertStringContainsString('## Direct Test Callers', $output);

        // Test json format
        ob_start();
        $status = $cli->run(['agent-map', 'tests', 'Demo\OrderService::process', '--index=' . $indexFile, '--format=json']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $status);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['tested']);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->deleteDirectory($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
