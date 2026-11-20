<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Rename\ParameterRenamePlan;
use voku\AgentMap\Rename\ParameterRenamePlanner;

/**
 * The parameter planner had the same gap as the method planner: an anonymous implementation of the interface blocked
 * the plan as "crosses an unindexed method contract". It now joins the family with exact parameter edits.
 */
final class AnonymousClassParameterRenameTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Override relations come from the optional phpstan/phpstan capability.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-anonymous-parameter-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Provider.php', <<<'PHP'
<?php

namespace Shop;

interface Provider
{
    public function collect(string $task): int;
}

final class RealProvider implements Provider
{
    public function collect(string $task): int
    {
        return strlen($task);
    }
}
PHP);
        file_put_contents($this->root . '/src/Usage.php', <<<'PHP'
<?php

namespace Shop;

final class Usage
{
    public function run(): int
    {
        $double = new class implements Provider {
            public function collect(string $task): int
            {
                return strlen($task) * 2;
            }
        };

        return $double->collect(task: 'x');
    }
}
PHP);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testTheAnonymousImplementationsParameterIsRenamedWithItsFamily(): void
    {
        $usage = (string) file_get_contents($this->root . '/src/Usage.php');
        $plan = (new ParameterRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\Provider::collect', 'task', 'subject');

        self::assertNotSame(ParameterRenamePlan::STATUS_BLOCKED, $plan->status, json_encode($plan->toArray()) ?: '');
        self::assertCount(1, array_filter($plan->family, static fn (string $id): bool => str_starts_with($id, 'method:AnonymousClass')));
        $usageEdits = array_map(
            static fn (PlanEdit $edit): array => [$edit->role, $edit->startFilePos],
            array_values(array_filter($plan->edits, static fn (PlanEdit $edit): bool => $edit->path === 'src/Usage.php')),
        );
        sort($usageEdits);
        self::assertSame([
            ['named_argument', strpos($usage, "task: 'x'")],
            ['parameter_declaration', strpos($usage, '$task)')],
            ['parameter_reference', strpos($usage, '$task) * 2')],
        ], $usageEdits);
    }

    public function testAnAnonymousImplementationWithAnotherParameterNameBlocks(): void
    {
        $path = $this->root . '/src/Usage.php';
        file_put_contents($path, str_replace(['collect(string $task)', 'strlen($task) * 2', "task: 'x'"], ['collect(string $job)', 'strlen($job) * 2', "'x'"], (string) file_get_contents($path)));

        $plan = (new ParameterRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\Provider::collect', 'task', 'subject');

        self::assertSame(ParameterRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertNotEmpty(array_filter($plan->blockers, static fn (string $blocker): bool => str_contains($blocker, 'is not consistently named')), json_encode($plan->toArray()) ?: '');
    }
}
