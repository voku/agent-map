<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Removal\ClassConstantRemovalPlan;
use voku\AgentMap\Removal\ClassConstantRemovalPlanner;

/**
 * Core safety cases adapted from Rector's RemoveUnusedPrivateClassConstantRector at
 * rectorphp/rector-src@cd3ec48e1209436d03d9c67d47c51ac4972a20cc.
 *
 * Copyright (c) 2017-present Tomáš Votruba. MIT licensed; see docs/reference/third-party-notices.md.
 */
final class ClassConstantRemovalPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Class constant removal tests require PHPStan.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-class-constant-removal-' . bin2hex(random_bytes(6));
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

    public function testPlansWholeDeclarationWithoutChangingSource(): void
    {
        $source = "<?php\nfinal class Worker\n{\n    private const OBSOLETE = 'old';\n}\n";
        file_put_contents($this->root . '/src/Worker.php', $source);

        $plan = $this->plan('Worker::OBSOLETE');

        self::assertSame(ClassConstantRemovalPlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->edits);
        self::assertSame("    private const OBSOLETE = 'old';\n", $plan->edits[0]->expected);
        self::assertSame('class_constant_declaration_removal', $plan->edits[0]->role);
        self::assertSame($source, file_get_contents($this->root . '/src/Worker.php'));
    }

    public function testObservedFetchBlocksRemoval(): void
    {
        file_put_contents($this->root . '/src/Worker.php', "<?php\nfinal class Worker\n{\n    private const OBSOLETE = 'old';\n    public function value(): string { return self::OBSOLETE; }\n}\n");

        $plan = $this->plan('Worker::OBSOLETE');

        self::assertSame(ClassConstantRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('is fetched', implode("\n", $plan->blockers));
    }

    #[DataProvider('unsafeDeclarations')]
    public function testUnsafeDeclarationsFailClosed(string $declaration, string $expected): void
    {
        file_put_contents($this->root . '/src/Worker.php', "<?php\nfinal class Worker\n{\n    {$declaration}\n}\n");

        $plan = $this->plan('Worker::OBSOLETE');

        self::assertSame(ClassConstantRemovalPlan::STATUS_BLOCKED, $plan->status);
        self::assertStringContainsString($expected, implode("\n", $plan->blockers));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeDeclarations(): iterable
    {
        yield 'public' => ["public const OBSOLETE = 'old';", 'Only private'];
        yield 'multiple' => ["private const OBSOLETE = 'old', KEEP = 'new';", 'Multi-constant'];
    }

    public function testMetadataIsIncludedAndRequiresReview(): void
    {
        file_put_contents($this->root . '/src/Worker.php', "<?php\nfinal class Worker\n{\n    /** @deprecated */\n    private const OBSOLETE = 'old';\n}\n");

        $plan = $this->plan('Worker::OBSOLETE');

        self::assertSame(ClassConstantRemovalPlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertStringContainsString('/** @deprecated */', $plan->edits[0]->expected);
        self::assertSame('class_constant_phpdoc', $plan->blindSpots[0]->kind);
    }

    private function plan(string $target): ClassConstantRemovalPlan
    {
        return (new ClassConstantRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), $target);
    }
}
