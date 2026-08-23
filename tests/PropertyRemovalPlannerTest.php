<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Removal\PropertyRemovalPlan;
use voku\AgentMap\Removal\PropertyRemovalPlanner;

/**
 * Safety inventory adapted from Rector's
 * rules/DeadCode/Rector/Property/RemoveUnusedPrivatePropertyRector.php at
 * rectorphp/rector-src@cbeefaa869f3c5a8721af602b887c242b18741fd.
 *
 * Copyright (c) 2017-present Tomáš Votruba. Licensed under the MIT License; see THIRD_PARTY_NOTICES.md.
 */
final class PropertyRemovalPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Property removal tests require PHPStan.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-property-removal-' . bin2hex(random_bytes(6));
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

    public function testPlansExactWholePropertyDeletionWithoutChangingSource(): void
    {
        $source = <<<'PHP'
<?php
final class Worker
{
    private string $obsolete = 'old';

    public function run(): void
    {
    }
}
PHP;
        file_put_contents($this->root . '/src/Worker.php', $source);

        $plan = (new PropertyRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::$obsolete',
        );

        self::assertSame(PropertyRemovalPlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->edits);
        self::assertStringContainsString("private string \$obsolete = 'old';", $plan->edits[0]->expected);
        self::assertSame('', $plan->edits[0]->replacement);
        self::assertSame('property_declaration_removal', $plan->edits[0]->role);
        self::assertSame($source, file_get_contents($this->root . '/src/Worker.php'));
    }

    public function testAnyObservedAccessBlocksInsteadOfAssumingWriteOnlySafety(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    private string $obsolete = '';

    public function run(): void
    {
        $this->obsolete = 'assigned';
    }
}
PHP);

        $plan = (new PropertyRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::$obsolete',
        );

        self::assertSame(PropertyRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('zero observed semantic accesses', implode("\n", $plan->blockers));
        self::assertStringContainsString('Write-only property elimination', implode("\n", $plan->notObservable));
    }

    public function testPublicPropertyFailsClosed(): void
    {
        file_put_contents($this->root . '/src/Worker.php', "<?php\nfinal class Worker\n{\n    public string \$obsolete = '';\n}\n");

        $plan = (new PropertyRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::$obsolete',
        );

        self::assertSame(PropertyRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('Only private properties', implode("\n", $plan->blockers));
    }

    public function testStaticPropertyFailsClosed(): void
    {
        file_put_contents($this->root . '/src/Worker.php', "<?php\nfinal class Worker\n{\n    private static string \$obsolete = '';\n}\n");

        $plan = (new PropertyRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::$obsolete',
        );

        self::assertSame(PropertyRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('Static property removal', implode("\n", $plan->blockers));
    }

    public function testTraitUsingOwnerFailsClosed(): void
    {
        file_put_contents($this->root . '/src/Reusable.php', "<?php\ntrait Reusable\n{\n}\n");
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    use Reusable;

    private string $obsolete = '';
}
PHP);

        $plan = (new PropertyRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::$obsolete',
        );

        self::assertSame(PropertyRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('Classes using traits', implode("\n", $plan->blockers));
    }

    public function testDoctrineLoadMetadataOwnerFailsClosed(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    private string $obsolete = '';

    public static function loadMetadata(object $metadata): void
    {
    }
}
PHP);

        $plan = (new PropertyRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::$obsolete',
        );

        self::assertSame(PropertyRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('loadMetadata()', implode("\n", $plan->blockers));
    }

    public function testPropertyAttributesRequireReviewAndRemainInExactDeletionEvidence(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Marker
{
}

final class Worker
{
    #[Marker]
    private string $obsolete = '';
}
PHP);

        $plan = (new PropertyRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::$obsolete',
        );

        self::assertSame(PropertyRemovalPlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->edits);
        self::assertStringContainsString('#[Marker]', $plan->edits[0]->expected);
        self::assertSame('property_attributes', $plan->blindSpots[0]->kind);
    }

    public function testPropertyPhpDocRequiresReview(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    /** @var non-empty-string */
    private string $obsolete = 'old';
}
PHP);

        $plan = (new PropertyRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::$obsolete',
        );

        self::assertSame(PropertyRemovalPlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->edits);
        self::assertStringContainsString('@var non-empty-string', $plan->edits[0]->expected);
        self::assertSame('property_phpdoc', $plan->blindSpots[0]->kind);
    }

    public function testCompactPropertyFailsClosedInsteadOfDeletingNeighboringSource(): void
    {
        file_put_contents(
            $this->root . '/src/Worker.php',
            "<?php\nfinal class Worker { private string \$obsolete = ''; public function run(): void {} }\n",
        );

        $plan = (new PropertyRemovalPlanner())->plan(
            (new AgentMapBuilder())->build($this->root, ['src'], []),
            'Worker::$obsolete',
        );

        self::assertSame(PropertyRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('own line', implode("\n", $plan->blockers));
    }
}
