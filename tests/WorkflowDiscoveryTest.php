<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\TemplateParser;
use voku\AgentMap\Discovery\TemplateScanner;
use voku\AgentMap\Discovery\WorkflowDiscovery;
use voku\AgentMap\Index\AgentMapBuilder;

final class WorkflowDiscoveryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/agent-map-workflow-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/src', 0o775, true);
        mkdir($this->tempDir . '/templates/sub', 0o775, true);

        file_put_contents($this->tempDir . '/src/OrderView.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class OrderView
        {
            public function show(): string
            {
                $smarty = new Smarty();
                return $smarty->render_with_data($this->getTemplateForm(), []);
            }

            private function getTemplateForm(): string
            {
                return 'order_form.tpl';
            }
        }

        final class OrderSaveAjax
        {
            public function save(): void
            {
            }
        }
        PHP);

        file_put_contents($this->tempDir . '/templates/sub/header.tpl', <<<'TPL'
        <header>Header Content</header>
        TPL);

        file_put_contents($this->tempDir . '/templates/order_form.tpl', <<<'TPL'
        {include file="sub/header.tpl"}
        <form action="index.php?action=OrderSave" method="POST">
            <input type="text" name="order_number" />
            <select name="status">
                <option value="1">Active</option>
            </select>
            <button type="button" onclick="xajax_OrderSaveAjax(this.value)">Save</button>
        </form>
        TPL);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    public function testTemplateParserExtractsMetadata(): void
    {
        $parser = new TemplateParser();
        $content = file_get_contents($this->tempDir . '/templates/order_form.tpl');
        self::assertIsString($content);

        $info = $parser->parse('templates/order_form.tpl', $content);

        self::assertSame('order_form.tpl', $info->name);
        self::assertSame('smarty', $info->engine);
        self::assertSame(['sub/header.tpl'], $info->includes);
        self::assertCount(1, $info->forms);
        self::assertSame('post', $info->forms[0]['method']);
        self::assertSame(['order_number', 'status'], $info->inputs);
        self::assertSame(['xajax_OrderSaveAjax'], $info->xajax);
    }

    public function testTemplateScannerDiscoversTemplates(): void
    {
        $scanner = new TemplateScanner();
        $discovered = $scanner->scan($this->tempDir);

        self::assertArrayHasKey('templates/order_form.tpl', $discovered);
        self::assertArrayHasKey('templates/sub/header.tpl', $discovered);
    }

    public function testWorkflowDiscoveryLinksPhpAndTemplates(): void
    {
        $builder = new AgentMapBuilder();
        $map = $builder->build($this->tempDir, ['src'], []);

        $discovery = new WorkflowDiscovery();
        $report = $discovery->discover($map, 'Demo\OrderView');

        self::assertSame('symbol', $report->targetKind);
        self::assertCount(1, $report->phpSymbols);
        self::assertSame('Demo\OrderView', $report->phpSymbols[0]['symbol']);

        self::assertCount(1, $report->templates);
        self::assertSame('order_form.tpl', $report->templates[0]->name);

        self::assertCount(1, $report->includedTemplates);
        self::assertSame('sub/header.tpl', $report->includedTemplates[0]->name);

        self::assertCount(1, $report->xajaxHandlers);
        self::assertSame('xajax_OrderSaveAjax', $report->xajaxHandlers[0]['call']);
        self::assertSame('Demo\OrderSaveAjax', $report->xajaxHandlers[0]['target_symbol']);

        self::assertNotEmpty($report->flowSteps);
    }

    public function testWorkflowDiscoveryReverseLookupFromTemplate(): void
    {
        $builder = new AgentMapBuilder();
        $map = $builder->build($this->tempDir, ['src'], []);

        $discovery = new WorkflowDiscovery();
        $report = $discovery->discover($map, 'order_form.tpl');

        self::assertSame('template', $report->targetKind);
        self::assertCount(1, $report->templates);
        self::assertSame('order_form.tpl', $report->templates[0]->name);

        self::assertCount(1, $report->phpSymbols);
        self::assertSame('Demo\OrderView', $report->phpSymbols[0]['symbol']);
    }

    public function testWorkflowDiscoveryReverseLookupFromAjaxHandler(): void
    {
        $builder = new AgentMapBuilder();
        $map = $builder->build($this->tempDir, ['src'], []);

        $discovery = new WorkflowDiscovery();
        $report = $discovery->discover($map, 'Demo\OrderSaveAjax');

        self::assertSame('symbol', $report->targetKind);
        self::assertCount(1, $report->templates);
        self::assertSame('order_form.tpl', $report->templates[0]->name);

        // Found both the Ajax handler itself and the View that renders the template
        $symbols = array_column($report->phpSymbols, 'symbol');
        self::assertContains('Demo\OrderSaveAjax', $symbols);
        self::assertContains('Demo\OrderView', $symbols);
    }

    public function testTemplateScannerExcludesHiddenDirectories(): void
    {
        mkdir($this->tempDir . '/.hidden_dir', 0o775, true);
        file_put_contents($this->tempDir . '/.hidden_dir/secret.tpl', '<div>hidden</div>');

        $scanner = new TemplateScanner();
        $templates = $scanner->scan($this->tempDir);

        self::assertArrayNotHasKey('.hidden_dir/secret.tpl', $templates);
    }

    public function testWorkflowDiscoverySeparatesTestsFromProductionPhpSymbols(): void
    {
        mkdir($this->tempDir . '/tests', 0o775, true);
        file_put_contents($this->tempDir . '/tests/OrderWorkflowTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo\Tests;

        final class OrderWorkflowTest
        {
            public function testRendersOrderForm(): void
            {
                $tpl = 'order_form.tpl';
            }
        }
        PHP);

        $builder = new AgentMapBuilder();
        $map = $builder->build($this->tempDir, ['src', 'tests'], []);

        $discovery = new WorkflowDiscovery();
        $report = $discovery->discover($map, 'order_form.tpl');

        $prodSymbols = array_column($report->phpSymbols, 'symbol');
        self::assertContains('Demo\OrderView', $prodSymbols);
        self::assertNotContains('Demo\Tests\OrderWorkflowTest', $prodSymbols);

        $testSymbols = array_column($report->testSymbols, 'symbol');
        self::assertContains('Demo\Tests\OrderWorkflowTest', $testSymbols);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->deleteDirectory($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
