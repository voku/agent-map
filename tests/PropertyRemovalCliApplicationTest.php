<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Cli\PropertyRemovalCliApplication;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\MapArtifactPaths;

final class PropertyRemovalCliApplicationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Property removal CLI tests require PHPStan.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-property-removal-cli-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    private string $obsolete = 'old';

    public function run(): void
    {
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testExplicitRelativeIndexProducesVersionedSafePlanWithoutMutation(): void
    {
        $this->writeIndex('custom/map.json');
        $before = (string) file_get_contents($this->root . '/src/Worker.php');
        $application = new PropertyRemovalCliApplication(MapArtifactPaths::forProject($this->root));

        ob_start();
        $exit = $application->run([
            'agent-map',
            'property-removal-plan',
            'Worker::$obsolete',
            '--index',
            'custom/map.json',
            '--format',
            'json',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        self::assertJson($output);
        $plan = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        self::assertSame('property_removal_plan', $plan['type']);
        self::assertSame('1.0', $plan['contract_version']);
        self::assertSame('safe', $plan['status']);
        self::assertSame('property:Worker::$obsolete', $plan['target_id']);
        self::assertCount(1, $plan['edits']);
        self::assertSame('', $plan['edits'][0]['replacement']);
        self::assertSame($before, file_get_contents($this->root . '/src/Worker.php'));
    }

    public function testTextOutputExplainsStaleBlockingAndWriteOnlyBoundary(): void
    {
        $this->writeIndex('custom/map.json');
        file_put_contents($this->root . '/src/Worker.php', "\n// changed after indexing\n", FILE_APPEND);
        $application = new PropertyRemovalCliApplication(MapArtifactPaths::forProject($this->root));

        ob_start();
        $exit = $application->run([
            'agent-map',
            'property-removal-plan',
            'Worker::$obsolete',
            '--index=custom/map.json',
            '--format=text',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(1, $exit);
        self::assertStringContainsString('Property removal plan: BLOCKED', $output);
        self::assertStringContainsString('STALE: src/Worker.php', $output);
        self::assertStringContainsString('Write-only property elimination', $output);
    }

    private function writeIndex(string $relativePath): void
    {
        $index = (new AgentMapBuilder())->build($this->root, ['src'], []);
        (new IndexWriter())->write($index, $this->root . '/' . $relativePath);
    }
}
