<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Plan\PlanStatus;
use voku\AgentMap\Scaffold\MethodCopyPlanner;

final class MethodCopyPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-method-copy-' . bin2hex(random_bytes(6));
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

    public function testPlansSafeMethodCopy(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Source
{
    /**
     * Compute square.
     */
    public static function square(int $x): int
    {
        return $x * $x;
    }
}
PHP);

        $this->write('Dest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Dest
{
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodCopyPlanner($this->root);

        $plan = $planner->plan(
            map: $map,
            sourceTarget: 'App\Source::square',
            destinationClass: 'App\Dest',
            newMethodName: 'computeSquare',
            visibility: 'public',
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('App\Dest', $plan->destinationFqn);
        self::assertSame('computeSquare', $plan->newMethodName);
        self::assertSame([], $plan->ownerDependencies);
        self::assertCount(1, $plan->edits);

        $edit = $plan->edits[0];
        self::assertSame('method_declaration_insertion', $edit->role);
        self::assertStringContainsString('function computeSquare(int $x): int', $edit->replacement);
        self::assertStringContainsString('* Compute square.', $edit->replacement);
    }

    public function testFlagsOwnerDependencies(): void
    {
        $this->write('WithState.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class WithState
{
    private string $name = 'test';

    public function greet(): string
    {
        return 'Hello ' . $this->name;
    }
}
PHP);

        $this->write('Other.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class Other
{
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodCopyPlanner($this->root);

        $plan = $planner->plan($map, 'App\WithState::greet', 'App\Other');
        self::assertSame(PlanStatus::REVIEW_REQUIRED, $plan->status);
        self::assertContains('$this', $plan->ownerDependencies);
        self::assertNotEmpty($plan->blindSpots);
    }

    public function testBlocksWhenDestinationAlreadyHasMethod(): void
    {
        $this->write('Source.php', <<<'PHP'
<?php

class Source
{
    public function foo(): void {}
}
PHP);

        $this->write('Dest.php', <<<'PHP'
<?php

class Dest
{
    public function foo(): void {}
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodCopyPlanner($this->root);

        $plan = $planner->plan($map, 'Source::foo', 'Dest');
        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('already exists on destination class Dest', $plan->blockers[0]);
    }

    private function write(string $name, string $source): void
    {
        file_put_contents($this->root . '/src/' . $name, $source);
    }
}
