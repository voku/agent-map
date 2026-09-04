<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Cli\PropertyRenameCliApplication;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Rename\PropertyRenamePlan;
use voku\AgentMap\Rename\PropertyRenamePlanner;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;

/**
 * Behavioral inventory cross-checked against Rector's RenamePropertyRector and focused fixtures:
 * - rectorphp/rector/rules/Renaming/Rector/PropertyFetch/RenamePropertyRector.php
 *   @29ac8eb5d206c9d62486c9e8ff018b27f94f34ce
 * - rectorphp/rector-src/rules-tests/Renaming/Rector/PropertyFetch/RenamePropertyRector/*
 *   @83496b5d65035792e7b8eea3bb083e8f3b16bec0
 *
 * The tests are independently written for agent-map's stricter evidence contract.
 * Copyright (c) 2017-present Tomáš Votruba.
 * Licensed under the MIT License; see docs/reference/third-party-notices.md.
 */
final class PropertyRenamePlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-property-rename-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPlansPrivateDeclarationAndResolvedAccessesExactly(): void
    {
        $this->write(<<<'PHP'
<?php
namespace Demo;
final class Box
{
    private string $name = 'initial';
    public function change(): string
    {
        $this->name = 'changed';
        return $this->name;
    }
}
PHP);

        $plan = (new PropertyRenamePlanner())->plan($this->map(), 'Demo\\Box::$name', 'label');

        self::assertSame(PropertyRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('property:Demo\\Box::$name', $plan->targetId);
        self::assertSame('Demo\\Box', $plan->ownerFqn);
        self::assertCount(3, $plan->edits);
        self::assertSame([], $plan->staleEvidence);
        foreach ($plan->edits as $edit) {
            self::assertSame('sha256:' . hash_file('sha256', $this->root . '/' . $edit->path), $edit->sourceSha256);
            self::assertSame('label', ltrim($edit->replacement, '$'));
        }
    }

    public function testPropertyIdentityAndTokensRemainCaseSensitive(): void
    {
        $this->write(<<<'PHP'
<?php
namespace Demo;
final class Box
{
    private string $name = 'lower';
    private string $Name = 'upper';

    public function read(): string
    {
        return $this->name . $this->Name;
    }
}
PHP);

        $plan = (new PropertyRenamePlanner())->plan($this->map(), 'Demo\\Box::$name', 'label');

        self::assertSame(PropertyRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('property:Demo\\Box::$name', $plan->targetId);
        self::assertCount(2, $plan->edits);
        foreach ($plan->edits as $edit) {
            self::assertSame('name', ltrim($edit->expected, '$'));
            self::assertNotSame('Name', ltrim($edit->expected, '$'));
        }
    }

    public function testPlansPrivateStaticPropertyAccess(): void
    {
        $this->write(<<<'PHP'
<?php
namespace Demo;
final class Box
{
    private static int $count = 0;
    public static function increment(): int
    {
        return ++self::$count;
    }
}
PHP);

        $plan = (new PropertyRenamePlanner())->plan($this->map(), 'Demo\\Box::$count', 'total');

        self::assertSame(PropertyRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(2, $plan->edits);
        self::assertSame(['property_access', 'property_declaration'], $this->roles($plan));
    }

    public function testDynamicAccessRequiresReviewInsteadOfGuessing(): void
    {
        $this->write(<<<'PHP'
<?php
namespace Demo;
final class Box
{
    private string $name = 'initial';
    public function read(): string
    {
        $field = 'name';
        return $this->{$field};
    }
}
PHP);

        $plan = (new PropertyRenamePlanner())->plan($this->map(), 'Demo\\Box::$name', 'label');

        self::assertSame(PropertyRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertContains('dynamic_property_name', array_map(static fn (PlanBlindSpot $spot): string => $spot->kind, $plan->blindSpots));
        self::assertNotSame([], $plan->edits);
    }

    public function testMagicDispatchRequiresReview(): void
    {
        $this->write(<<<'PHP'
<?php
namespace Demo;
final class Box
{
    private string $name = 'initial';
    public function __get(string $name): mixed { return null; }
    public function read(): string { return $this->name; }
}
PHP);

        $plan = (new PropertyRenamePlanner())->plan($this->map(), 'Demo\\Box::$name', 'label');

        self::assertSame(PropertyRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertContains('magic_property_dispatch', array_map(static fn (PlanBlindSpot $spot): string => $spot->kind, $plan->blindSpots));
    }

    public function testStructuralOnlyEvidenceBlocksWithZeroEdits(): void
    {
        $this->writePrivateFixture();
        $map = (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build($this->root, ['src'], []);

        $plan = (new PropertyRenamePlanner())->plan($map, 'Demo\\Box::$name', 'label');

        self::assertSame(PropertyRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('PHPStan-backed map', implode("\n", $plan->blockers));
    }

    public function testStaleSourceIsSeparateAndPublishesZeroEdits(): void
    {
        $this->writePrivateFixture();
        $map = $this->map();
        file_put_contents($this->root . '/src/Box.php', "\n// changed after planning evidence\n", FILE_APPEND);

        $plan = (new PropertyRenamePlanner())->plan($map, 'Demo\\Box::$name', 'label');

        self::assertSame(PropertyRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->blockers);
        self::assertCount(1, $plan->staleEvidence);
        self::assertSame('hash', $plan->staleEvidence[0]->reason);
    }

    /** @dataProvider unsupportedDeclarationProvider */
    public function testUnsupportedDeclarationSurfacesBlockWithZeroEdits(string $source, string $expected): void
    {
        $this->write($source);

        $plan = (new PropertyRenamePlanner())->plan($this->map(), 'Demo\\Box::$name', 'label');

        self::assertSame(PropertyRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString($expected, implode("\n", $plan->blockers));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupportedDeclarationProvider(): iterable
    {
        yield 'public inheritance surface' => [<<<'PHP'
<?php
namespace Demo;
class Box { public string $name = ''; }
PHP, 'private properties only'];

        yield 'promoted parameter surface' => [<<<'PHP'
<?php
namespace Demo;
final class Box { public function __construct(private string $name) {} }
PHP, 'Promoted property rename is blocked'];

        yield 'replacement collision' => [<<<'PHP'
<?php
namespace Demo;
final class Box { private string $name = ''; private string $label = ''; }
PHP, 'already exists'];
    }

    public function testCliPublishesVersionedPlanWithoutMutation(): void
    {
        $this->writePrivateFixture();
        $index = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $index);
        $before = file_get_contents($this->root . '/src/Box.php');

        ob_start();
        $exit = (new PropertyRenameCliApplication())->run([
            'agent-map',
            'property-rename-plan',
            'Demo\\Box::$name',
            'label',
            '--index=' . $index,
            '--format=json',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('property_rename_plan', $payload['type']);
        self::assertSame('1.0', $payload['contract_version']);
        self::assertSame('safe', $payload['status']);
        self::assertSame($before, file_get_contents($this->root . '/src/Box.php'));
    }

    private function writePrivateFixture(): void
    {
        $this->write(<<<'PHP'
<?php
namespace Demo;
final class Box
{
    private string $name = 'initial';
    public function read(): string { return $this->name; }
}
PHP);
    }

    private function write(string $source): void
    {
        file_put_contents($this->root . '/src/Box.php', $source . "\n");
    }

    private function map(): AgentMapIndex
    {
        return (new AgentMapBuilder())->build($this->root, ['src'], [], null, '512M');
    }

    /** @return list<string> */
    private function roles(PropertyRenamePlan $plan): array
    {
        $roles = array_map(static fn (PlanEdit $edit): string => $edit->role, $plan->edits);
        sort($roles, SORT_STRING);

        return $roles;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
