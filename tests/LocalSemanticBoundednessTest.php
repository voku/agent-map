<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Inspect\LocalBindingCheckpoint;
use voku\AgentMap\Inspect\LocalExitCheckpoint;
use voku\AgentMap\Inspect\LocalSemanticFrameBuilder;
use voku\AgentMap\Inspect\ScopeSelector;

final class LocalSemanticBoundednessTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/agent-map-local-semantic-bounds-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . '/src', 0o775, true);

        file_put_contents($this->fixtureRoot . '/src/RepeatedReads.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class RepeatedReads
        {
            public function run(): bool
            {
                $value = true;
                $value;
                $value;
                $value;
                $value;
                $value;
                $value;
                $value;
                $value;
                $value;
                $value;

                return $value;
            }
        }
        PHP);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->fixtureRoot)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->fixtureRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->fixtureRoot);
    }

    public function testRepeatedTrivialReadsDoNotExpandTheLocalSemanticFrame(): void
    {
        $index = (new AgentMapBuilder())->build($this->fixtureRoot, ['src'], []);
        $selection = (new ScopeSelector())->select($index, 'Demo\\RepeatedReads::run');

        self::assertNotNull($selection->target);
        $frame = (new LocalSemanticFrameBuilder())->build($index, $selection->target);

        self::assertCount(2, $frame->checkpoints);
        self::assertInstanceOf(LocalBindingCheckpoint::class, $frame->checkpoints[0]);
        self::assertSame('$value', $frame->checkpoints[0]->variable);
        self::assertInstanceOf(LocalExitCheckpoint::class, $frame->checkpoints[1]);
        self::assertSame('$value', $frame->checkpoints[1]->variable);
    }

    public function testRepeatedUnchangedBuildPreservesLocalSemanticRecords(): void
    {
        $builder = new AgentMapBuilder();
        $cold = $builder->build($this->fixtureRoot, ['src'], []);
        $warm = $builder->build($this->fixtureRoot, ['src'], []);

        $coldData = $cold->toArray();
        $warmData = $warm->toArray();

        self::assertSame($coldData['local_bindings'], $warmData['local_bindings']);
        self::assertSame($coldData['local_exits'], $warmData['local_exits']);
        self::assertCount(1, $warmData['local_bindings']);
        self::assertCount(1, $warmData['local_exits']);
    }
}
