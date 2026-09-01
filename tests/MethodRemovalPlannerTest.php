<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Removal\MethodRemovalPlan;
use voku\AgentMap\Removal\MethodRemovalPlanner;

/**
 * The unused-private-method behavior is adapted from Rector's
 * rules/DeadCode/Rector/ClassMethod/RemoveUnusedPrivateMethodRector.php. Agent-map emits guarded
 * evidence rather than mutating the AST.
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

    /**
     * Reduced from lib/framework/system/helper/AssocSorter.php in IT-Portal, where
     * the planner published a SAFE deletion for a method the same file hands to
     * uasort as `[&$this, 'compare']`. Applying that edit left source that parses
     * and then fatals at runtime.
     */
    public function testArrayCallableHoldingTheMethodBlocksRemoval(): void
    {
        $source = <<<'PHP'
<?php
final class AssocSorter
{
    private function compare($left, $right): int
    {
        return $left <=> $right;
    }

    public function asort(array &$array): void
    {
        \uasort($array, [&$this, 'compare']);
    }
}
PHP;
        file_put_contents($this->root . '/src/AssocSorter.php', $source);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'AssocSorter::compare');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertNotEmpty(array_filter(
            $plan->blockers,
            static fn (string $blocker): bool => str_contains($blocker, 'callable naming this method'),
        ));
    }

    public function testStringCallableHoldingTheMethodBlocksRemoval(): void
    {
        $source = <<<'PHP'
<?php
final class Handler
{
    private function handle(): void
    {
    }

    public function register(): void
    {
        \register_shutdown_function('Handler::handle');
    }
}
PHP;
        file_put_contents($this->root . '/src/Handler.php', $source);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Handler::handle');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
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
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('Only private methods', implode("\n", $plan->blockers));
    }

    public function testCompactPrivateMethodFailsClosedInsteadOfDeletingSurroundingSource(): void
    {
        file_put_contents(
            $this->root . '/src/Worker.php',
            "<?php\nfinal class Worker { private function obsolete(): void {} public function run(): void {} }\n",
        );
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Worker::obsolete');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('own line', implode("\n", $plan->blockers));
    }

    public function testTrailingSourceOnMethodLineFailsClosed(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    private function obsolete(): void {} // intentionally retained note

    public function run(): void {}
}
PHP);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Worker::obsolete');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('without trailing source', implode("\n", $plan->blockers));
    }

    public function testPrivateMagicMethodFailsClosedWithoutOrdinaryCalls(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    private function __construct()
    {
    }
}
PHP);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Worker::__construct');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('magic methods', implode("\n", $plan->blockers));
    }

    public function testOwnerMagicDispatchFailsClosed(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    private function obsolete(): void
    {
    }

    public function __call(string $name, array $arguments): mixed
    {
        return null;
    }
}
PHP);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Worker::obsolete');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('__call', implode("\n", $plan->blockers));
    }

    public function testPrivateTraitMethodFailsClosedUntilAdaptationsAreObservable(): void
    {
        file_put_contents($this->root . '/src/Reusable.php', <<<'PHP'
<?php
trait Reusable
{
    private function obsolete(): void
    {
    }
}
PHP);
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    use Reusable;

    public function run(): void
    {
    }
}
PHP);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Reusable::obsolete');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('Trait method removal', implode("\n", $plan->blockers));
    }

    public function testClassStringStaticCallFailsClosedWhenCollectorCannotResolveIt(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    private static function obsolete(): void
    {
    }

    public static function run(): void
    {
        self::class::obsolete();
    }
}
PHP);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Worker::obsolete');

        self::assertSame(MethodRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('class-string static call', implode("\n", $plan->blockers));
    }

    public function testUnionTypedDynamicDispatchRequiresReviewButKeepsExactDeletionEvidence(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
final class Worker
{
    private function obsolete(): void
    {
    }

    public function run(): void
    {
    }
}
PHP);
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $relations = $map->relations;
        $relations[] = RelationEntry::create(
            sourceId: 'method:Worker::run',
            kind: 'calls',
            targetIds: ['unresolved:calls'],
            file: 'src/Worker.php',
            lineStart: 9,
            lineEnd: 9,
            resolution: 'dynamic',
            receiverType: 'Worker|null',
        );
        $map = new AgentMapIndex(
            schemaVersion: $map->schemaVersion,
            root: $map->root,
            backend: $map->backend,
            files: $map->files,
            relations: $relations,
            diagnostics: $map->diagnostics,
            fingerprint: $map->fingerprint,
        );

        $plan = (new MethodRemovalPlanner())->plan($map, 'Worker::obsolete');

        self::assertSame(MethodRemovalPlan::STATUS_REVIEW_REQUIRED, $plan->status);
        self::assertCount(1, $plan->edits);
        self::assertSame('dynamic_method_name', $plan->blindSpots[0]->kind);
    }

    public function testMethodAttributesRequireReviewAndAreIncludedInDeletionEvidence(): void
    {
        file_put_contents($this->root . '/src/Worker.php', <<<'PHP'
<?php
#[Attribute(Attribute::TARGET_METHOD)]
final class Hook
{
}

final class Worker
{
    #[Hook]
    private function obsolete(): void
    {
    }
}
PHP);
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Worker::obsolete');

        self::assertSame(MethodRemovalPlan::STATUS_REVIEW_REQUIRED, $plan->status);
        self::assertCount(1, $plan->edits);
        self::assertStringContainsString('#[Hook]', $plan->edits[0]->expected);
        self::assertSame('method_attributes', $plan->blindSpots[0]->kind);
    }
}
