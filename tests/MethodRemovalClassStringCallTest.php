<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Removal\MethodRemovalPlan;
use voku\AgentMap\Removal\MethodRemovalPlanner;

final class MethodRemovalClassStringCallTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Method removal class-string tests require PHPStan.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-method-removal-class-string-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
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

    public function testClassStringStaticCallInComposedTraitFailsClosedAcrossFiles(): void
    {
        file_put_contents($this->root . '/src/Reusable.php', <<<'PHP'
<?php
trait Reusable
{
    public static function invokeObsolete(): void
    {
        self::class::obsolete();
    }
}
PHP);
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    use Reusable;

    private static function obsolete(): void
    {
    }
}
PHP);

        $plan = (new MethodRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::obsolete',
        );

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('src/Reusable.php', implode("\n", $plan->blockers));
        self::assertStringContainsString('class-string static call', implode("\n", $plan->blockers));
    }
}
