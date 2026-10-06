<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Reference\NonPhpReference;
use voku\AgentMap\Reference\NonPhpReferenceScanner;
use voku\AgentMap\Reference\ReferenceTarget;

final class NonPhpReferenceScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-nonphp-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/docs', 0o775, true);
        mkdir($this->root . '/templates', 0o775, true);
        mkdir($this->root . '/vendor/pkg', 0o775, true);
        file_put_contents($this->root . '/README.md', <<<'MD'
# Demo

Use `\Demo\Service` or `Service::oldName()` here.

The Service prose mention is not code and stays out.

```php
$service->oldName();
new Service();
```

Call `oldName()` on it.
MD);
        file_put_contents($this->root . '/CHANGELOG.md', "- renamed `Demo\\\\Service::oldName`\n");
        file_put_contents($this->root . '/docs/guide.md', "No mention of anything here.\n");
        file_put_contents($this->root . '/templates/page.html.twig', <<<'TWIG'
<p>oldName in plain html is not a reference</p>
{{ service.oldName() }} {# @var \Demo\Service service #}
{{ user.name }}
TWIG);
        file_put_contents($this->root . '/templates/page.tpl', "<b>{\$service->oldName()}</b>\n");
        file_put_contents($this->root . '/vendor/pkg/README.md', "`Service::oldName()`\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testAClassIsFoundByFqcnAnywhereAndByShortNameOnlyInsideCode(): void
    {
        $report = (new NonPhpReferenceScanner())->scan($this->root, ReferenceTarget::classLike('Demo\\Service'));
        $found = $this->summary($report->references);

        self::assertContains('README.md:3:exact_fqcn:\\Demo\\Service', $found);
        self::assertContains('README.md:3:code_name:Service', $found);
        self::assertContains('README.md:9:code_name:Service', $found);
        self::assertContains('CHANGELOG.md:1:exact_fqcn:Demo\\\\Service', $found);
        self::assertContains('templates/page.html.twig:2:exact_fqcn:\\Demo\\Service', $found);
        foreach ($found as $entry) {
            self::assertStringNotContainsString('README.md:5', $entry, 'prose is not a reference');
            self::assertStringNotContainsString('vendor/', $entry);
        }
    }

    public function testAMethodIsFoundQualifiedAndByNameOnlyAndTheByteRangeIsExact(): void
    {
        $report = (new NonPhpReferenceScanner())->scan($this->root, ReferenceTarget::method('Demo\\Service', 'oldName'));
        $found = $this->summary($report->references);

        self::assertContains('README.md:3:class_member_qualified:Service::oldName', $found);
        self::assertContains('README.md:8:member_name_only:->oldName', $found);
        self::assertContains('README.md:12:member_name_only:oldName(', $found);
        self::assertContains('templates/page.html.twig:2:member_name_only:.oldName', $found);
        self::assertContains('templates/page.tpl:1:member_name_only:->oldName', $found);
        self::assertNotContains('templates/page.html.twig:1:member_name_only:oldName', $found, 'plain html outside a tag is not a template reference');

        foreach ($report->references as $reference) {
            $source = (string) file_get_contents($this->root . '/' . $reference->path);
            self::assertSame($reference->matched, substr($source, $reference->startFilePos, $reference->endFilePos - $reference->startFilePos + 1));
        }
    }

    public function testAGetterIsAlsoFoundThroughTheTemplateAttribute(): void
    {
        $report = (new NonPhpReferenceScanner())->scan($this->root, ReferenceTarget::method('Demo\\User', 'getName'));

        self::assertContains('templates/page.html.twig:3:member_name_only:.name', $this->summary($report->references));
    }

    public function testHistoricalFilesAreMarkedAndNothingIsFoundWhereNothingIsMentioned(): void
    {
        $report = (new NonPhpReferenceScanner())->scan($this->root, ReferenceTarget::method('Demo\\Service', 'oldName'));
        $byPath = [];
        foreach ($report->references as $reference) {
            $byPath[$reference->path] = $reference->historical;
        }

        self::assertTrue($byPath['CHANGELOG.md']);
        self::assertFalse($byPath['README.md']);
        self::assertArrayNotHasKey('docs/guide.md', $byPath);
        self::assertTrue((new NonPhpReferenceScanner())->scan($this->root, ReferenceTarget::classLike('Nobody\\Home'))->isEmpty());
    }

    public function testTheReportIsBoundedAndSaysSo(): void
    {
        $report = (new NonPhpReferenceScanner(2))->scan($this->root, ReferenceTarget::method('Demo\\Service', 'oldName'));

        self::assertCount(2, $report->references);
        self::assertGreaterThan(2, $report->total);
        self::assertTrue($report->toArray()['truncated']);
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
