<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Plan\PlanStatus;
use voku\AgentMap\Scaffold\MethodScaffoldPlan;
use voku\AgentMap\Scaffold\MethodScaffoldPlanner;

final class MethodScaffoldPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-method-scaffold-' . bin2hex(random_bytes(6));
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

    public function testPlansSafeMethodScaffold(): void
    {
        $this->write('Calculator.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Math;

final class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodScaffoldPlanner($this->root);

        $plan = $planner->plan(
            map: $map,
            target: 'App\Math\Calculator::subtract',
            visibility: 'public',
            static: false,
            parameters: ['int $a', 'int $b'],
            returnType: 'int',
            docSummary: 'Subtract b from a.',
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('App\Math\Calculator', $plan->targetClass);
        self::assertSame('subtract', $plan->methodName);
        self::assertCount(1, $plan->edits);

        $edit = $plan->edits[0];
        self::assertSame('method_declaration_insertion', $edit->role);
        self::assertStringContainsString('public function subtract(int $a, int $b): int', $edit->replacement);
        self::assertStringContainsString('* Subtract b from a.', $edit->replacement);
    }

    public function testInsertsUseStatementsForExternalTypes(): void
    {
        $this->write('UserService.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

final class UserService
{
    public function find(int $id): ?User
    {
        return null;
    }
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodScaffoldPlanner($this->root);

        $plan = $planner->plan(
            map: $map,
            target: 'App\Service\UserService::logAction',
            parameters: ['User $user', 'DateTimeImmutable $at'],
            returnType: 'void',
            docSummary: 'Record action timestamp.',
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        // DateTimeImmutable is external and should get a use statement edit
        self::assertCount(2, $plan->edits);
        self::assertSame('use_statement_insertion', $plan->edits[0]->role);
        self::assertStringContainsString('use DateTimeImmutable;', $plan->edits[0]->replacement);
        self::assertSame('method_declaration_insertion', $plan->edits[1]->role);
    }

    public function testBlocksWhenMethodAlreadyExists(): void
    {
        $this->write('Existing.php', <<<'PHP'
<?php

declare(strict_types=1);

class Existing
{
    public function alreadyHere(): void
    {
    }
}
PHP);

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodScaffoldPlanner($this->root);

        $plan = $planner->plan($map, 'Existing::alreadyHere');
        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertNotEmpty($plan->blockers);
        self::assertStringContainsString('already exists', $plan->blockers[0]);
    }

    public function testBlocksWhenTargetNotFound(): void
    {
        $this->write('Empty.php', "<?php\n\nclass SomeClass\n{\n}\n");
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new MethodScaffoldPlanner($this->root);

        $plan = $planner->plan($map, 'NonExistent::foo');
        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('Target class not found in index', $plan->blockers[0]);
    }

    private function write(string $name, string $source): void
    {
        file_put_contents($this->root . '/src/' . $name, $source);
    }
}
