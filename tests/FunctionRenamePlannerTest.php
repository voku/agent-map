<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Cli\FunctionRenameCliApplication;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Rename\FunctionRenamePlan;
use voku\AgentMap\Rename\FunctionRenamePlanner;
use voku\AgentMap\Plan\PlanEdit;

/**
 * Function-call context is adapted from Rector's
 * rules-tests/Renaming/Rector/FuncCall/RenameFunctionRector/Fixture/in_for.php.inc
 * @83496b5d65035792e7b8eea3bb083e8f3b16bec0, while the expected safety behavior is
 * agent-map specific: semantic identity and exact source evidence are mandatory.
 *
 * Copyright (c) 2017-present Tomáš Votruba.
 * Licensed under the MIT License; see THIRD_PARTY_NOTICES.md.
 */
final class FunctionRenamePlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-function-rename-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPlansDeclarationAndCallInsideForExpression(): void
    {
        $this->requirePhpStan();
        $this->writeFixture(false, false, false);
        $map = $this->semanticMap();

        self::assertNotSame([], $map->incoming('function:FunctionRename\\old_size', 'calls'));
        $plan = (new FunctionRenamePlanner())->plan($map, 'FunctionRename\\old_size', 'new_size');

        self::assertSame(FunctionRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame(['call', 'declaration'], $this->roles($plan));
        self::assertSame(['old_size', 'old_size'], $this->expected($plan));
        self::assertSame('1.0', $plan::CONTRACT_VERSION);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/', $plan->provenance->mapDigest);
    }

    public function testDynamicFunctionCallRequiresReview(): void
    {
        $this->requirePhpStan();
        $this->writeFixture(true, false, false);
        $plan = (new FunctionRenamePlanner())->plan($this->semanticMap(), 'FunctionRename\\old_size', 'new_size');

        self::assertSame(FunctionRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertSame(['dynamic_function_name'], array_map(static fn ($blindSpot): string => $blindSpot->kind, $plan->blindSpots));
        self::assertCount(2, $plan->edits);
    }

    public function testQualifiedCallFailsClosedInsteadOfGuessingNamespaceEdits(): void
    {
        $this->requirePhpStan();
        $this->writeFixture(false, true, false);
        $plan = (new FunctionRenamePlanner())->plan($this->semanticMap(), 'FunctionRename\\old_size', 'new_size');

        self::assertSame(FunctionRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('exactly one unqualified', implode("\n", $plan->blockers));
    }

    public function testReplacementCollisionBlocksWithoutPublishingEdits(): void
    {
        $this->requirePhpStan();
        $this->writeFixture(false, false, true);
        $plan = (new FunctionRenamePlanner())->plan($this->semanticMap(), 'FunctionRename\\old_size', 'new_size');

        self::assertSame(FunctionRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('collides with indexed function', implode("\n", $plan->blockers));
    }

    public function testStructuralOnlyMapCannotClaimFunctionRenameSafety(): void
    {
        $this->writeFixture(false, false, false);
        $map = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src'], []);
        $plan = (new FunctionRenamePlanner())->plan($map, 'FunctionRename\\old_size', 'new_size');

        self::assertSame(FunctionRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('PHPStan-backed map', implode("\n", $plan->blockers));
    }

    public function testStaleEvidenceIsDistinctFromSemanticBlockers(): void
    {
        $this->requirePhpStan();
        $this->writeFixture(false, false, false);
        $map = $this->semanticMap();
        file_put_contents($this->root . '/src/Functions.php', "\n// changed after map\n", FILE_APPEND);

        $plan = (new FunctionRenamePlanner())->plan($map, 'FunctionRename\\old_size', 'new_size');

        self::assertSame(FunctionRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->blockers);
        self::assertCount(1, $plan->staleEvidence);
        self::assertSame('hash', $plan->staleEvidence[0]->reason);
    }

    public function testCliPublishesVersionedPlanWithoutMutation(): void
    {
        $this->requirePhpStan();
        $this->writeFixture(false, false, false);
        $map = $this->semanticMap();
        $index = $this->root . '/map.json';
        (new IndexWriter())->write($map, $index);
        $before = file_get_contents($this->root . '/src/Functions.php');

        ob_start();
        $exit = (new FunctionRenameCliApplication())->run([
            'agent-map',
            'function-rename-plan',
            'FunctionRename\\old_size',
            'new_size',
            '--index=' . $index,
            '--format=json',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('function_rename_plan', $data['type']);
        self::assertSame('1.0', $data['contract_version']);
        self::assertSame('safe', $data['status']);
        self::assertSame($before, file_get_contents($this->root . '/src/Functions.php'));
    }

    /** @return list<string> */
    private function roles(FunctionRenamePlan $plan): array
    {
        $roles = array_map(static fn (PlanEdit $edit): string => $edit->role, $plan->edits);
        sort($roles, SORT_STRING);

        return $roles;
    }

    /** @return list<string> */
    private function expected(FunctionRenamePlan $plan): array
    {
        return array_map(static fn (PlanEdit $edit): string => $edit->expected, $plan->edits);
    }

    private function semanticMap(): AgentMapIndex
    {
        return (new AgentMapBuilder())->build($this->root, ['src'], []);
    }

    private function requirePhpStan(): void
    {
        if (!PhpStanSemanticAnalyzer::isAvailable()) {
            self::markTestSkipped('Function rename semantic tests require the optional phpstan/phpstan capability.');
        }
    }

    private function writeFixture(bool $dynamic, bool $qualified, bool $collision): void
    {
        $collisionFunction = $collision ? <<<'PHP'

function new_size(array $files): int
{
    return count($files);
}
PHP : '';
        file_put_contents($this->root . '/src/Functions.php', <<<PHP
<?php

declare(strict_types=1);

namespace FunctionRename;

function old_size(array \$files): int
{
    return count(\$files);
}
{$collisionFunction}
PHP);

        $call = $qualified ? '\\FunctionRename\\old_size($files)' : 'old_size($files)';
        $dynamicCall = $dynamic ? <<<'PHP'

        $callable = 'FunctionRename\\old_size';
        $callable($files);
PHP : '';
        file_put_contents($this->root . '/src/Caller.php', <<<PHP
<?php

declare(strict_types=1);

namespace FunctionRename;

final class Caller
{
    public function run(array \$files): void
    {
        for (\$i = 0, \$n = {$call}; \$i < \$n; ++\$i) {
        }{$dynamicCall}
    }
}
PHP);
    }
}
