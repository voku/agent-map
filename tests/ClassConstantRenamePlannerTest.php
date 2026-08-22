<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Rename\ClassConstantRenamePlan;
use voku\AgentMap\Rename\ClassConstantRenamePlanner;
use voku\AgentMap\Rename\RenameBlindSpot;
use voku\AgentMap\Rename\RenameEdit;

/** Behavioral coverage adapted from Rector's RenameClassConstFetch rule. */
final class ClassConstantRenamePlannerTest extends TestCase
{
    private string $root;

    /** Creates an isolated structural-map fixture. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-class-constant-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    /** Removes every generated fixture path. */
    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** Proves exact declaration, import, FQN and owner-local self fetch edits. */
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

    /** Proves exact replacement collisions block and dynamic names require review. */
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

    /** Proves class-constant collision checks preserve PHP's case-sensitive identifier semantics. */
    public function testCaseDistinctReplacementCollisionBlocks(): void
    {
        file_put_contents($this->root . '/src/Settings.php', <<<'PHP'
<?php
namespace Demo;
final class Settings
{
    public const OLD_NAME = 1;
    public const old_name = 2;
}
PHP);

        $plan = (new ClassConstantRenamePlanner())->plan($this->map(), 'Demo\Settings::OLD_NAME', 'old_name');

        self::assertSame(ClassConstantRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertContains('Replacement class constant Demo\Settings::old_name already exists.', $plan->blockers);
    }

    /** Proves late-static and inherited declarations are never presented as exact-owner edits. */
    public function testLateStaticAndInheritedOwnerEvidenceRequireReview(): void
    {
        file_put_contents($this->root . '/src/Settings.php', <<<'PHP'
<?php
namespace Demo;
class Settings
{
    public const OLD_NAME = 'parent';

    public function lateBound(): string
    {
        return static::OLD_NAME;
    }
}

final class ChildSettings extends Settings
{
    public function inherited(): string
    {
        return self::OLD_NAME;
    }
}
PHP);
        file_put_contents($this->root . '/src/Consumer.php', <<<'PHP'
<?php
namespace Demo;
$value = ChildSettings::OLD_NAME;
PHP);

        $plan = (new ClassConstantRenamePlanner())->plan($this->map(), 'Demo\Settings::OLD_NAME', 'NEW_NAME');

        self::assertSame(ClassConstantRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->edits);
        self::assertSame('class_constant_declaration', $plan->edits[0]->role);
        $kinds = array_map(static fn (RenameBlindSpot $blindSpot): string => $blindSpot->kind, $plan->blindSpots);
        self::assertContains('late_static_class_constant_fetch', $kinds);
        self::assertContains('unproven_class_constant_owner', $kinds);
    }

    /** Proves any indexed-source drift blocks the plan and suppresses edits. */
    public function testStaleSourceBlocksAndPublishesNoEdits(): void
    {
        $path = $this->root . '/src/Settings.php';
        file_put_contents($path, <<<'PHP'
<?php
namespace Demo;
final class Settings { public const OLD_NAME = 1; }
PHP);
        $map = $this->map();
        file_put_contents($path, <<<'PHP'
<?php
namespace Demo;
final class Settings { public const OLD_NAME = 2; }
PHP);

        $plan = (new ClassConstantRenamePlanner())->plan($map, 'Demo\Settings::OLD_NAME', 'NEW_NAME');

        self::assertSame(ClassConstantRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertNotSame([], $plan->staleEvidence);
    }

    /** Proves malformed targets and replacements are rejected before planning. */
    public function testRejectsInvalidTargetAndReplacementNames(): void
    {
        file_put_contents($this->root . '/src/Settings.php', <<<'PHP'
<?php
namespace Demo;
final class Settings { public const OLD_NAME = 1; }
PHP);
        $planner = new ClassConstantRenamePlanner();
        $map = $this->map();

        try {
            $planner->plan($map, 'Demo\Settings', 'NEW_NAME');
            self::fail('Missing class-constant separator should be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Class constant target must use ClassName::CONSTANT syntax.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $planner->plan($map, 'Demo\Settings::OLD_NAME', 'not-valid!');
    }

    /** Builds the current structural map from fixture sources. */
    private function map(): AgentMapIndex
    {
        return (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src'], []);
    }
}
