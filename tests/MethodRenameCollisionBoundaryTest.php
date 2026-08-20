<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlanner;

/** Guards the rename collision graph against walking through unrelated interface components. */
final class MethodRenameCollisionBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-rename-collision-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);

        $this->write('Contract.php', <<<'PHP'
<?php
namespace CollisionBoundary;
interface Contract
{
    public function oldName(): void;
}
PHP);
        $this->write('Marker.php', <<<'PHP'
<?php
namespace CollisionBoundary;
interface Marker
{
}
PHP);
        $this->write('Impl.php', <<<'PHP'
<?php
namespace CollisionBoundary;
final class Impl implements Contract, Marker
{
    public function oldName(): void
    {
    }
}
PHP);
        $this->write('Unrelated.php', <<<'PHP'
<?php
namespace CollisionBoundary;
final class Unrelated implements Marker
{
    public function renamed(): void
    {
    }
}
PHP);
        $this->write('Caller.php', <<<'PHP'
<?php
namespace CollisionBoundary;
final class Caller
{
    public function run(Contract $service): void
    {
        $service->oldName();
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testUnrelatedInterfaceSiblingDoesNotCreateRenameCollision(): void
    {
        $files = [];
        foreach (['Contract.php', 'Marker.php', 'Impl.php', 'Unrelated.php', 'Caller.php'] as $name) {
            $files[] = $this->file('src/' . $name);
        }

        $map = new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: 'simple-php-code-parser+phpstan',
            files: $files,
            relations: [
                RelationEntry::create(
                    sourceId: 'class:CollisionBoundary\\Impl',
                    kind: 'implements',
                    targetIds: ['interface:CollisionBoundary\\Contract', 'interface:CollisionBoundary\\Marker'],
                    file: 'src/Impl.php',
                    lineStart: 3,
                    lineEnd: 8,
                    resolution: 'structural_only',
                ),
                RelationEntry::create(
                    sourceId: 'class:CollisionBoundary\\Unrelated',
                    kind: 'implements',
                    targetIds: ['interface:CollisionBoundary\\Marker'],
                    file: 'src/Unrelated.php',
                    lineStart: 3,
                    lineEnd: 8,
                    resolution: 'structural_only',
                ),
                RelationEntry::create(
                    sourceId: 'method:CollisionBoundary\\Impl::oldName',
                    kind: 'overrides',
                    targetIds: ['method:CollisionBoundary\\Contract::oldName'],
                    file: 'src/Impl.php',
                    lineStart: 5,
                    lineEnd: 7,
                    resolution: 'phpstan_resolved',
                ),
                RelationEntry::create(
                    sourceId: 'method:CollisionBoundary\\Caller::run',
                    kind: 'calls',
                    targetIds: ['method:CollisionBoundary\\Contract::oldName'],
                    file: 'src/Caller.php',
                    lineStart: 7,
                    lineEnd: 7,
                    resolution: 'phpstan_resolved',
                    receiverType: 'CollisionBoundary\\Contract',
                    resultType: 'void',
                ),
            ],
        );

        $plan = (new MethodRenamePlanner())->plan($map, 'CollisionBoundary\\Contract::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(3, $plan->edits);
    }

    /** Writes one PHP fixture below the isolated source root. */
    private function write(string $name, string $source): void
    {
        file_put_contents($this->root . '/src/' . $name, $source);
    }

    /** Builds one structural file entry with the same parser used by production map builds. */
    private function file(string $path): FileEntry
    {
        $absolute = $this->root . '/' . $path;
        $result = (new SimplePhpParserSymbolExtractor())->extract($absolute);
        self::assertTrue($result->ok, $result->error ?? 'Parser extraction failed.');
        $hash = hash_file('sha256', $absolute);
        self::assertIsString($hash);

        return new FileEntry(
            path: $path,
            sha256: 'sha256:' . $hash,
            namespace: 'CollisionBoundary',
            symbols: $result->symbols,
            semanticStatus: 'analysed',
        );
    }
}
