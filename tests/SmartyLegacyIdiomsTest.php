<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Reference\Template\Smarty\SmartyEvidenceBuilder;

/**
 * Idioms found when the Smarty analysis was run over a large real gallery application (see
 * docs/experiments/template-engine-evidence.md, "Real-world validation"). The fixture is written for this test; it
 * reproduces the shapes, not the project's code: a wrapper class that owns Smarty in a property and registers plugins
 * with the legacy array callback syntax, the `|@modifier` prefix, modifiers inside HTML attribute values, block plugins,
 * and the same calls hidden in `{* ... *}` comments, which must not count.
 */
final class SmartyLegacyIdiomsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-smarty-legacy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/include', 0o775, true);
        mkdir($this->root . '/themes/default/template', 0o775, true);
        file_put_contents($this->root . '/include/template.class.php', <<<'PHP'
<?php

class Template
{
    private $smarty;

    public function __construct()
    {
        $this->smarty = new Smarty();
        $this->smarty->registerPlugin('modifiercompiler', 'translate', array('Template', 'modcompiler_translate'));
        $this->smarty->registerPlugin('block', 'footer_script', array(&$this, 'block_footer_script'));
        $this->smarty->registerPlugin('modifier', 'ucfirst', 'ucfirst');
    }

    public static function modcompiler_translate($params) { return ''; }

    public function block_footer_script($params, $content) { return ''; }
}
PHP);
        file_put_contents($this->root . '/themes/default/template/page.tpl', <<<'TPL'
<h1>{'Albums'|@translate}</h1>
<a title="{'Edit'|@translate}" {*title="{'Old'|@translate}"*}>{$name|@ucfirst}</a>
{* <div>{'Hidden'|@translate}</div> *}
{footer_script}var label = "{'Saved'|translate|escape:javascript}";{/footer_script}
TPL);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testLegacyIdiomsResolveToTheRegisteredPhpAndCommentsDoNotCount(): void
    {
        $report = (new SmartyEvidenceBuilder())->build($this->root);
        $source = (string) file_get_contents($this->root . '/themes/default/template/page.tpl');

        $found = [];
        foreach ($report->references as $reference) {
            self::assertSame($reference->text, substr($source, $reference->startFilePos, $reference->endFilePos - $reference->startFilePos + 1), 'exact byte range');
            $found[] = $reference->line . ':' . $reference->text . '->' . $reference->symbol();
        }
        sort($found, SORT_STRING);

        self::assertSame([
            '1:translate->Template::modcompiler_translate',
            '2:translate->Template::modcompiler_translate',
            '2:ucfirst->ucfirst',
            '4:footer_script->Template::block_footer_script',
            '4:translate->Template::modcompiler_translate',
        ], $found, 'two commented-out translate calls (lines 2 and 3) must not be references');
    }
}
