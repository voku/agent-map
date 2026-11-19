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
 * `$bundle->amount(...)` and `self::make(...)` build closures. PHPStan replaces them with callable nodes, so they used to
 * leave no relation, and any first-class callable of the method's name blocked the rename. They are now resolved
 * call relations with byte ranges: the rename rewrites them, and only an unresolved one still blocks.
 */
final class FirstClassCallableRenameTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Call relations come from the optional phpstan/phpstan capability.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-first-class-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Shop.php', <<<'PHP'
<?php

namespace Shop;

final class Bundle
{
    public function amount(): int
    {
        return 1;
    }

    public static function make(): self
    {
        return new self();
    }
}

final class Coupon
{
    public function amount(): int
    {
        return 2;
    }
}

final class Refund
{
    /** @return list<\Closure> */
    public function quoters(Bundle $bundle, Coupon $coupon): array
    {
        return [$bundle->amount(...), $coupon->amount(...), Bundle::make(...)];
    }

    public function untyped(mixed $anything): \Closure
    {
        return $anything->make(...);
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

    public function testAResolvedFirstClassCallableIsRewrittenAndTheUnrelatedOneIsLeftAlone(): void
    {
        $source = (string) file_get_contents($this->root . '/src/Shop.php');
        $plan = (new MethodRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\Bundle::amount', 'price');

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, json_encode($plan->toArray()) ?: '');
        $calls = array_values(array_filter($plan->edits, static fn (PlanEdit $edit): bool => $edit->role === 'call'));
        self::assertCount(1, $calls);
        self::assertSame(strpos($source, '$bundle->amount(...)') + strlen('$bundle->'), $calls[0]->startFilePos);
    }

    public function testAStaticFirstClassCallableIsRewritten(): void
    {
        $this->writeWithoutUntyped();
        $source = (string) file_get_contents($this->root . '/src/Shop.php');
        $plan = (new MethodRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\Bundle::make', 'create');

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, json_encode($plan->toArray()) ?: '');
        $calls = array_values(array_filter($plan->edits, static fn (PlanEdit $edit): bool => $edit->role === 'call'));
        self::assertCount(1, $calls);
        self::assertSame(strpos($source, 'Bundle::make(...)') + strlen('Bundle::'), $calls[0]->startFilePos);
    }

    public function testAnUnresolvedFirstClassCallableOfTheNameStillBlocks(): void
    {
        $plan = (new MethodRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\Bundle::make', 'create');

        self::assertNotSame(MethodRenamePlan::STATUS_SAFE, $plan->status);
        self::assertNotEmpty(array_filter(
            $plan->blockers,
            static fn (string $blocker): bool => str_contains($blocker, 'callable naming this method'),
        ), json_encode($plan->toArray()) ?: '');
    }

    private function writeWithoutUntyped(): void
    {
        $path = $this->root . '/src/Shop.php';
        $source = (string) file_get_contents($path);
        $start = (int) strpos($source, '    public function untyped');
        $end = (int) strpos($source, "    }\n", $start) + strlen("    }\n");
        file_put_contents($path, substr($source, 0, $start) . substr($source, $end));
    }
}
