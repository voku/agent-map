<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Rename\ClassConstantRenamePlan;
use voku\AgentMap\Rename\ClassConstantRenamePlanner;
use voku\AgentMap\Rename\RenameEdit;

/** Behavioral coverage adapted from Rector's RenameClassConstFetch rule. */
final class ClassConstantRenamePlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-class-constant-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPlansDeclarationImportedFullyQualifiedAndSelfFetches(): void
    {
        file_put_contents($this->root . '/src/Settings.php', <<<'PHP'
<?php
namespace Demo;
final class Settings
{
    public const OLD_NAME = 'value';
    public function local(): string { return self::OLD_NAME; }
}
PHP);
        file_put_contents($this->root . '/src/Consumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\Settings;
$one = Settings::OLD_NAME;
$two = \Demo\Settings::OLD_NAME;
PHP);

        $plan = (new ClassConstantRenamePlanner())->plan($this->map(), 'Demo\Settings::OLD_NAME', 'NEW_NAME');

        self::assertSame(ClassConstantRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        $roles = array_values(array_unique(array_map(static fn (RenameEdit $edit): string => $edit->role, $plan->edits)));
        sort($roles);
        self::assertSame(['class_constant_declaration', 'class_constant_fetch'], $roles);
        self::assertCount(4, $plan->edits);
    }

    public function testCollisionBlocksAndDynamicNameRequiresReview(): void
    {
        file_put_contents($this->root . '/src/Settings.php', <<<'PHP'
<?php
namespace Demo;
final class Settings { public const OLD_NAME = 1; public const NEW_NAME = 2; }
PHP);
        $blocked = (new ClassConstantRenamePlanner())->plan($this->map(), 'Demo\Settings::OLD_NAME', 'NEW_NAME');
        self::assertSame(ClassConstantRenamePlan::STATUS_BLOCKED, $blocked->status);
        self::assertSame([], $blocked->edits);

        file_put_contents($this->root . '/src/Settings.php', <<<'PHP'
<?php
namespace Demo;
final class Settings { public const OLD_NAME = 1; }
$name = 'OLD_NAME';
$value = Settings::{$name};
PHP);
        $review = (new ClassConstantRenamePlanner())->plan($this->map(), 'Demo\Settings::OLD_NAME', 'NEW_NAME');
        self::assertSame(ClassConstantRenamePlan::STATUS_REVIEW_REQUIRED, $review->status, implode("\n", $review->blockers));
        self::assertSame('dynamic_class_constant_name', $review->blindSpots[0]->kind);
    }

    private function map(): \voku\AgentMap\Index\AgentMapIndex
    {
        return (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src'], []);
    }
}
