<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\IO\PhpFileFinder;
use voku\AgentMap\Plan\PlanEdit;
use voku\AgentMap\Rename\FunctionRenamePlanner;

/**
 * Plain PHP templates (`.phtml`) are PHP source: their calls into application code are call sites. A rename that
 * ignored them would publish a "safe" plan and leave the template calling a function that no longer exists.
 */
final class PhtmlTemplateIndexingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-phtml-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/templates', 0o775, true);
        file_put_contents($this->root . '/src/helpers.php', "<?php\n\nfunction page_title(string \$name): string\n{\n    return ucfirst(\$name);\n}\n");
        file_put_contents($this->root . '/src/View.php', "<?php\n\nnamespace App;\n\nfinal class View\n{\n    public function heading(): string\n    {\n        return 'home';\n    }\n}\n");
        file_put_contents($this->root . '/templates/page.phtml', "<?php /** @var \\App\\View \$view */ ?>\n<h1><?= page_title('home') ?></h1>\n<h2><?= \$view->heading() ?></h2>\n<p>static <?php echo 'html'; ?></p>\n");
        file_put_contents($this->root . '/templates/notes.txt', "page_title() mentioned in prose\n");
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testTheFinderIndexesPhtmlTemplatesAsPhpSource(): void
    {
        self::assertSame(
            ['src/View.php', 'src/helpers.php', 'templates/page.phtml'],
            (new PhpFileFinder())->find($this->root, ['.']),
        );
        self::assertTrue(PhpFileFinder::isPhpSource('views/a.phtml'));
        self::assertFalse(PhpFileFinder::isPhpSource('views/a.phtml.bak'));
        self::assertFalse(PhpFileFinder::isPhpSource('views/a.html'));
    }

    public function testTheStructuralMapRecordsTheTemplateAsAFile(): void
    {
        $map = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src', 'templates'], []);

        self::assertContains('templates/page.phtml', array_map(static fn ($file): string => $file->path, $map->files));
    }

    public function testAFunctionRenamePlanEditsTheCallInsideTheTemplate(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Semantic call sites require the optional phpstan/phpstan capability.');
        }
        $map = (new AgentMapBuilder())->build($this->root, ['src', 'templates'], []);
        $plan = (new FunctionRenamePlanner())->plan($map, 'page_title', 'heading_text');

        $sites = array_map(static fn (PlanEdit $edit): string => $edit->path . ':' . $edit->role, $plan->edits);
        sort($sites, SORT_STRING);
        self::assertContains('templates/page.phtml:call', $sites, 'the template call site must be part of the plan: ' . implode(', ', $sites) . ' / ' . implode("\n", $plan->blockers));
        self::assertContains('src/helpers.php:declaration', $sites);
    }

    public function testTheTemplateIsACallerOfATypedViewMethod(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Semantic call sites require the optional phpstan/phpstan capability.');
        }
        $map = (new AgentMapBuilder())->build($this->root, ['src', 'templates'], []);
        $callers = array_map(static fn (RelationEntry $relation): string => $relation->file, $map->incoming('method:App\\View::heading', 'calls'));

        self::assertContains('templates/page.phtml', $callers);
    }
}
