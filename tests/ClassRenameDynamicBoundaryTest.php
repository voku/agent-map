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
use voku\AgentMap\Plan\PlanBlindSpot;

final class ClassRenameDynamicBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-class-rename-dynamic-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/OldClass.php', "<?php\nnamespace Demo;\nfinal class OldClass {}\n");
        file_put_contents($this->root . '/src/DynamicConsumer.php', <<<'PHP'
<?php
namespace Client;
final class DynamicConsumer
{
    public function exercise(string $class, object $value, string $property): array
    {
        $created = new $class();
        $matches = $value instanceof $class;
        $called = $class::make();
        $constant = $class::SOME_CONST;
        $staticProperty = $class::${$property};

        return [$created, $matches, $called, $constant, $staticProperty];
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDynamicClassOperationsRequireReviewInsteadOfSafeClaim(): void
    {
        $map = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build(
            root: $this->root,
            paths: ['src'],
            excludes: [],
        );

        $plan = (new ClassRenamePlanner())->plan($map, 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertNotSame([], $plan->edits);
        self::assertSame([], $plan->blockers);
        $dynamic = array_values(array_filter(
            $plan->blindSpots,
            static fn (PlanBlindSpot $blindSpot): bool => $blindSpot->kind === 'dynamic_class_name',
        ));
        self::assertCount(5, $dynamic);
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
