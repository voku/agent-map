<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Cli\ParameterRenameCliApplication;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Rename\ParameterRenamePlan;
use voku\AgentMap\Rename\ParameterRenamePlanner;
use voku\AgentMap\Rename\RenameEdit;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

final class ParameterRenamePlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-parameter-rename-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPlansParameterBindingAndNamedArgumentButLeavesPositionalAndUnrelatedParameterUntouched(): void
    {
        $this->writeService();
        $before = (string) file_get_contents($this->root . '/src/Service.php');

        $plan = (new ParameterRenamePlanner())->plan($this->map(), 'Demo\\Service::find', '$userId', '$id');

        self::assertSame(ParameterRenamePlan::STATUS_SAFE, $plan->status);
        self::assertSame('$userId', $plan->originalName);
        self::assertSame('$id', $plan->replacementName);
        self::assertSame(0, $plan->parameterIndex);
        self::assertSame(['method:Demo\\Service::find'], $plan->family);
        self::assertSame(['named_argument', 'parameter_declaration', 'parameter_reference'], $this->sortedRoles($plan->edits));
        self::assertSame([], $plan->blindSpots);
        self::assertSame([], $plan->blockers);
        self::assertSame($before, file_get_contents($this->root . '/src/Service.php'));

        $rewritten = $this->applyEdits($plan->edits)['src/Service.php'];
        self::assertStringContainsString("find(id: 'named')", $rewritten);
        self::assertStringContainsString("find('positional')", $rewritten);
        self::assertStringContainsString('private function find(string $id, int $limit = 10)', $rewritten);
        self::assertStringContainsString("if (\$id === '')", $rewritten);
        self::assertStringContainsString('private function other(string $userId)', $rewritten);
        self::assertNotSame([], PhpCodeParser::getAstFromString($rewritten));
    }

    public function testReplacementParameterCollisionBlocksWithoutPublishingEdits(): void
    {
        $this->writeService(collision: true);

        $plan = (new ParameterRenamePlanner())->plan($this->map(), 'Demo\\Service::find', 'userId', 'id');

        self::assertSame(ParameterRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('collides', implode("\n", $plan->blockers));
    }

    public function testReplacementLocalVariableCollisionBlocksWithoutMergingBindings(): void
    {
        $this->writeService(localCollision: true);

        $plan = (new ParameterRenamePlanner())->plan($this->map(), 'Demo\\Service::find', 'userId', 'id');

        self::assertSame(ParameterRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('would merge local bindings', implode("\n", $plan->blockers));
    }

    public function testNestedClosureUseBlocksInsteadOfGuessingBindingSemantics(): void
    {
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php
namespace Demo;
final class Service
{
    private function find(string $userId): void
    {
        $callback = static fn (): string => $userId;
        $callback();
    }
}
PHP);

        $plan = (new ParameterRenamePlanner())->plan($this->map([]), 'Demo\\Service::find', 'userId', 'id');

        self::assertSame(ParameterRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('nested closure/arrow scope', implode("\n", $plan->blockers));
    }

    public function testOverrideFamilyParameterMismatchBlocksInsteadOfSilentlySplittingContract(): void
    {
        $this->writeOverrideFixture();
        $relations = [
            RelationEntry::create(
                sourceId: 'method:Demo\\Impl::find',
                kind: 'overrides',
                targetIds: ['method:Demo\\Contract::find'],
                file: 'src/Impl.php',
                lineStart: 5,
                lineEnd: 7,
                resolution: 'phpstan_resolved',
            ),
        ];

        $plan = (new ParameterRenamePlanner())->plan($this->map($relations), 'Demo\\Contract::find', 'userId', 'id');

        self::assertSame(ParameterRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame(['method:Demo\\Contract::find', 'method:Demo\\Impl::find'], $plan->family);
        self::assertStringContainsString('not consistently named', implode("\n", $plan->blockers));
    }

    public function testDynamicNamedCallRequiresReviewWithoutInventingCallEdit(): void
    {
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php
namespace Demo;
final class Service
{
    public function run(): void
    {
        $name = 'find';
        $this->{$name}(userId: 'dynamic');
    }

    private function find(string $userId): void
    {
    }
}
PHP);
        $relations = [
            RelationEntry::create(
                sourceId: 'method:Demo\\Service::run',
                kind: 'calls',
                targetIds: ['unresolved:calls'],
                file: 'src/Service.php',
                lineStart: 8,
                lineEnd: 8,
                resolution: 'dynamic',
                receiverType: 'Demo\\Service',
            ),
        ];

        $plan = (new ParameterRenamePlanner())->plan($this->map($relations), 'Demo\\Service::find', 'userId', 'id');

        self::assertSame(ParameterRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status);
        self::assertSame(['parameter_declaration'], $this->sortedRoles($plan->edits));
        self::assertSame('dynamic_parameter_call', $plan->blindSpots[0]->kind);
    }

    public function testArgumentUnpackingRequiresReviewButKeepsExactDeclarationEdit(): void
    {
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php
namespace Demo;
final class Service
{
    public function run(): void
    {
        $args = ['userId' => 'spread'];
        $this->find(...$args);
    }

    private function find(string $userId): void
    {
    }
}
PHP);
        $relations = [
            RelationEntry::create(
                sourceId: 'method:Demo\\Service::run',
                kind: 'calls',
                targetIds: ['method:Demo\\Service::find'],
                file: 'src/Service.php',
                lineStart: 8,
                lineEnd: 8,
                resolution: 'phpstan_resolved',
                receiverType: 'Demo\\Service',
            ),
        ];

        $plan = (new ParameterRenamePlanner())->plan($this->map($relations), 'Demo\\Service::find', 'userId', 'id');

        self::assertSame(ParameterRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status);
        self::assertSame(['parameter_declaration'], $this->sortedRoles($plan->edits));
        self::assertSame('argument_unpacking', $plan->blindSpots[0]->kind);
    }

    public function testMultipleTargetNamedCallBlocksWithoutPublishingEdits(): void
    {
        $this->writeService();
        $relations = $this->serviceRelations();
        $relations[0] = RelationEntry::create(
            sourceId: 'method:Demo\\Service::run',
            kind: 'calls',
            targetIds: ['method:Demo\\Other::find', 'method:Demo\\Service::find'],
            file: 'src/Service.php',
            lineStart: 7,
            lineEnd: 7,
            resolution: 'multiple_targets',
            receiverType: 'Demo\\Service|Demo\\Other',
        );

        $plan = (new ParameterRenamePlanner())->plan($this->map($relations), 'Demo\\Service::find', 'userId', 'id');

        self::assertSame(ParameterRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('outside method', implode("\n", $plan->blockers));
    }

    public function testPublicParameterContractIsReviewRequiredForOutOfScopeNamedCallers(): void
    {
        $this->writeService(publicTarget: true);

        $plan = (new ParameterRenamePlanner())->plan($this->map(), 'Demo\\Service::find', 'userId', 'id');

        self::assertSame(ParameterRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status);
        self::assertSame('out_of_scope_named_callers', $plan->blindSpots[0]->kind);
        self::assertCount(3, $plan->edits);
    }

    public function testStaleEvidenceBlocksWithoutPublishingEdits(): void
    {
        $this->writeService();
        $map = $this->map();
        file_put_contents($this->root . '/src/Service.php', "\n// changed after mapping\n", FILE_APPEND);

        $plan = (new ParameterRenamePlanner())->plan($map, 'Demo\\Service::find', 'userId', 'id');

        self::assertSame(ParameterRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->blockers);
        self::assertCount(1, $plan->staleEvidence);
        self::assertSame('src/Service.php', $plan->staleEvidence[0]->path);
    }

    public function testCliJsonAndToonProjectTheSameReadOnlySemanticPlan(): void
    {
        $this->writeService();
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $mapPath, 'json');
        $before = (string) file_get_contents($this->root . '/src/Service.php');
        $application = new ParameterRenameCliApplication();

        ob_start();
        $jsonStatus = $application->run([
            'agent-map',
            'parameter-rename-plan',
            'Demo\\Service::find',
            '$userId',
            '$id',
            '--index',
            $mapPath,
            '--format',
            'json',
        ]);
        $json = (string) ob_get_clean();
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        ob_start();
        $toonStatus = $application->run([
            'agent-map',
            'parameter-rename-plan',
            'Demo\\Service::find',
            '$userId',
            '$id',
            '--index',
            $mapPath,
            '--format',
            'toon',
        ]);
        $toon = (string) ob_get_clean();

        self::assertSame(0, $jsonStatus);
        self::assertSame(0, $toonStatus);
        self::assertSame('parameter_rename_plan', $payload['type'] ?? null);
        self::assertSame(ParameterRenamePlan::CONTRACT_VERSION, $payload['contract_version'] ?? null);
        self::assertSame(Toon::encode($payload) . "\n", $toon);
        self::assertSame($before, file_get_contents($this->root . '/src/Service.php'));
    }

    private function writeService(bool $collision = false, bool $publicTarget = false, bool $localCollision = false): void
    {
        $visibility = $publicTarget ? 'public' : 'private';
        $secondParameter = $collision ? 'int $id = 10' : 'int $limit = 10';
        $body = $localCollision
            ? "        \$id = 'existing';\n        if (\$userId === \$id) {\n            return;\n        }"
            : "        if (\$userId === '') {\n            return;\n        }";
        file_put_contents($this->root . '/src/Service.php', <<<PHP
<?php
namespace Demo;
final class Service
{
    public function run(): void
    {
        \$this->find(userId: 'named');
        \$this->find('positional');
    }

    {$visibility} function find(string \$userId, {$secondParameter}): void
    {
{$body}
    }

    private function other(string \$userId): void
    {
    }
}
PHP);
    }

    private function writeOverrideFixture(): void
    {
        file_put_contents($this->root . '/src/Contract.php', <<<'PHP'
<?php
namespace Demo;
interface Contract
{
    public function find(string $userId): void;
}
PHP);
        file_put_contents($this->root . '/src/Impl.php', <<<'PHP'
<?php
namespace Demo;
final class Impl implements Contract
{
    public function find(string $otherName): void
    {
    }
}
PHP);
    }

    /** @param list<RelationEntry>|null $relations */
    private function map(?array $relations = null): AgentMapIndex
    {
        $files = [];
        $paths = glob($this->root . '/src/*.php');
        self::assertIsArray($paths);
        sort($paths, SORT_STRING);
        foreach ($paths as $absolute) {
            $files[] = $this->fileEntry('src/' . basename($absolute));
        }

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: 'simple-php-code-parser+phpstan',
            files: $files,
            relations: $relations ?? $this->serviceRelations(),
        );
    }

    /** @return list<RelationEntry> */
    private function serviceRelations(): array
    {
        return [
            RelationEntry::create(
                sourceId: 'method:Demo\\Service::run',
                kind: 'calls',
                targetIds: ['method:Demo\\Service::find'],
                file: 'src/Service.php',
                lineStart: 7,
                lineEnd: 7,
                resolution: 'phpstan_resolved',
                receiverType: 'Demo\\Service',
            ),
            RelationEntry::create(
                sourceId: 'method:Demo\\Service::run',
                kind: 'calls',
                targetIds: ['method:Demo\\Service::find'],
                file: 'src/Service.php',
                lineStart: 8,
                lineEnd: 8,
                resolution: 'phpstan_resolved',
                receiverType: 'Demo\\Service',
            ),
        ];
    }

    private function fileEntry(string $path): FileEntry
    {
        $absolute = $this->root . '/' . $path;
        $extracted = (new SimplePhpParserSymbolExtractor())->extract($absolute);
        self::assertTrue($extracted->ok, $extracted->error ?? 'Parser extraction failed.');
        $hash = hash_file('sha256', $absolute);
        self::assertIsString($hash);

        return new FileEntry(
            path: $path,
            sha256: 'sha256:' . $hash,
            namespace: 'Demo',
            symbols: $extracted->symbols,
            semanticStatus: 'analysed',
        );
    }

    /** @param list<RenameEdit> $edits @return array<string, string> */
    private function applyEdits(array $edits): array
    {
        $byPath = [];
        foreach ($edits as $edit) {
            $byPath[$edit->path][] = $edit;
        }

        $rewritten = [];
        foreach ($byPath as $path => $pathEdits) {
            $source = file_get_contents($this->root . '/' . $path);
            self::assertIsString($source);
            usort($pathEdits, static fn (RenameEdit $left, RenameEdit $right): int => $right->startFilePos <=> $left->startFilePos);
            foreach ($pathEdits as $edit) {
                self::assertSame($edit->expected, substr($source, $edit->startFilePos, $edit->endFilePos - $edit->startFilePos + 1));
                $source = substr($source, 0, $edit->startFilePos) . $edit->replacement . substr($source, $edit->endFilePos + 1);
            }
            $rewritten[$path] = $source;
        }

        return $rewritten;
    }

    /** @param list<RenameEdit> $edits @return list<string> */
    private function sortedRoles(array $edits): array
    {
        $roles = array_map(static fn (RenameEdit $edit): string => $edit->role, $edits);
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