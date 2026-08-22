<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\RelationEntry;

final class PhpStanPropertyEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-property-evidence-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Box.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Box
{
    private string $name = 'initial';
    private static int $count = 0;

    public function exercise(?Box $other): ?string
    {
        $this->name = 'changed';
        $copy = $other?->name;
        self::$count++;
        $field = 'name';
        $dynamic = $this->{$field};

        return $copy ?? $dynamic ?? $this->name;
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPhpStanPublishesPropertyDeclarationsAndResolvedAccesses(): void
    {
        $index = (new AgentMapBuilder())->build($this->root, ['src'], [], null, '512M');

        self::assertSame('simple-php-code-parser+phpstan', $index->backend);
        $declarations = array_values(array_filter(
            $index->relations,
            static fn (RelationEntry $relation): bool => $relation->kind === 'declares_property',
        ));
        self::assertCount(1, $declarations);
        self::assertSame([
            'property:Demo\\Box::$count',
            'property:Demo\\Box::$name',
        ], $declarations[0]->targetIds);
        self::assertSame('phpstan_resolved', $declarations[0]->resolution);

        $accesses = array_values(array_filter(
            $index->relations,
            static fn (RelationEntry $relation): bool => $relation->kind === 'property_access',
        ));
        self::assertGreaterThanOrEqual(5, count($accesses));
        self::assertNotEmpty(array_filter(
            $accesses,
            static fn (RelationEntry $relation): bool => in_array('property:Demo\\Box::$name', $relation->targetIds, true)
                && $relation->resolution === 'phpstan_resolved',
        ));
        self::assertNotEmpty(array_filter(
            $accesses,
            static fn (RelationEntry $relation): bool => in_array('property:Demo\\Box::$count', $relation->targetIds, true)
                && $relation->resolution === 'phpstan_resolved',
        ));
        self::assertNotEmpty(array_filter(
            $accesses,
            static fn (RelationEntry $relation): bool => $relation->targetIds === ['unresolved:property_access']
                && $relation->resolution === 'dynamic',
        ));
    }

    public function testStructuralOnlyMapCannotClaimPropertySemanticEvidence(): void
    {
        $index = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src'], []);

        self::assertSame('simple-php-code-parser+structural-only', $index->backend);
        self::assertSame([], array_values(array_filter(
            $index->relations,
            static fn (RelationEntry $relation): bool => in_array($relation->kind, ['declares_property', 'property_access'], true),
        )));
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
