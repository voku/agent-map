<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Cli\ClassRenameCliApplication;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Rename\ClassRenamePlan;
use voku\AgentMap\Rename\ClassRenamePlanner;
use voku\AgentMap\Rename\RenameBlindSpot;
use voku\AgentMap\Rename\RenameEdit;

/**
 * Class-name/import behavior is exercised against the same surfaces covered by Rector's
 * rules-tests/Renaming/Rector/Name/RenameClassRector @83496b5d65035792e7b8eea3bb083e8f3b16bec0,
 * while agent-map keeps its stricter exact-evidence and read-only contract.
 *
 * Copyright (c) 2017-present Tomáš Votruba.
 * Licensed under the MIT License; see THIRD_PARTY_NOTICES.md.
 */
final class GovernedClassRenamePlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-governed-class-rename-' . bin2hex(random_bytes(6));
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

    public function testPlansDeclarationImportsStaticReferencesAndConventionalMove(): void
    {
        $this->writeBaseFixture();
        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('class:Demo\\OldClass', $plan->targetId);
        self::assertSame('Demo\\NewClass', $plan->replacementFqn);
        self::assertCount(7, $plan->edits);
        self::assertCount(1, $plan->moves);
        self::assertSame('src/OldClass.php', $plan->moves[0]->fromPath);
        self::assertSame('src/NewClass.php', $plan->moves[0]->toPath);
        self::assertSame([], $plan->staleEvidence);
        self::assertMatchesRegularExpression('/\\Asha256:[a-f0-9]{64}\\z/', $plan->provenance->mapDigest);
    }

    public function testAliasedImportChangesOnlyImportIdentity(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/AliasConsumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\OldClass as Alias;
final class AliasConsumer
{
    public function make(Alias $input): Alias
    {
        return new Alias();
    }
}
PHP);

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        $roles = array_map(static fn (RenameEdit $edit): string => $edit->role, $plan->edits);
        sort($roles, SORT_STRING);
        self::assertSame(['class_declaration', 'class_import'], $roles);
    }

    public function testPhpDocAndClassStringBecomeReviewEvidence(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/ReviewConsumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\OldClass;
final class ReviewConsumer
{
    /** @return OldClass */
    public function make(): OldClass
    {
        $class = 'Demo\\OldClass';
        return new OldClass();
    }
}
PHP);

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        $kinds = array_map(static fn (RenameBlindSpot $blindSpot): string => $blindSpot->kind, $plan->blindSpots);
        self::assertContains('phpdoc_type_reference', $kinds);
        self::assertContains('class_string_literal', $kinds);
        self::assertNotSame([], $plan->edits);
    }

    public function testStaleEvidenceIsDistinctFromSemanticBlockers(): void
    {
        $this->writeBaseFixture();
        $map = $this->map();
        file_put_contents($this->root . '/src/Consumer.php', "\n// changed after map\n", FILE_APPEND);

        $plan = (new ClassRenamePlanner())->plan($map, 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertSame([], $plan->blockers);
        self::assertCount(1, $plan->staleEvidence);
        self::assertSame('hash', $plan->staleEvidence[0]->reason);
    }

    public function testReplacementCollisionBlocksWithoutPublishingEditsOrMoves(): void
    {
        $this->writeBaseFixture();
        file_put_contents($this->root . '/src/NewClass.php', "<?php\nnamespace Demo;\nfinal class NewClass {}\n");

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('collides with indexed class', implode("\n", $plan->blockers));
    }

    public function testCliPublishesVersionedPlanWithoutMutation(): void
    {
        $this->writeBaseFixture();
        $index = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $index);
        $before = file_get_contents($this->root . '/src/OldClass.php');

        ob_start();
        $exit = (new ClassRenameCliApplication())->run([
            'agent-map',
            'class-rename-plan',
            'Demo\\OldClass',
            'NewClass',
            '--index=' . $index,
            '--format=json',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('class_rename_plan', $payload['type']);
        self::assertSame('1.0', $payload['contract_version']);
        self::assertSame('safe', $payload['status']);
        self::assertSame($before, file_get_contents($this->root . '/src/OldClass.php'));
        self::assertFileDoesNotExist($this->root . '/src/NewClass.php');
    }

    private function writeBaseFixture(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/Consumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\OldClass;
final class Consumer
{
    public function make(OldClass $input): OldClass
    {
        $copy = new OldClass();
        if ($copy instanceof OldClass) {
            return $copy;
        }
        return $input;
    }

    public function className(): string
    {
        return \Demo\OldClass::class;
    }
}
PHP);
    }

    private function writeClass(): void
    {
        file_put_contents($this->root . '/src/OldClass.php', "<?php\nnamespace Demo;\nfinal class OldClass {}\n");
    }

    private function map(): AgentMapIndex
    {
        return (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build(
            root: $this->root,
            paths: ['src'],
            excludes: [],
        );
    }
}
