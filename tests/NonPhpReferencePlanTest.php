<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Removal\MethodRemovalPlan;
use voku\AgentMap\Removal\MethodRemovalPlanner;
use voku\AgentMap\Rename\ClassRenamePlanner;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlanner;

/** Docs and templates that mention a symbol are residue evidence: they must never change plan status. */
final class NonPhpReferencePlanTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Plan tests require PHPStan.');
        }
        $this->root = sys_get_temp_dir() . '/agent-map-nonphp-plan-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/templates', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
    public function oldName(): void
    {
    }

    private function unused(): void
    {
    }
}
PHP);
        file_put_contents($this->root . '/README.md', "Call `Service::oldName()` or `Service::unused()`; see \\Demo\\Service.\n");
        file_put_contents($this->root . '/templates/page.html.twig', "{{ service.oldName() }}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAMethodRenameStaysSafeAndListsTheResidualReferences(): void
    {
        $plan = (new MethodRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Demo\\Service::oldName', 'newName');
        $data = $plan->toArray();

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame([], $data['blind_spots']);
        self::assertSame(2, $data['non_php_references']['total']);
        $paths = array_column($data['non_php_references']['references'], 'path');
        self::assertSame(['README.md', 'templates/page.html.twig'], $paths);
    }

    public function testAMethodRemovalStaysSafeAndListsTheResidualReferences(): void
    {
        $plan = (new MethodRemovalPlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Demo\\Service::unused');
        $data = $plan->toArray();

        self::assertSame(MethodRemovalPlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame([], $data['blind_spots']);
        self::assertSame('README.md', $data['non_php_references']['references'][0]['path']);
    }

    public function testAClassRenameStaysSafeAndListsTheResidualReferences(): void
    {
        $plan = (new ClassRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Demo\\Service', 'Demo\\Worker');
        $data = $plan->toArray();

        self::assertNotNull($plan->nonPhpReferences);
        self::assertContains('exact_fqcn', array_column($data['non_php_references']['references'], 'confidence'));
    }

    public function testAPlanWithoutAnyMentionCarriesNoReferenceKey(): void
    {
        unlink($this->root . '/README.md');
        unlink($this->root . '/templates/page.html.twig');
        $plan = (new MethodRenamePlanner())->plan((new AgentMapBuilder())->build($this->root, ['src'], []), 'Demo\\Service::oldName', 'newName');

        self::assertArrayNotHasKey('non_php_references', $plan->toArray());
    }
}
