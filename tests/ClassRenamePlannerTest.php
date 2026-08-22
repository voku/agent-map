<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
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
use voku\SimplePhpParser\Parsers\PhpCodeParser;

final class ClassRenamePlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-class-rename-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testStructuralMapPlansDeclarationStaticReferencesAndFileMove(): void
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
        self::assertSame([], $plan->blindSpots);
        self::assertSame([], $plan->blockers);

        $rewritten = $this->applyEdits($plan->edits);
        foreach ($rewritten as $source) {
            self::assertNotSame([], PhpCodeParser::getAstFromString($source));
        }
        self::assertStringContainsString('class NewClass', $rewritten['src/OldClass.php']);
        self::assertStringContainsString('use Demo\\NewClass;', $rewritten['src/Consumer.php']);
        self::assertStringContainsString('new NewClass()', $rewritten['src/Consumer.php']);
        self::assertStringContainsString('\\Demo\\NewClass::class', $rewritten['src/Consumer.php']);
    }

    public function testAliasedImportChangesImportButLeavesAliasReferencesAlone(): void
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
        self::assertCount(2, $plan->edits);
        $roles = array_map(static fn (RenameEdit $edit): string => $edit->role, $plan->edits);
        sort($roles, SORT_STRING);
        self::assertSame(['class_declaration', 'class_import'], $roles);
        $rewritten = $this->applyEdits($plan->edits);
        self::assertStringContainsString('use Demo\\NewClass as Alias;', $rewritten['src/AliasConsumer.php']);
        self::assertStringContainsString('new Alias()', $rewritten['src/AliasConsumer.php']);
    }

    public function testGroupedImportIsRenamedWithoutTouchingSiblingImport(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/GroupedConsumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\{OldClass, Something};
final class GroupedConsumer
{
    public function make(OldClass $input): OldClass
    {
        return new OldClass();
    }
}
PHP);

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        $rewritten = $this->applyEdits($plan->edits);
        self::assertStringContainsString('use Demo\\{NewClass, Something};', $rewritten['src/GroupedConsumer.php']);
        self::assertStringContainsString('make(NewClass $input): NewClass', $rewritten['src/GroupedConsumer.php']);
    }

    public function testMixedGroupedImportTreatsUnprefixedItemAsClassImport(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/MixedGroupedConsumer.php', <<<'PHP'
<?php
namespace Client;
use Demo\{OldClass, function helper};
final class MixedGroupedConsumer
{
    public function make(): OldClass
    {
        return new OldClass();
    }
}
PHP);

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        $rewritten = $this->applyEdits($plan->edits);
        self::assertStringContainsString('use Demo\\{NewClass, function helper};', $rewritten['src/MixedGroupedConsumer.php']);
    }

    public function testPhpDocAndClassStringBecomeReviewEvidenceWithoutDroppingExactEdits(): void
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
        self::assertNotSame([], $plan->edits);
        $kinds = array_map(static fn (RenameBlindSpot $blindSpot): string => $blindSpot->kind, $plan->blindSpots);
        self::assertContains('phpdoc_type_reference', $kinds);
        self::assertContains('class_string_literal', $kinds);
        self::assertSame([], $plan->blockers);
    }

    public function testReplacementTypeCollisionBlocksAndPublishesNoMutationPlan(): void
    {
        $this->writeBaseFixture();
        file_put_contents($this->root . '/src/NewClass.php', "<?php\nnamespace Demo;\nfinal class NewClass {}\n");

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('collides with indexed class Demo\\NewClass', implode("\n", $plan->blockers));
    }

    public function testExistingDestinationFileBlocksEvenWithoutTypeCollision(): void
    {
        $this->writeBaseFixture();
        file_put_contents($this->root . '/src/NewClass.php', "<?php\nnamespace Demo;\nfunction unrelated(): void {}\n");

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('Replacement class path already exists', implode("\n", $plan->blockers));
    }

    public function testExistingDestinationDirectoryAlsoBlocks(): void
    {
        $this->writeBaseFixture();
        $map = $this->map();
        mkdir($this->root . '/src/NewClass.php');

        $plan = (new ClassRenamePlanner())->plan($map, 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('Replacement class path already exists', implode("\n", $plan->blockers));
    }

    public function testOtherDeclarationInConventionalClassFileRequiresMoveReview(): void
    {
        file_put_contents($this->root . '/src/OldClass.php', <<<'PHP'
<?php
namespace Demo;
final class OldClass {}
function relatedHelper(): void {}
PHP);

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertCount(1, $plan->moves);
        self::assertContains('multi_symbol_file_move', array_map(static fn (RenameBlindSpot $spot): string => $spot->kind, $plan->blindSpots));
    }

    public function testUnconventionalClassFilenameRequiresReviewInsteadOfInventingMove(): void
    {
        file_put_contents($this->root . '/src/Legacy.php', "<?php\nnamespace Demo;\nfinal class OldClass {}\n");
        file_put_contents($this->root . '/src/Consumer.php', "<?php\nnamespace Client;\nuse Demo\\OldClass;\nfinal class Consumer { public function make(): OldClass { return new OldClass(); } }\n");

        $plan = (new ClassRenamePlanner())->plan($this->map(), 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertSame([], $plan->moves);
        self::assertSame('autoload_path', $plan->blindSpots[0]->kind);
    }

    public function testStaleMapBlocksBeforeAnyEditIsPublished(): void
    {
        $this->writeBaseFixture();
        $map = $this->map();
        file_put_contents($this->root . '/src/Consumer.php', "<?php\nnamespace Client;\n// changed after indexing\n");

        $plan = (new ClassRenamePlanner())->plan($map, 'Demo\\OldClass', 'NewClass');

        self::assertSame(ClassRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertCount(1, $plan->staleEvidence);
        self::assertSame('src/Consumer.php', $plan->staleEvidence[0]->path);
        self::assertSame([], $plan->blockers);
    }

    public function testNamespaceMoveAndCaseOnlyRenameAreRejected(): void
    {
        $this->writeBaseFixture();
        $planner = new ClassRenamePlanner();
        $map = $this->map();

        try {
            $planner->plan($map, 'Demo\\OldClass', 'Other\\NewClass');
            self::fail('Namespace move must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('same namespace only', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $planner->plan($map, 'Demo\\OldClass', 'OLDCLASS');
    }

    public function testCliPublishesJsonPlanWithoutMutatingSource(): void
    {
        $this->writeBaseFixture();
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $mapPath, 'json');
        $before = (string) file_get_contents($this->root . '/src/OldClass.php');

        ob_start();
        $status = (new ClassRenameCliApplication())->run([
            'agent-map',
            'class-rename-plan',
            'Demo\\OldClass',
            'NewClass',
            '--index',
            $mapPath,
            '--format',
            'json',
        ]);
        $output = ob_get_clean();

        self::assertSame(0, $status);
        self::assertIsString($output);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('safe', $payload['status'] ?? null);
        self::assertCount(1, $payload['moves'] ?? []);
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

    /**
     * @param list<RenameEdit> $edits
     * @return array<string, string>
     */
    private function applyEdits(array $edits): array
    {
        /** @var array<string, list<RenameEdit>> $byPath */
        $byPath = [];
        foreach ($edits as $edit) {
            $byPath[$edit->path][] = $edit;
        }

        $rewritten = [];
        foreach ($byPath as $path => $pathEdits) {
            $source = file_get_contents($this->root . '/' . $path);
            self::assertIsString($source);
            $preEditSha256 = 'sha256:' . hash('sha256', $source);
            foreach ($pathEdits as $edit) {
                self::assertSame($preEditSha256, $edit->sourceSha256);
            }

            usort($pathEdits, static fn (RenameEdit $left, RenameEdit $right): int => $right->startFilePos <=> $left->startFilePos);
            foreach ($pathEdits as $edit) {
                self::assertSame($edit->expected, substr($source, $edit->startFilePos, $edit->endFilePos - $edit->startFilePos + 1));
                $source = substr($source, 0, $edit->startFilePos)
                    . $edit->replacement
                    . substr($source, $edit->endFilePos + 1);
            }
            $rewritten[$path] = $source;
        }

        return $rewritten;
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
