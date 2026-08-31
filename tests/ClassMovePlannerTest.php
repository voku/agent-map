<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Cli\ClassMoveCliApplication;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Move\ClassMovePlan;
use voku\AgentMap\Move\ClassMovePlanner;
use voku\AgentMap\Rename\ClassRenamePlanner;
use voku\AgentMap\Plan\PlanBlindSpot;
use voku\AgentMap\Plan\PlanEdit;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

final class ClassMovePlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-class-move-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Legacy', 0o775, true);
        mkdir($this->root . '/src/Client', 0o775, true);
        $this->writeComposer(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDeterministicRelocationPublishesNamespaceImportAndMoveEvidence(): void
    {
        $this->writeBaseFixture();

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame('class:App\\Legacy\\UserService', $plan->targetId);
        self::assertSame('App\\Legacy\\UserService', $plan->sourceFqn);
        self::assertSame('App\\Service\\UserService', $plan->destinationFqn);
        self::assertSame([], $plan->blindSpots);
        self::assertSame([], $plan->blockers);

        self::assertNotNull($plan->autoload);
        self::assertSame('composer.json', $plan->autoload->manifestPath);
        self::assertSame('App\\', $plan->autoload->destinationPrefix);
        self::assertSame('src', $plan->autoload->destinationDirectory);
        self::assertSame('src/Service/UserService.php', $plan->autoload->destinationPath);

        $roles = array_map(static fn (PlanEdit $edit): string => $edit->role, $plan->edits);
        sort($roles, SORT_STRING);
        self::assertSame(['class_import', 'class_reference', 'namespace_declaration'], $roles);

        self::assertCount(1, $plan->moves);
        self::assertSame('src/Legacy/UserService.php', $plan->moves[0]->fromPath);
        self::assertSame('src/Service/UserService.php', $plan->moves[0]->toPath);
        self::assertTrue($plan->moves[0]->destinationMustBeAbsent);

        $rewritten = $this->applyEdits($plan->edits);
        foreach ($rewritten as $source) {
            self::assertNotSame([], PhpCodeParser::getAstFromString($source));
        }
        self::assertStringContainsString('namespace App\\Service;', $rewritten['src/Legacy/UserService.php']);
        self::assertStringContainsString('use App\\Service\\UserService;', $rewritten['src/Client/Consumer.php']);
        self::assertStringContainsString('new UserService()', $rewritten['src/Client/Consumer.php']);
        self::assertStringContainsString('\\App\\Service\\UserService::class', $rewritten['src/Client/Consumer.php']);
    }

    public function testPlanningNeverTouchesTheSourceTree(): void
    {
        $this->writeBaseFixture();
        $map = $this->map();
        $before = $this->snapshot();

        $plan = (new ClassMovePlanner())->plan($map, 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        self::assertSame($before, $this->snapshot());
        self::assertFileDoesNotExist($this->root . '/src/Service/UserService.php');
    }

    public function testNamespaceRelativeReferenceIsPinnedAndFlaggedForReview(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/Legacy/Sibling.php', <<<'PHP'
<?php
namespace App\Legacy;
final class Sibling
{
    public function make(): UserService
    {
        return new UserService();
    }
}
PHP);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertContains('namespace_relative_reference', $this->blindSpotKinds($plan->blindSpots));

        $rewritten = $this->applyEdits($plan->edits);
        self::assertNotSame([], PhpCodeParser::getAstFromString($rewritten['src/Legacy/Sibling.php']));
        self::assertStringContainsString('): \\App\\Service\\UserService', $rewritten['src/Legacy/Sibling.php']);
        self::assertStringContainsString('new \\App\\Service\\UserService()', $rewritten['src/Legacy/Sibling.php']);
    }

    public function testMovedSourcePinsItsOwnNamespaceRelativeDependencies(): void
    {
        file_put_contents($this->root . '/src/Legacy/UserService.php', <<<'PHP'
<?php
namespace App\Legacy;
final class UserService
{
    public function make(Repository $repository): UserService
    {
        return new UserService();
    }
}
PHP);
        file_put_contents($this->root . '/src/Legacy/Repository.php', "<?php\nnamespace App\\Legacy;\nfinal class Repository {}\n");

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertContains('namespace_relative_dependency', $this->blindSpotKinds($plan->blindSpots));

        $rewritten = $this->applyEdits($plan->edits);
        self::assertNotSame([], PhpCodeParser::getAstFromString($rewritten['src/Legacy/UserService.php']));
        self::assertStringContainsString('namespace App\\Service;', $rewritten['src/Legacy/UserService.php']);
        self::assertStringContainsString('make(\\App\\Legacy\\Repository $repository)', $rewritten['src/Legacy/UserService.php']);
        // The declaration travels with the file, so its own short name stays untouched.
        self::assertStringContainsString('return new UserService();', $rewritten['src/Legacy/UserService.php']);
    }

    public function testAliasedImportKeepsTheAliasAndRewritesOnlyTheImportedIdentity(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/Client/AliasConsumer.php', <<<'PHP'
<?php
namespace App\Client;
use App\Legacy\UserService as Service;
final class AliasConsumer
{
    public function make(Service $input): Service
    {
        return new Service();
    }
}
PHP);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_SAFE, $plan->status, implode("\n", $plan->blockers));
        $rewritten = $this->applyEdits($plan->edits);
        self::assertStringContainsString('use App\\Service\\UserService as Service;', $rewritten['src/Client/AliasConsumer.php']);
        self::assertStringContainsString('new Service()', $rewritten['src/Client/AliasConsumer.php']);
    }

    public function testGroupedImportOfTheMovedClassBlocksWithoutPublishingEdits(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/Legacy/Other.php', "<?php\nnamespace App\\Legacy;\nfinal class Other {}\n");
        file_put_contents($this->root . '/src/Client/GroupedConsumer.php', <<<'PHP'
<?php
namespace App\Client;
use App\Legacy\{UserService, Other};
final class GroupedConsumer
{
    public function make(): UserService
    {
        return new UserService();
    }
}
PHP);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('Grouped import', implode("\n", $plan->blockers));
    }

    public function testDestinationIdentityCollisionBlocksWithoutFileCollision(): void
    {
        $this->writeBaseFixture();
        mkdir($this->root . '/src/Service', 0o775, true);
        file_put_contents($this->root . '/src/Service/Unconventional.php', "<?php\nnamespace App\\Service;\nfinal class UserService {}\n");

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('Destination class identity collides with indexed class App\\Service\\UserService', implode("\n", $plan->blockers));
    }

    public function testDestinationFileCollisionBlocksEvenWithoutIdentityCollision(): void
    {
        $this->writeBaseFixture();
        mkdir($this->root . '/src/Service', 0o775, true);
        file_put_contents($this->root . '/src/Service/UserService.php', "<?php\nnamespace App\\Service;\nfunction unrelated(): void {}\n");

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('Destination class path already exists: src/Service/UserService.php', implode("\n", $plan->blockers));
    }

    public function testStaleSourceBlocksBeforeAnyEditIsPublished(): void
    {
        $this->writeBaseFixture();
        $map = $this->map();
        file_put_contents($this->root . '/src/Client/Consumer.php', "<?php\nnamespace App\\Client;\n// changed after indexing\n");

        $plan = (new ClassMovePlanner())->plan($map, 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->moves);
        self::assertCount(1, $plan->staleEvidence);
        self::assertSame('src/Client/Consumer.php', $plan->staleEvidence[0]->path);
    }

    public function testMissingComposerManifestDoesNotGuessADestinationPath(): void
    {
        $this->writeBaseFixture();
        unlink($this->root . '/composer.json');

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertNull($plan->autoload);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('without a Composer manifest', implode("\n", $plan->blockers));
    }

    public function testUncoveredDestinationPrefixBlocks(): void
    {
        $this->writeBaseFixture();

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'Other\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertStringContainsString('No declared PSR-4 mapping covers the destination identity', implode("\n", $plan->blockers));
    }

    public function testEquallySpecificDestinationMappingsBlockInsteadOfPickingOne(): void
    {
        $this->writeBaseFixture();
        $this->writeComposer(['autoload' => ['psr-4' => ['App\\' => ['src/', 'lib/']]]]);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->moves);
        self::assertStringContainsString('equally specific PSR-4 mappings', implode("\n", $plan->blockers));
    }

    public function testUnexplainedSourceLocationBlocks(): void
    {
        $this->writeComposer(['autoload' => ['psr-4' => ['App\\' => 'lib/']]]);
        $this->writeBaseFixture();

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertStringContainsString('No declared PSR-4 mapping explains the current location', implode("\n", $plan->blockers));
    }

    /**
     * A declared directory outside the project root is a real autoload answer and an unusable move
     * destination. The absolute case matters most: trimming its leading slash would turn it into a
     * plausible in-project path and publish a move nobody declared.
     */
    public function testMappingsThatLeaveTheProjectRootBlockWithoutPublishingAMove(): void
    {
        foreach (['../outside/src/', '/etc/agent-map-escape/', 'C:/agent-map/src/'] as $directory) {
            $this->writeBaseFixture();
            $this->writeComposer(['autoload' => ['psr-4' => ['App\\' => 'src/', 'App\\Service\\' => $directory]]]);

            $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

            self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status, $directory);
            self::assertSame([], $plan->edits, $directory);
            self::assertSame([], $plan->moves, $directory);
            $blockers = implode("\n", $plan->blockers);
            self::assertStringContainsString('points outside the project root', $blockers, $directory);
            // The blocker has to name what composer.json actually declares, not a tidied version.
            self::assertStringContainsString($directory, $blockers, $directory);
        }
    }

    public function testAnEscapingShadowPrefixDoesNotHijackAProvableDestination(): void
    {
        $this->writeBaseFixture();
        $this->writeComposer(['autoload' => ['psr-4' => [
            'App\\' => '../outside/',
            'App\\Legacy\\' => 'src/Legacy/',
            'App\\Service\\' => 'src/Service/',
        ]]]);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        // The most specific mapping decides the destination; the escaping one Composer would fall
        // back to is reported for review rather than becoming the move.
        self::assertSame(ClassMovePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertNotNull($plan->autoload);
        self::assertSame('src/Service/UserService.php', $plan->autoload->destinationPath);
        self::assertSame('src/Service/UserService.php', $plan->moves[0]->toPath);
        self::assertContains('shadowed_autoload_prefix', $this->blindSpotKinds($plan->blindSpots));
        self::assertStringContainsString('../outside/', implode("\n", array_map(
            static fn (PlanBlindSpot $blindSpot): string => $blindSpot->message,
            $plan->blindSpots,
        )));
    }

    public function testClassmapLayoutBlocksInsteadOfDerivingAPath(): void
    {
        $this->writeBaseFixture();
        $this->writeComposer(['autoload' => ['psr-4' => ['App\\' => 'src/'], 'classmap' => ['src/Legacy']]]);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertStringContainsString('classmap', implode("\n", $plan->blockers));
    }

    public function testCrossingAutoloadSectionsStaysReviewEvidence(): void
    {
        $this->writeBaseFixture();
        $this->writeComposer([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['App\\Service\\' => 'tests/Service/']],
        ]);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertContains('autoload_section_change', $this->blindSpotKinds($plan->blindSpots));
        self::assertNotNull($plan->autoload);
        self::assertSame('tests/Service/UserService.php', $plan->autoload->destinationPath);
    }

    public function testMultiSymbolFileBlocksBecauseOneNamespaceEditRelocatesEverything(): void
    {
        file_put_contents($this->root . '/src/Legacy/UserService.php', <<<'PHP'
<?php
namespace App\Legacy;
final class UserService {}
final class Companion {}
PHP);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('declares 2 symbols', implode("\n", $plan->blockers));
    }

    public function testBracedNamespaceBlocks(): void
    {
        file_put_contents($this->root . '/src/Legacy/UserService.php', "<?php\nnamespace App\\Legacy {\n    final class UserService {}\n}\n");

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('braced namespace', implode("\n", $plan->blockers));
    }

    public function testGlobalNamespaceSourceBlocks(): void
    {
        $this->writeComposer(['autoload' => ['psr-4' => ['' => 'src/']]]);
        file_put_contents($this->root . '/src/UserService.php', "<?php\nfinal class UserService {}\n");

        $plan = (new ClassMovePlanner())->plan($this->map(), 'UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('global namespace', implode("\n", $plan->blockers));
    }

    public function testNamespacedFunctionFallbackBlocksTheMove(): void
    {
        file_put_contents($this->root . '/src/Legacy/UserService.php', <<<'PHP'
<?php
namespace App\Legacy;
final class UserService
{
    public function make(): string
    {
        return helper();
    }
}
PHP);
        file_put_contents($this->root . '/src/Legacy/functions.php', "<?php\nnamespace App\\Legacy;\nfunction helper(): string { return 'x'; }\n");

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('would rebind after the move', implode("\n", $plan->blockers));
    }

    public function testUnknownBareCallStaysReviewEvidenceWhileBuiltinsAreSilent(): void
    {
        file_put_contents($this->root . '/src/Legacy/UserService.php', <<<'PHP'
<?php
namespace App\Legacy;
final class UserService
{
    public function make(): string
    {
        return strtoupper(project_specific_helper());
    }
}
PHP);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        $fallbacks = array_values(array_filter(
            $plan->blindSpots,
            static fn (PlanBlindSpot $blindSpot): bool => $blindSpot->kind === 'namespace_fallback_reference',
        ));
        self::assertCount(1, $fallbacks);
        self::assertStringContainsString('project_specific_helper', $fallbacks[0]->message);
    }

    public function testPhpDocStringLiteralAndDynamicUseStayExplicitUncertainty(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/Client/ReviewConsumer.php', <<<'PHP'
<?php
namespace App\Client;
use App\Legacy\UserService;
final class ReviewConsumer
{
    /** @return \App\Legacy\UserService */
    public function make(string $class): UserService
    {
        $literal = 'App\\Legacy\\UserService';
        $dynamic = new $class();

        return new UserService();
    }
}
PHP);

        $plan = (new ClassMovePlanner())->plan($this->map(), 'App\\Legacy\\UserService', 'App\\Service\\UserService');

        self::assertSame(ClassMovePlan::STATUS_REVIEW_REQUIRED, $plan->status, implode("\n", $plan->blockers));
        self::assertNotSame([], $plan->edits);
        $kinds = $this->blindSpotKinds($plan->blindSpots);
        self::assertContains('phpdoc_type_reference', $kinds);
        self::assertContains('class_string_literal', $kinds);
        self::assertContains('dynamic_class_name', $kinds);
        self::assertSame([], $plan->blockers);
    }

    public function testRenameAndMoveContractsRejectEachOthersRequests(): void
    {
        $this->writeBaseFixture();
        $map = $this->map();

        try {
            (new ClassMovePlanner())->plan($map, 'App\\Legacy\\UserService', 'App\\Legacy\\Renamed');
            self::fail('A same-namespace request must not be accepted as a move.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('class-rename-plan', $exception->getMessage());
        }

        try {
            (new ClassMovePlanner())->plan($map, 'App\\Legacy\\UserService', 'App\\Service\\Renamed');
            self::fail('A simultaneous rename and move must not be accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('class-rename-plan', $exception->getMessage());
        }

        try {
            (new ClassRenamePlanner())->plan($map, 'App\\Legacy\\UserService', 'App\\Service\\UserService');
            self::fail('class-rename-plan must keep rejecting namespace moves.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('same namespace only', $exception->getMessage());
        }
    }

    public function testCliPublishesEquivalentJsonAndToonWithoutMutatingSource(): void
    {
        $this->writeBaseFixture();
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $mapPath, 'json');
        $before = $this->snapshot();

        $json = $this->runCli(['agent-map', 'class-move-plan', 'App\\Legacy\\UserService', 'App\\Service\\UserService', '--index', $mapPath, '--format', 'json']);
        self::assertSame(0, $json['status']);
        $payload = json_decode($json['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('class_move_plan', $payload['type'] ?? null);
        self::assertSame('1.0', $payload['contract_version'] ?? null);
        self::assertSame('safe', $payload['status'] ?? null);
        self::assertCount(1, $payload['moves'] ?? []);
        self::assertSame('src/Service/UserService.php', $payload['autoload']['destination_path'] ?? null);

        $toon = $this->runCli(['agent-map', 'class-move-plan', 'App\\Legacy\\UserService', 'App\\Service\\UserService', '--index', $mapPath, '--format', 'toon']);
        self::assertSame(0, $toon['status']);
        self::assertStringContainsString('class_move_plan', $toon['output']);
        self::assertStringContainsString('src/Service/UserService.php', $toon['output']);

        self::assertSame($before, $this->snapshot());
    }

    public function testBlockedCliPlanExitsNonZeroAndPublishesNoEdits(): void
    {
        $this->writeBaseFixture();
        mkdir($this->root . '/src/Service', 0o775, true);
        file_put_contents($this->root . '/src/Service/UserService.php', "<?php\nnamespace App\\Service;\nfunction unrelated(): void {}\n");
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $mapPath, 'json');

        $result = $this->runCli(['agent-map', 'class-move-plan', 'App\\Legacy\\UserService', 'App\\Service\\UserService', '--index', $mapPath, '--format', 'json']);

        self::assertSame(1, $result['status']);
        $payload = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('blocked', $payload['status'] ?? null);
        self::assertSame([], $payload['edits'] ?? null);
        self::assertSame([], $payload['moves'] ?? null);
    }

    /**
     * @param list<string> $argv
     * @return array{status: int, output: string}
     */
    private function runCli(array $argv): array
    {
        ob_start();
        try {
            $status = (new ClassMoveCliApplication())->run($argv);
        } finally {
            $output = (string) ob_get_clean();
        }

        return ['status' => $status, 'output' => $output];
    }

    /**
     * @param list<PlanBlindSpot> $blindSpots
     * @return list<string>
     */
    private function blindSpotKinds(array $blindSpots): array
    {
        return array_map(static fn (PlanBlindSpot $blindSpot): string => $blindSpot->kind, $blindSpots);
    }

    /** @param array<string, mixed> $manifest */
    private function writeComposer(array $manifest): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function writeBaseFixture(): void
    {
        $this->writeClass();
        file_put_contents($this->root . '/src/Client/Consumer.php', <<<'PHP'
<?php
namespace App\Client;
use App\Legacy\UserService;
final class Consumer
{
    public function make(UserService $input): UserService
    {
        $copy = new UserService();
        if ($copy instanceof UserService) {
            return $copy;
        }

        return $input;
    }

    public function className(): string
    {
        return \App\Legacy\UserService::class;
    }
}
PHP);
    }

    private function writeClass(): void
    {
        file_put_contents($this->root . '/src/Legacy/UserService.php', "<?php\nnamespace App\\Legacy;\nfinal class UserService {}\n");
    }

    private function map(): AgentMapIndex
    {
        return (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer()))->build(
            root: $this->root,
            paths: ['src'],
            excludes: [],
        );
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[$item->getPathname()] = (string) hash_file('sha256', $item->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param list<PlanEdit> $edits
     * @return array<string, string>
     */
    private function applyEdits(array $edits): array
    {
        /** @var array<string, list<PlanEdit>> $byPath */
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

            usort($pathEdits, static fn (PlanEdit $left, PlanEdit $right): int => $right->startFilePos <=> $left->startFilePos);
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
