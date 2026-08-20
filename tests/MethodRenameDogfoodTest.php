<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlanner;

/** End-to-end proof that real PHPStan collector output feeds exact rename planning. */
final class MethodRenameDogfoodTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Method rename dogfood requires the optional phpstan/phpstan capability.');
        }

        $this->root = sys_get_temp_dir() . '/agent-map-method-rename-dogfood-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);

        file_put_contents($this->root . '/src/Contract.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace RenameDogfood;

interface Contract
{
    public function oldName(): void;
}
PHP);
        file_put_contents($this->root . '/src/Impl.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace RenameDogfood;

final class Impl implements Contract
{
    public function oldName(): void
    {
    }
}
PHP);
        file_put_contents($this->root . '/src/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace RenameDogfood;

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
        if (isset($this->root)) {
            $this->removeDirectory($this->root);
        }
    }

    public function testRealBuilderProducesSafeExactMethodRenamePlan(): void
    {
        $map = (new AgentMapBuilder())->build(
            root: $this->root,
            paths: ['src'],
            excludes: [],
        );

        self::assertSame('simple-php-code-parser+phpstan', $map->backend);
        self::assertNotSame([], $map->incoming('method:RenameDogfood\\Contract::oldName', 'calls'));
        self::assertNotSame([], $map->incoming('method:RenameDogfood\\Contract::oldName', 'overrides'));

        $plan = (new MethodRenamePlanner())->plan($map, 'RenameDogfood\\Contract::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame(
            ['method:RenameDogfood\\Contract::oldName', 'method:RenameDogfood\\Impl::oldName'],
            $plan->family,
        );
        self::assertCount(3, $plan->edits);
        self::assertSame([], $plan->blindSpots);
        self::assertSame([], $plan->blockers);

        foreach ($plan->edits as $edit) {
            $source = file_get_contents($this->root . '/' . $edit->path);
            self::assertIsString($source);
            self::assertSame($edit->sourceSha256, 'sha256:' . hash('sha256', $source));
            self::assertSame(
                'oldName',
                substr($source, $edit->startFilePos, $edit->endFilePos - $edit->startFilePos + 1),
            );
        }
    }

    /** Removes only the isolated dogfood fixture and PHPStan/structural caches below it. */
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
