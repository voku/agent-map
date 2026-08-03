<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Inspect\ScopeSelector;

final class ScopeSelectorTest extends TestCase
{
    use ScopeFixture;

    protected function setUp(): void
    {
        $this->setUpScopeFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownScopeFixture();
    }

    public function testExactMethodSelectionByFullyQualifiedName(): void
    {
        $index = $this->buildScopeIndex();

        $selection = (new ScopeSelector())->select($index, 'Demo\Service\UserService::save');

        self::assertSame('found', $selection->status);
        self::assertNotNull($selection->target);
        self::assertSame('method', $selection->target->kind);
        self::assertSame('Demo\Service\UserService::save', $selection->target->label);
        self::assertSame('src/Service/UserService.php', $selection->target->file);
        self::assertGreaterThan(0, $selection->target->lineStart);
        self::assertGreaterThanOrEqual($selection->target->lineStart, $selection->target->lineEnd);
    }

    public function testAmbiguousMethodSelectionReportsCandidates(): void
    {
        $index = $this->buildScopeIndex();

        $selection = (new ScopeSelector())->select($index, 'UserService::save');

        self::assertSame('ambiguous', $selection->status);
        self::assertNull($selection->target);
        self::assertCount(2, $selection->candidates);
        self::assertStringContainsString('Demo\Service\UserService', implode(' ', $selection->candidates));
        self::assertStringContainsString('Demo\Other\UserService', implode(' ', $selection->candidates));
    }

    public function testFunctionSelection(): void
    {
        $index = $this->buildScopeIndex();

        $selection = (new ScopeSelector())->select($index, 'normalize_email');

        self::assertSame('found', $selection->status);
        self::assertNotNull($selection->target);
        self::assertSame('function', $selection->target->kind);
        self::assertSame('normalize_email', $selection->target->label);
        self::assertSame('src/functions.php', $selection->target->file);
    }

    public function testUnknownTermIsNotFound(): void
    {
        $index = $this->buildScopeIndex();

        $selection = (new ScopeSelector())->select($index, 'NoSuchClass::noSuchMethod');

        self::assertSame('not_found', $selection->status);
        self::assertNull($selection->target);
    }
}
