<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Reference\Template\Smarty\SmartyEvidenceBuilder;
use voku\AgentMap\Reference\Template\Smarty\SmartyEvidenceReport;
use voku\AgentMap\Reference\Template\Smarty\SmartyRenderSiteExtractor;
use voku\AgentMap\Reference\Template\Smarty\SmartyTemplateAnalyzer;
use voku\AgentMap\Reference\Template\Smarty\SmartyTypedReference;
use voku\AgentMap\Reference\Template\SmartyDialect;
use voku\AgentMap\Reference\Template\TemplateFact;

final class SmartyEvidenceBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-smarty-' . bin2hex(random_bytes(6));
        foreach (['src/Controller', 'src/View', 'templates/user', 'templates/partials', 'templates_c', 'vendor/pkg'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o775, true);
        }

        $this->write('src/Controller/ProfileController.php', <<<'PHP'
<?php

namespace App\Controller;

use App\Model\User;
use Smarty\Smarty;

final class ProfileController
{
    public function __construct(private Smarty $smarty, private \App\Http\Client $http)
    {
    }

    public function show(User $user, string $name): void
    {
        $this->smarty->assign('user', $user);
        $this->smarty->assign(['price' => 10, 'order' => $this->loadOrder()]);
        $this->smarty->display('user/profile.tpl');

        $this->http->fetch('https://example.invalid/profile');   // not Smarty
        $this->smarty->display($name);                           // dynamic render
    }

    public function mail(User $user): string
    {
        $tpl = $this->smarty->createTemplate('mail.tpl');
        $tpl->assign('user', $user);

        return $tpl->fetch();
    }
}
PHP);
        $this->write('src/Controller/AdminController.php', <<<'PHP'
<?php

namespace App\Controller;

use App\Model\Admin;

final class AdminController
{
    public function show($smarty): void
    {
        // The receiver is untyped, but the template name is a Smarty template.
        $smarty->assign('user', new Admin());
        $smarty->display('user/profile.tpl');
        $smarty->display('missing.tpl');
    }
}
PHP);
        $this->write('src/View/SmartyFactory.php', <<<'PHP'
<?php

namespace App\View;

use App\Support\Money;
use Smarty\Smarty;

final class SmartyFactory
{
    public function create(): Smarty
    {
        $smarty = new Smarty();
        $smarty->registerPlugin('modifier', 'money', [Money::class, 'format']);
        $smarty->registerPlugin(Smarty::PLUGIN_MODIFIER, 'ignored', 'strtoupper');
        $smarty->registerPlugin('function', 'icon', $this->icon(...));
        $smarty->registerPlugin('modifier', 'shout', fn (string $value): string => strtoupper($value));
        $smarty->registerClass('Str', \App\Support\Str::class);
        $smarty->addExtension(new \App\View\AppExtension());

        return $smarty;
    }

    public function icon(array $params): string
    {
        return '';
    }
}
PHP);
        $this->write('templates/user/profile.tpl', <<<'TPL'
{* {$user->getEmail()} is commented out *}
<h1>{$user->getName()|escape}</h1>
<p>{$user.name}</p>
<p>{$price|money}</p>
{icon name="star"}
<a href="/u/{Str::slug($user->getName())}">x</a>
{App\Support\Clock::now()}
{include file="../partials/card.tpl" owner=$user}
{include file=$dynamic}
{$order->getTotal()}
TPL);
        $this->write('templates/partials/card.tpl', "<b>{\$owner->getEmail()}</b> #{\$user->getId()}\n");
        $this->write('templates/mail.tpl', "Hello {\$user->getName()}\n");
        $this->write('templates_c/compiled.tpl', "{\$user->getName()}\n");
        $this->write('vendor/pkg/vendor.tpl', "{\$user->getName()}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testRenderSitesKeepOnlySmartyReceiversOrSmartyTemplateNames(): void
    {
        $sites = [];
        foreach ($this->report()->sites as $site) {
            $sites[] = sprintf('%s:%d %s(%s)%s', $site->path, $site->line, $site->method, $site->template ?? '<dynamic>', $site->receiverProven ? '' : ' unproven');
        }

        self::assertSame([
            'src/Controller/AdminController.php:13 display(user/profile.tpl) unproven',
            'src/Controller/AdminController.php:14 display(missing.tpl) unproven',
            'src/Controller/ProfileController.php:18 display(user/profile.tpl)',
            'src/Controller/ProfileController.php:21 display(<dynamic>)',
            'src/Controller/ProfileController.php:29 fetch(mail.tpl)',
        ], $sites);
    }

    public function testAssignedVariablesCarryOnlyStaticallyClearTypes(): void
    {
        $site = $this->report()->sites[2];

        self::assertSame(['type' => 'App\\Model\\User', 'expression' => '$user'], ['type' => $site->variables['user']['type'], 'expression' => $site->variables['user']['expression']]);
        self::assertNull($site->variables['price']['type']);
        self::assertNull($site->variables['order']['type'], 'a method call result needs PHPStan, not a guess');
    }

    public function testMemberAccessesBecomeTypedReferencesThroughRenderSitesAndIncludes(): void
    {
        $report = $this->report();

        self::assertSame([
            'templates/user/profile.tpl:2:render_context_typed:App\\Model\\User::getName:ambiguous',
            'templates/user/profile.tpl:6:render_context_typed:App\\Model\\User::getName:ambiguous',
        ], $this->summary($report->referencesTo('App\\Model\\User', 'getName'), 'templates/user/profile.tpl'));
        self::assertSame(
            ['templates/mail.tpl:1:render_context_typed:App\\Model\\User::getName'],
            $this->summary($report->referencesTo('app\\model\\user', 'GETNAME'), 'templates/mail.tpl'),
            'createTemplate() sites and case-insensitive lookups work',
        );
        // `owner=$user` renames the variable for the include; `$user` is inherited as well.
        self::assertSame(['templates/partials/card.tpl:1:render_context_typed:App\\Model\\User::getEmail:ambiguous'], $this->summary($report->referencesTo('App\\Model\\User', 'getEmail')));
        self::assertSame(['templates/partials/card.tpl:1:render_context_typed:App\\Model\\Admin::getId:ambiguous'], $this->summary($report->referencesTo('App\\Model\\Admin', 'getId')));
        self::assertSame([], array_filter(
            $report->references,
            static fn (SmartyTypedReference $reference): bool => $reference->path === 'templates/user/profile.tpl' && $reference->line === 1,
        ), 'a commented-out access is never a reference');
    }

    public function testEvidenceNamesTheRenderSites(): void
    {
        $reference = $this->report()->referencesTo('App\\Model\\Admin', 'getName')[0];

        self::assertSame(['src/Controller/AdminController.php:13'], $reference->evidence);
        self::assertSame('$user->getName', $reference->text);
        self::assertTrue($reference->ambiguous, 'profile.tpl is rendered with a User and with an Admin');
    }

    public function testRegisteredPluginsAndStaticReferencesResolveToPhp(): void
    {
        $report = $this->report();

        self::assertSame(['templates/user/profile.tpl:4:registered_plugin:App\\Support\\Money::format'], $this->summary($report->referencesTo('App\\Support\\Money', 'format')));
        self::assertSame(['templates/user/profile.tpl:5:registered_plugin:App\\View\\SmartyFactory::icon'], $this->summary($report->referencesTo('App\\View\\SmartyFactory', 'icon')));
        self::assertSame(['templates/user/profile.tpl:6:static_reference:App\\Support\\Str::slug'], $this->summary($report->referencesTo('App\\Support\\Str', 'slug')), 'registerClass() aliases resolve');
        self::assertSame(['src/View/SmartyFactory.php:17'], $report->referencesTo('App\\Support\\Str', 'slug')[0]->evidence);
        self::assertSame(['templates/user/profile.tpl:7:static_reference:App\\Support\\Clock::now'], $this->summary($report->referencesTo('App\\Support\\Clock')));
        self::assertSame([], $report->referencesTo('strtoupper'), '`escape` and `ignored` are not used as registered plugins here');
    }

    public function testEverythingUnresolvableIsAGapNotAnAbsence(): void
    {
        $gaps = array_map(static fn (array $gap): string => $gap['kind'] . ' ' . $gap['path'] . ':' . $gap['line'] . ' ' . $gap['detail'], $this->report()->gaps);

        self::assertSame([
            'unknown_template src/Controller/AdminController.php:14 missing.tpl',
            'dynamic_render src/Controller/ProfileController.php:21 display() with a computed template name',
            'unresolved_registration src/View/SmartyFactory.php:16 plugin shout',
            'dynamic_include templates/user/profile.tpl:9 include file=$dynamic',
            'untyped_variable templates/user/profile.tpl:10 $order->getTotal',
        ], $gaps);
    }

    public function testVendorAndCompileDirectoriesAreNotTemplatesOfTheProject(): void
    {
        self::assertSame(['templates/mail.tpl', 'templates/partials/card.tpl', 'templates/user/profile.tpl'], $this->report()->templates);
    }

    public function testTemplateDirsRestrictNameResolution(): void
    {
        $this->write('other/user/profile.tpl', "{\$user->getName()}\n");

        $loose = (new SmartyEvidenceBuilder())->build($this->root);
        $strict = (new SmartyEvidenceBuilder(templateDirs: ['templates']))->build($this->root);

        self::assertContains('other/user/profile.tpl', array_map(static fn (SmartyTypedReference $reference): string => $reference->path, $loose->referencesTo('App\\Model\\User', 'getName')));
        self::assertNotContains('other/user/profile.tpl', array_map(static fn (SmartyTypedReference $reference): string => $reference->path, $strict->referencesTo('App\\Model\\User', 'getName')));
    }

    public function testCustomDelimitersFlowThroughTheWholePipeline(): void
    {
        $this->write('templates/mail.tpl', "<{\$user->getName()}> {\$notATag->getName()}\n");

        $report = (new SmartyEvidenceBuilder(new SmartyDialect('<{', '}>')))->build($this->root);

        self::assertSame(['templates/mail.tpl:1:render_context_typed:App\\Model\\User::getName'], $this->summary($report->referencesTo('App\\Model\\User', 'getName'), 'templates/mail.tpl'));
    }

    public function testTheReportSerializesWithAnExperimentalSchemaMarker(): void
    {
        $array = $this->report()->toArray();

        self::assertSame('agent-map-smarty-evidence@experimental', $array['schema']);
        self::assertSame(json_decode((string) json_encode($array), true), $array);
    }

    public function testAnalyzerFactsHaveExactByteRanges(): void
    {
        $source = (string) file_get_contents($this->root . '/templates/user/profile.tpl');
        $facts = (new SmartyTemplateAnalyzer())->analyze('templates/user/profile.tpl', $source);

        self::assertNotSame([], $facts);
        foreach ($facts as $fact) {
            self::assertSame($fact->text, substr($source, $fact->startFilePos, $fact->endFilePos - $fact->startFilePos + 1), $fact->kind);
        }
        $includes = array_values(array_filter($facts, static fn (TemplateFact $fact): bool => $fact->kind === TemplateFact::KIND_INCLUDE));
        self::assertSame('../partials/card.tpl', $includes[0]->target);
        self::assertSame(['owner' => 'user'], $includes[0]->arguments);
        self::assertTrue($includes[1]->dynamic);
    }

    public function testAnalyzerReadsPositionalIncludesQuotedTextAndSmartyVariables(): void
    {
        $facts = (new SmartyTemplateAnalyzer())->analyze('t.tpl', <<<'TPL'
{extends 'layout.tpl'}
{include "file:box.tpl" nocache}
{$smarty.const.X} {'$fake->call()'} {"Hi {$real->call()}"|upper} {if $a || $b}{/if}
TPL);
        $summary = array_map(static fn (TemplateFact $fact): string => $fact->kind . ':' . ($fact->target ?? '-') . ($fact->member === null ? '' : '->' . $fact->member), $facts);

        self::assertContains('extends:layout.tpl', $summary);
        self::assertContains('include:box.tpl', $summary);
        self::assertContains('variable_member:real->call', $summary);
        self::assertContains('modifier:upper', $summary);
        self::assertNotContains('variable_member:fake->call', $summary);
        self::assertNotContains('variable_member:smarty->const', $summary);
        self::assertSame(1, count(array_filter($summary, static fn (string $line): bool => str_starts_with($line, 'modifier:'))), '`||` is not a modifier');
    }

    public function testExtractorIgnoresUnparsablePhp(): void
    {
        self::assertSame(['sites' => [], 'registrations' => []], (new SmartyRenderSiteExtractor())->extract('broken.php', "<?php\nfunction {\n"));
    }

    private function report(): SmartyEvidenceReport
    {
        return (new SmartyEvidenceBuilder())->build($this->root);
    }

    /**
     * @param list<SmartyTypedReference> $references
     * @return list<string>
     */
    private function summary(array $references, ?string $path = null): array
    {
        $lines = [];
        foreach ($references as $reference) {
            if ($path === null || $reference->path === $path) {
                $lines[] = sprintf('%s:%d:%s:%s%s', $reference->path, $reference->line, $reference->confidence, $reference->symbol(), $reference->ambiguous ? ':ambiguous' : '');
            }
        }

        return $lines;
    }

    private function write(string $relative, string $content): void
    {
        if (!is_dir(dirname($this->root . '/' . $relative))) {
            mkdir(dirname($this->root . '/' . $relative), 0o775, true);
        }
        file_put_contents($this->root . '/' . $relative, $content);
    }
}
