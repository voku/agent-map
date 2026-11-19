<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlanner;

/**
 * Test doubles often implement an interface with `new class implements Provider`. PHPStan records the anonymous
 * method's override with its file and lines, but the class has no symbol in the structural index, so the rename
 * family was blocked as "crosses an unindexed method contract". The anonymous member now joins the family with an
 * exact declaration edit, and calls on it are rewritten like any other family call.
 */
final class AnonymousClassRenameFamilyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Override relations come from the optional phpstan/phpstan capability.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-anonymous-family-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Provider.php', <<<'PHP'
<?php

namespace Shop;

interface Provider
{
    public function collect(): int;
}

final class RealProvider implements Provider
{
    public function collect(): int
    {
        return 1;
    }
}
PHP);
        file_put_contents($this->root . '/src/Usage.php', <<<'PHP'
<?php

namespace Shop;

final class Usage
{
    public function run(Provider $provider): int
    {
        $double = new class implements Provider {
            public function collect(): int
            {
                return 2;
            }
        };

        return $provider->collect() + $double->collect();
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

    public function testTheAnonymousImplementationJoinsTheFamilyWithExactEdits(): void
    {
        $usage = (string) file_get_contents($this->root . '/src/Usage.php');
        $plan = (new MethodRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\Provider::collect', 'gather');

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, json_encode($plan->toArray()) ?: '');
        self::assertCount(3, $plan->family);
        self::assertCount(1, array_filter($plan->family, static fn (string $id): bool => str_starts_with($id, 'method:AnonymousClass')));
        $usageEdits = array_map(
            static fn (PlanEdit $edit): array => [$edit->role, $edit->startFilePos],
            array_values(array_filter($plan->edits, static fn (PlanEdit $edit): bool => $edit->path === 'src/Usage.php')),
        );
        sort($usageEdits);
        self::assertSame([
            ['call', strpos($usage, '$provider->collect()') + strlen('$provider->')],
            ['call', strpos($usage, '$double->collect()') + strlen('$double->')],
            ['declaration', strpos($usage, 'function collect') + strlen('function ')],
        ], $usageEdits);
        self::assertCount(5, $plan->edits);
    }

    public function testAReplacementNameTheAnonymousClassAlreadyHasIsACollision(): void
    {
        $path = $this->root . '/src/Usage.php';
        file_put_contents($path, str_replace("                return 2;\n            }\n", "                return 2;\n            }\n\n            public function gather(): int\n            {\n                return 3;\n            }\n", (string) file_get_contents($path)));

        $plan = (new MethodRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\Provider::collect', 'gather');

        self::assertSame(MethodRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertNotEmpty(array_filter($plan->blockers, static fn (string $blocker): bool => str_contains($blocker, 'of anonymous class')), json_encode($plan->toArray()) ?: '');
    }
}
