<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlanner;

/**
 * `$catalog->maybe()?->amount()` dispatches on the non-null part of `?Product`. The nullsafe call used to be projected
 * with the receiver `Product|null`, which has no class reflection of its own, so it was recorded as a dynamic call
 * next to the resolved one, and every rename of `Product::amount()` came back review_required.
 */
final class NullsafeCallRenameTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Call relations come from the optional phpstan/phpstan capability.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-nullsafe-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Shop.php', <<<'PHP'
<?php

namespace Shop;

final class Product
{
    public int $cents = 5;

    public function amount(): int
    {
        return $this->cents;
    }
}

final class Catalog
{
    public function maybe(bool $found): ?Product
    {
        return $found ? new Product() : null;
    }
}

final class Display
{
    public function line(Catalog $catalog): ?int
    {
        return $catalog->maybe(true)?->amount();
    }

    public function cents(Catalog $catalog): ?int
    {
        return $catalog->maybe(false)?->cents;
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

    public function testANullsafeCallIsANormalFamilyCallAndNotABlindSpot(): void
    {
        $source = (string) file_get_contents($this->root . '/src/Shop.php');
        $plan = (new MethodRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Shop\\Product::amount', 'price');

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, json_encode($plan->toArray()) ?: '');
        self::assertSame([], $plan->blindSpots);
        $calls = array_values(array_filter($plan->edits, static fn (PlanEdit $edit): bool => $edit->role === 'call'));
        self::assertCount(1, $calls);
        self::assertSame(strpos($source, '?->amount()') + strlen('?->'), $calls[0]->startFilePos);
    }

    public function testNullsafeCallsAndPropertyFetchesResolveOnTheNonNullReceiver(): void
    {
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $nullsafe = array_values(array_filter(
            $map->relations,
            static fn (RelationEntry $relation): bool => $relation->sourceId === 'method:Shop\\Display::line' && $relation->kind === 'calls'
                && $relation->targetIds !== ['method:Shop\\Catalog::maybe'],
        ));
        $fetches = array_values(array_filter(
            $map->relations,
            static fn (RelationEntry $relation): bool => $relation->sourceId === 'method:Shop\\Display::cents' && $relation->kind === 'property_access',
        ));

        self::assertNotSame([], $nullsafe);
        foreach ($nullsafe as $relation) {
            self::assertSame(['method:Shop\\Product::amount'], $relation->targetIds, 'no dynamic copy of the nullsafe call');
            self::assertSame('phpstan_resolved', $relation->resolution);
        }
        self::assertNotSame([], $fetches);
        foreach ($fetches as $relation) {
            self::assertSame(['property:Shop\\Product::$cents'], $relation->targetIds);
        }
    }
}
