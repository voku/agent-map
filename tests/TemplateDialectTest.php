<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentMap\Reference\NonPhpReference;
use voku\AgentMap\Reference\NonPhpReferenceScanner;
use voku\AgentMap\Reference\ReferenceTarget;
use voku\AgentMap\Reference\Template\RegexTemplateDialect;
use voku\AgentMap\Reference\Template\SmartyDialect;
use voku\AgentMap\Reference\Template\TemplateDialect;

final class TemplateDialectTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-dialect-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/templates', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function smartySources(): iterable
    {
        yield 'plain tag' => ['<b>{$user->getName()}</b>', ['{$user->getName()}']];
        yield 'modifier and arguments' => ['{$user->getName()|escape:"html"}', ['{$user->getName()|escape:"html"}']];
        yield 'comment is not code' => ['{* {$user->getName()} *}x{$a}', ['{$a}']];
        yield 'multi-line comment' => ["{*\n {\$user->getName()}\n*}", []];
        yield 'literal block is text' => ['{literal}{a: b.getName()}{/literal}{$b}', ['{$b}']];
        yield 'literal tag is case-insensitive' => ['{LITERAL}{x}{/LITERAL}', []];
        yield 'auto-literal whitespace' => ['<script>var o = { a: 1 };</script>{$c}', ['{$c}']];
        yield 'nested tag in array index' => ['{$a[{$b}]->getName()}', ['{$a[{$b}]->getName()}']];
        yield 'nested tag in double quotes' => ['{"Hello {$user->getName()}!"}', ['{"Hello {$user->getName()}!"}']];
        yield 'right delimiter inside single quotes' => ["{\$a|default:'}'} rest", ["{\$a|default:'}'}"]];
        yield 'block tags' => ['{if $u->isAdmin()}x{/if}', ['{if $u->isAdmin()}', '{/if}']];
        yield 'unterminated tag claims nothing' => ['{$a} {$b', ['{$a}']];
        yield 'unterminated comment hides the rest' => ['{$a} {* {$b}', ['{$a}']];
    }

    /** @param list<string> $expected */
    #[DataProvider('smartySources')]
    public function testSmartyCodeRegionsFollowTheSmarty5Lexer(string $source, array $expected): void
    {
        self::assertSame($expected, $this->texts(new SmartyDialect(), $source));
    }

    public function testSmartyCustomDelimitersAreHonoured(): void
    {
        $dialect = new SmartyDialect('<{', '}>');

        self::assertSame(
            ['<{$user->getName()}>', '<{$x[<{$y}>]}>'],
            $this->texts($dialect, '{not a tag} <{$user->getName()}> <{* <{$hidden}> *}> <{$x[<{$y}>]}> <{literal}><{$z}><{/literal}>'),
        );
    }

    public function testSmartyAutoLiteralCanBeTurnedOff(): void
    {
        self::assertSame(['{ $a }'], $this->texts(new SmartyDialect(autoLiteral: false), '{ $a }'));
        self::assertSame([], $this->texts(new SmartyDialect(), '{ $a }'));
    }

    public function testSmartySuffixesAreConfigurable(): void
    {
        $dialect = new SmartyDialect(suffixes: ['.tpl', '.smarty']);

        self::assertTrue($dialect->handles('views/a.TPL'));
        self::assertTrue($dialect->handles('views/a.smarty'));
        self::assertFalse($dialect->handles('views/a.twig'));
        self::assertFalse($dialect->attributeReachesGetter());
    }

    public function testSmartyRejectsEmptyDelimiters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SmartyDialect('');
    }

    public function testScannerOnSmartyFindsCodeAndSkipsCommentsLiteralsAndArrayKeys(): void
    {
        file_put_contents($this->root . '/templates/user.tpl', <<<'TPL'
{* {$user->getName()} commented out *}
<h1>{$user->getName()|escape}</h1>
<p>{$user.name}</p>
{literal}<script>var x = {a: user.getName()};</script>{/literal}
{App\Model\User::getName()}
TPL);

        $report = (new NonPhpReferenceScanner())->scan($this->root, ReferenceTarget::method('App\\Model\\User', 'getName'));

        self::assertSame([
            'templates/user.tpl:2:member_name_only:->getName',
            'templates/user.tpl:5:exact_fqcn:App\\Model\\User::getName',
        ], $this->summary($report->references));
    }

    public function testTwigAttributeStillReachesTheGetterButBladeAndSmartyDoNot(): void
    {
        file_put_contents($this->root . '/templates/a.html.twig', "{{ user.name }}\n");
        file_put_contents($this->root . '/templates/b.blade.php', "{{ \$user->name }}\n");
        file_put_contents($this->root . '/templates/c.tpl', "{\$user->name} {\$user.name}\n");

        $report = (new NonPhpReferenceScanner())->scan($this->root, ReferenceTarget::method('App\\User', 'getName'));

        self::assertSame(['templates/a.html.twig:1:member_name_only:.name'], $this->summary($report->references));
    }

    public function testAProjectCanPlugInItsOwnDialects(): void
    {
        file_put_contents($this->root . '/templates/page.latte', "{\$user->getName()} <p>getName()</p>\n");
        file_put_contents($this->root . '/templates/page.tpl', "<{\$user->getName()}> {\$ignored->getName()}\n");
        $latte = new RegexTemplateDialect('latte', ['.latte'], ['/\{(?![\s*])[^{}]*\}/'], false);

        $report = (new NonPhpReferenceScanner(200, [$latte, new SmartyDialect('<{', '}>')]))
            ->scan($this->root, ReferenceTarget::method('App\\User', 'getName'));

        self::assertSame([
            'templates/page.latte:1:member_name_only:->getName',
            'templates/page.tpl:1:member_name_only:->getName',
        ], $this->summary($report->references));
        self::assertSame(1, substr_count(implode("\n", $this->summary($report->references)), 'page.tpl'));
    }

    public function testFilesNoDialectHandlesAreNotScanned(): void
    {
        file_put_contents($this->root . '/templates/page.tpl', "{\$user->getName()}\n");

        $report = (new NonPhpReferenceScanner(200, [RegexTemplateDialect::twig()]))
            ->scan($this->root, ReferenceTarget::method('App\\User', 'getName'));

        self::assertSame(0, $report->scannedFiles);
    }

    /** @return list<string> */
    private function texts(TemplateDialect $dialect, string $source): array
    {
        return array_map(
            static fn (array $region): string => substr($source, $region[0], $region[1] - $region[0]),
            $dialect->codeRegions($source),
        );
    }

    /**
     * @param list<NonPhpReference> $references
     * @return list<string>
     */
    private function summary(array $references): array
    {
        return array_map(
            static fn (NonPhpReference $reference): string => sprintf('%s:%d:%s:%s', $reference->path, $reference->line, $reference->confidence, $reference->matched),
            $references,
        );
    }
}
