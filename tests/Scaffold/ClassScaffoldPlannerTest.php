<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Plan\PlanStatus;
use voku\AgentMap\Scaffold\ClassScaffoldPlanner;

final class ClassScaffoldPlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-class-scaffold-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/composer.json', json_encode([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT));
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

    public function testPlansSafeClassScaffoldWithPsr4Derivation(): void
    {
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new ClassScaffoldPlanner($this->root);

        $plan = $planner->plan(
            map: $map,
            fqn: 'App\Domain\Model\Invoice',
            type: 'class',
            final: true,
            readonly: true,
            docSummary: 'Invoice entity.',
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('App\Domain\Model\Invoice', $plan->fqn);
        self::assertSame('src/Domain/Model/Invoice.php', $plan->destinationPath);
        self::assertCount(1, $plan->edits);

        $edit = $plan->edits[0];
        self::assertSame('file_creation', $edit->role);
        self::assertStringContainsString('declare(strict_types=1);', $edit->replacement);
        self::assertStringContainsString('namespace App\Domain\Model;', $edit->replacement);
        self::assertStringContainsString('final readonly class Invoice', $edit->replacement);
        self::assertStringContainsString('* Invoice entity.', $edit->replacement);
    }

    public function testScaffoldsInterfaceWithExtends(): void
    {
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new ClassScaffoldPlanner($this->root);

        $plan = $planner->plan(
            map: $map,
            fqn: 'App\Contract\Payable',
            type: 'interface',
            extends: 'Stringable',
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('src/Contract/Payable.php', $plan->destinationPath);

        $edit = $plan->edits[0];
        self::assertStringContainsString('interface Payable extends Stringable', $edit->replacement);
    }

    public function testScaffoldsClassWithImplementsAndImports(): void
    {
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new ClassScaffoldPlanner($this->root);

        $plan = $planner->plan(
            map: $map,
            fqn: 'App\Service\PaymentGateway',
            implements: ['App\Contract\Payable', 'Vendor\Package\RemoteClientInterface'],
        );

        self::assertSame(PlanStatus::SAFE, $plan->status, implode("\n", $plan->blockers));
        $edit = $plan->edits[0];
        self::assertStringContainsString('use App\Contract\Payable;', $edit->replacement);
        self::assertStringContainsString('use Vendor\Package\RemoteClientInterface;', $edit->replacement);
        self::assertStringContainsString('final class PaymentGateway implements Payable, RemoteClientInterface', $edit->replacement);
    }

    public function testBlocksWhenFileAlreadyExists(): void
    {
        mkdir($this->root . '/src/Existing', 0o775, true);
        file_put_contents($this->root . '/src/Existing/ClassA.php', "<?php\n\nnamespace App\\Existing;\n\nclass OtherClass\n{\n}\n");

        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new ClassScaffoldPlanner($this->root);

        $plan = $planner->plan($map, 'App\Existing\ClassA');
        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('Destination file already exists', $plan->blockers[0]);
    }

    public function testBlocksWhenNoPsr4MappingMatches(): void
    {
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $planner = new ClassScaffoldPlanner($this->root);

        $plan = $planner->plan($map, 'UnknownNamespace\Foo');
        self::assertSame(PlanStatus::BLOCKED, $plan->status);
        self::assertStringContainsString('No declared PSR-4 mapping matches', $plan->blockers[0]);
    }
}
