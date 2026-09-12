<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\WorkflowCliApplication;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\MapArtifactPaths;

final class WorkflowCliApplicationTest extends TestCase
{
    private string $tempDir;
    private string $indexPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/agent-map-workflow-cli-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/src', 0o775, true);
        mkdir($this->tempDir . '/templates', 0o775, true);

        file_put_contents($this->tempDir . '/src/InvoiceView.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Billing;

        final class InvoiceView
        {
            public function render(): string
            {
                $smarty = new Smarty();
                return $smarty->render_with_data('invoice.tpl', []);
            }
        }
        PHP);

        file_put_contents($this->tempDir . '/templates/invoice.tpl', <<<'TPL'
        <form action="index.php?action=PayInvoice" method="POST">
            <input type="text" name="invoice_id" />
        </form>
        TPL);

        $builder = new AgentMapBuilder();
        $map = $builder->build($this->tempDir, ['src'], []);
        $this->indexPath = $this->tempDir . '/php-symbols.json';
        (new IndexWriter())->write($map, $this->indexPath);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    public function testSupportsCommand(): void
    {
        $app = new WorkflowCliApplication();
        self::assertTrue($app->supports(['bin/agent-map', 'workflow']));
        self::assertTrue($app->supports(['bin/agent-map', 'help', 'workflow']));
        self::assertFalse($app->supports(['bin/agent-map', 'other']));
    }

    public function testHelpOutput(): void
    {
        $app = new WorkflowCliApplication();
        ob_start();
        $status = $app->run(['bin/agent-map', 'help', 'workflow']);
        $out = ob_get_clean();

        self::assertSame(0, $status);
        self::assertStringContainsString('agent-map workflow', (string) $out);
    }

    public function testWorkflowExecutionTextOutput(): void
    {
        $artifacts = MapArtifactPaths::forProject($this->tempDir);
        $app = new WorkflowCliApplication(artifacts: $artifacts, defaultRoot: $this->tempDir);

        ob_start();
        $status = $app->run(['bin/agent-map', 'workflow', 'InvoiceView', '--index=' . $this->indexPath]);
        $out = ob_get_clean();

        self::assertSame(0, $status);
        self::assertStringContainsString('Workflow Discovery: InvoiceView', (string) $out);
        self::assertStringContainsString('InvoiceView', (string) $out);
        self::assertStringContainsString('invoice.tpl', (string) $out);
    }

    public function testWorkflowExecutionJsonOutput(): void
    {
        $artifacts = MapArtifactPaths::forProject($this->tempDir);
        $app = new WorkflowCliApplication(artifacts: $artifacts, defaultRoot: $this->tempDir);

        ob_start();
        $status = $app->run(['bin/agent-map', 'workflow', 'invoice.tpl', '--index=' . $this->indexPath, '--format=json']);
        $out = ob_get_clean();

        self::assertSame(0, $status);
        $data = json_decode((string) $out, true);
        self::assertIsArray($data);
        self::assertSame('template', $data['target_kind']);
        self::assertSame('invoice.tpl', $data['templates'][0]['name']);
        self::assertSame('Billing\InvoiceView', $data['php_symbols'][0]['symbol']);
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
