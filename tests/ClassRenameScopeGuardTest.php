<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Rename\ClassRenamePlan;
use voku\AgentMap\Rename\ClassRenamePlanner;

final class ClassRenameScopeGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-class-rename-scope-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/OldClass.php', "<?php\nnamespace Demo;\nfinal class OldClass {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExistingImportAliasForReplacementShortNameBlocks(): void
    {
        file_put_contents($this->root . '/src/Consumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\OldClass;
use Other\Thing as NewClass;
final class Consumer
{
    public function make(): OldClass
    {
        return new OldClass();
    }
}
PHP);

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('collides with an existing class import or declaration in src/Consumer.php', implode("\n", $plan->blockers));
    }

    public function testUnusedUnaliasedTargetImportStillBlocksReplacementBindingCollision(): void
    {
        file_put_contents($this->root . '/src/Consumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\OldClass;
use Other\NewClass;
final class Consumer {}
PHP);

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('collides with an existing class import or declaration in src/Consumer.php', implode("\n", $plan->blockers));
    }

    public function testExistingLocalClassWithReplacementShortNameBlocksImportedTargetRename(): void
    {
        file_put_contents($this->root . '/src/Consumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\OldClass;
final class NewClass {}
final class Consumer
{
    public function make(): OldClass
    {
        return new OldClass();
    }
}
PHP);

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('src/Consumer.php', implode("\n", $plan->blockers));
    }

    public function testExplicitAliasKeepsReplacementShortNameOutOfLocalScope(): void
    {
        file_put_contents($this->root . '/src/Consumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\OldClass as Service;
use Other\Thing as NewClass;
final class Consumer
{
    public function make(): Service
    {
        return new Service();
    }
}
PHP);

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(2, $plan->edits);
        self::assertSame([], $plan->blockers);
    }

    private function map(): \voku\AgentMap\Index\AgentMapIndex
    {
        return (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build(
            root: $this->root,
            paths: ['src'],
            excludes: [],
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
