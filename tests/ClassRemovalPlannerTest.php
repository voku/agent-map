<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Removal\ClassRemovalPlan;
use voku\AgentMap\Removal\ClassRemovalPlanner;

final class ClassRemovalPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Class removal tests require PHPStan.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-class-removal-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
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

    public function testPlansWholeOwnedFileDeletionWithoutChangingSource(): void
    {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo\Legacy;

final class Obsolete
{
    public function value(): string
    {
        return 'old';
    }
}
PHP;
        file_put_contents($this->root . '/src/Obsolete.php', $source);

        $plan = $this->plan('Demo\Legacy\Obsolete');

        self::assertSame(ClassRemovalPlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame([], $plan->edits);
        self::assertCount(1, $plan->deletions);
        self::assertSame('src/Obsolete.php', $plan->deletions[0]->path);
        self::assertSame('sha256:' . hash('sha256', $source), $plan->deletions[0]->sourceSha256);
        self::assertFileExists($this->root . '/src/Obsolete.php');
        self::assertSame($source, file_get_contents($this->root . '/src/Obsolete.php'));
    }

    public function testIncomingTypeUsageBlocksRemoval(): void
    {
        file_put_contents($this->root . '/src/Obsolete.php', <<<'PHP'
<?php
namespace Demo;
final class Obsolete {}
PHP);
        file_put_contents($this->root . '/src/Consumer.php', <<<'PHP'
<?php
namespace Demo;
final class Consumer
{
    public function make(): Obsolete
    {
        return new Obsolete();
    }
}
PHP);

        $plan = $this->plan('Demo\Obsolete');

        self::assertSame(ClassRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->deletions);
        self::assertNotEmpty(array_filter(
            $plan->blockers,
            static fn (string $blocker): bool => str_contains($blocker, 'incoming'),
        ));
    }

    public function testAdditionalExecutableNamespaceStatementBlocksOwnedFileDeletion(): void
    {
        file_put_contents($this->root . '/src/Obsolete.php', <<<'PHP'
<?php
namespace Demo;

register_shutdown_function(static function (): void {});

final class Obsolete {}
PHP);

        $plan = $this->plan('Demo\Obsolete');

        self::assertSame(ClassRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->deletions);
        self::assertNotEmpty(array_filter(
            $plan->blockers,
            static fn (string $blocker): bool => str_contains($blocker, 'another declaration or executable statement'),
        ));
    }

    public function testClassPhpDocRequiresReviewButKeepsExactDeletionEvidence(): void
    {
        file_put_contents($this->root . '/src/Obsolete.php', <<<'PHP'
<?php
namespace Demo;

/** @Entity */
final class Obsolete {}
PHP);

        $plan = $this->plan('Demo\\Obsolete');

        self::assertSame(ClassRemovalPlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->deletions);
        self::assertContains('class_phpdoc', array_map(static fn ($spot): string => $spot->kind, $plan->blindSpots));
    }

    public function testClassAttributeRequiresReviewButKeepsExactDeletionEvidence(): void
    {
        file_put_contents($this->root . '/src/Obsolete.php', <<<'PHP'
<?php
namespace Demo;

#[\AllowDynamicProperties]
final class Obsolete {}
PHP);

        $plan = $this->plan('Demo\Obsolete');

        self::assertSame(ClassRemovalPlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->deletions);
        self::assertContains('class_attributes', array_map(static fn ($spot): string => $spot->kind, $plan->blindSpots));
    }

    public function testExactClassStringRequiresReviewInsteadOfPretendingUnused(): void
    {
        file_put_contents($this->root . '/src/Obsolete.php', <<<'PHP'
<?php
namespace Demo;
final class Obsolete {}
PHP);
        file_put_contents($this->root . '/src/Registry.php', <<<'PHP'
<?php
namespace Demo;
final class Registry
{
    public const LEGACY = 'Demo\\Obsolete';
}
PHP);

        $plan = $this->plan('Demo\Obsolete');

        self::assertSame(ClassRemovalPlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->deletions);
        self::assertContains('class_string_literal', array_map(static fn ($spot): string => $spot->kind, $plan->blindSpots));
    }

    public function testMultiSymbolFileBlocksWholeFileDeletion(): void
    {
        file_put_contents($this->root . '/src/Legacy.php', <<<'PHP'
<?php
namespace Demo;
final class Obsolete {}
final class StillUsed {}
PHP);

        $plan = $this->plan('Demo\Obsolete');

        self::assertSame(ClassRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->deletions);
        self::assertNotEmpty(array_filter(
            $plan->blockers,
            static fn (string $blocker): bool => str_contains($blocker, 'only indexed symbol'),
        ));
    }

    private function plan(string $target): ClassRemovalPlan
    {
        return (new ClassRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            $target,
        );
    }
}
