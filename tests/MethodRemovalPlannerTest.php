<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Removal\MethodRemovalPlan;
use voku\AgentMap\Removal\MethodRemovalPlanner;

/**
 * The configured-removal behavior is adapted from Rector's
 * rules/Removing/Rector/ClassMethod/ArgumentRemoverRector.php. Agent-map emits guarded evidence
 * rather than mutating the AST.
 *
 * Copyright (c) 2017-present Tomáš Votruba. Licensed under the MIT License; see THIRD_PARTY_NOTICES.md.
 */
final class MethodRemovalPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Method removal tests require PHPStan.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-method-removal-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPlansExactWholeMethodDeletionWithoutChangingSource(): void
    {
        $source = <<<'PHP'
<?php
final class Worker
{
    /** No longer needed. */
    private function obsolete(): void
    {
        echo 'old';
    }

    public function run(): void
    {
    }
}
PHP;
        file_put_contents($this->root . '/src/Worker.php', $source);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Worker::obsolete');

        self::assertSame(MethodRemovalPlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->edits);
        self::assertStringContainsString('/** No longer needed. */', $plan->edits[0]->expected);
        self::assertStringContainsString('private function obsolete()', $plan->edits[0]->expected);
        self::assertSame('', $plan->edits[0]->replacement);
        self::assertSame($source, file_get_contents($this->root . '/src/Worker.php'));
    }

    public function testObservedCallBlocksAndPublishesNoDeletion(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    private function used(): void {}
    public function run(): void { $this->used(); }
}
PHP);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Worker::used');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('Method is called', implode("\n", $plan->blockers));
    }

    public function testPublicMethodFailsClosed(): void
    {
        file_put_contents($this->root . '/src/Worker.php', "<?php\nfinal class Worker { public function api(): void {} }\n");
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Worker::api');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertStringContainsString('Only private methods', implode("\n", $plan->blockers));
    }
}
