<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Inspect\CallHint;
use voku\AgentMap\Inspect\ScopeInspection;
use voku\AgentMap\Inspect\ScopeInspector;
use voku\AgentMap\Inspect\ScopeSelector;
use voku\AgentMap\Inspect\TableHint;
use voku\AgentMap\Inspect\TemplateHint;

final class ScopeInspectorTest extends TestCase
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

    public function testCallsInsideScopeAreClassifiedByKind(): void
    {
        $inspection = $this->inspectSave();

        $byLabel = $this->callsByLabel($inspection);

        self::assertSame('function', $byLabel['save_user']->kind);
        self::assertSame('method', $byLabel['$this->validate']->kind);
        self::assertSame('constructor', $byLabel['UserRepository']->kind);
        self::assertSame('method', $byLabel['$repository->store']->kind);
        self::assertSame('static', $byLabel['UserRepository::flush']->kind);
        self::assertSame('dynamic method call', $byLabel['dynamic method call']->label);
        self::assertSame('dynamic function call', $byLabel['dynamic function call']->label);
    }

    public function testCallsReuseResolvedSemanticRelationsFromTheMap(): void
    {
        $byLabel = $this->callsByLabel($this->inspectSave());

        self::assertSame(['function:save_user'], $byLabel['save_user']->targetIds);
        self::assertSame('phpstan_resolved', $byLabel['save_user']->resolution);
        self::assertSame(['method:Demo\\Service\\UserRepository::store'], $byLabel['$repository->store']->targetIds);
        self::assertSame('Demo\\Service\\UserRepository', $byLabel['$repository->store']->receiverType);
        self::assertSame(['class:Demo\\Service\\UserRepository'], $byLabel['UserRepository']->targetIds);
    }

    public function testCallsOutsideTargetRangeAreExcluded(): void
    {
        $inspection = $this->inspectSave();

        $labels = array_map(static fn (CallHint $call): string => $call->label, $inspection->calls);

        self::assertNotContains('outside_call', $labels);
    }

    public function testLiteralSqlTablesAreExtractedFromHeredocAndSingleLineStrings(): void
    {
        $inspection = $this->inspectSave();

        $tables = $this->tablesAsPairs($inspection);

        self::assertContains(['users', 'SELECT'], $tables);
        self::assertContains(['user_roles', 'SELECT'], $tables);
        self::assertContains(['users', 'UPDATE'], $tables);
        self::assertContains(['audit_log', 'INSERT'], $tables);
    }

    public function testDynamicSqlTableIsReportedWithoutResolvingTheVariable(): void
    {
        $inspection = $this->inspectSave();

        $tables = $this->tablesAsPairs($inspection);

        self::assertContains(['<dynamic table>', 'SELECT'], $tables);
    }

    public function testTemplateReferencesAreDetectedAcrossEngineStyles(): void
    {
        $inspection = $this->inspectSave();

        $paths = array_map(static fn (TemplateHint $template): string => $template->path, $inspection->templates);

        self::assertContains('user/saved.html.twig', $paths);
        self::assertContains('user/edit.tpl', $paths);
        self::assertContains('user.edit', $paths);
    }

    public function testLimitCapsEachSectionAndReportsOmittedCounts(): void
    {
        $index = $this->buildScopeIndex();
        $selection = (new ScopeSelector())->select($index, 'Demo\Service\UserService::save');
        self::assertNotNull($selection->target);

        $inspection = (new ScopeInspector())->inspect($index, $selection->target, 2);

        self::assertCount(2, $inspection->calls);
        self::assertGreaterThan(0, $inspection->callsOmitted);
        self::assertCount(2, $inspection->tables);
        self::assertGreaterThan(0, $inspection->tablesOmitted);
    }

    /**
     * @return array<string, CallHint>
     */
    private function callsByLabel(ScopeInspection $inspection): array
    {
        $byLabel = [];
        foreach ($inspection->calls as $call) {
            $byLabel[$call->label] = $call;
        }

        return $byLabel;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function tablesAsPairs(ScopeInspection $inspection): array
    {
        return array_map(static fn (TableHint $table): array => [$table->table, $table->action], $inspection->tables);
    }

    private function inspectSave(): ScopeInspection
    {
        $index = $this->buildScopeIndex();
        $selection = (new ScopeSelector())->select($index, 'Demo\Service\UserService::save');
        self::assertNotNull($selection->target);

        return (new ScopeInspector())->inspect($index, $selection->target, 20);
    }
}
