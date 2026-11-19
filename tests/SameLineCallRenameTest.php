<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Rename\FunctionRenamePlanner;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlanner;

/**
 * Two calls with the same name on one line, only one of them in the rename family: `$invoice->total() + $cart->total()`.
 * The call relation carries the byte range of its call expression, so the planner edits exactly the family's token
 * and leaves the other one alone. Before, the line alone was ambiguous and the whole plan was blocked.
 */
final class SameLineCallRenameTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Call relations come from the optional phpstan/phpstan capability.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-same-line-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Shop.php', <<<'PHP'
<?php

namespace Shop;

final class Invoice
{
    public function total(): int
    {
        return 1;
    }
}

final class Cart
{
    public function total(): int
    {
        return 2;
    }
}

function fee(int $amount): int
{
    return $amount;
}

final class Checkout
{
    public function due(Invoice $invoice, Cart $cart): int
    {
        return $invoice->total() + $cart->total() + fee(1) + fee(2);
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

    public function testOnlyTheFamilysCallOnTheSharedLineIsEdited(): void
    {
        $source = (string) file_get_contents($this->root . '/src/Shop.php');
        $plan = (new MethodRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\Invoice::total', 'grandTotal');

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        $calls = array_values(array_filter($plan->edits, static fn (PlanEdit $edit): bool => $edit->role === 'call'));
        self::assertCount(1, $calls);
        $invoiceCall = strpos($source, '$invoice->total()') + strlen('$invoice->');
        self::assertSame($invoiceCall, $calls[0]->startFilePos, 'the Invoice call, not the Cart call on the same line');
    }

    public function testBothSameLineCallsOfAFunctionAreEditedExactlyOnce(): void
    {
        $source = (string) file_get_contents($this->root . '/src/Shop.php');
        $plan = (new FunctionRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\fee', 'charge');

        $calls = array_map(static fn (PlanEdit $edit): int => $edit->startFilePos, array_values(array_filter($plan->edits, static fn (PlanEdit $edit): bool => $edit->role === 'call')));
        sort($calls);
        self::assertSame([strpos($source, 'fee(1)'), strpos($source, 'fee(2)')], $calls, implode("\n", $plan->blockers));
    }
}
