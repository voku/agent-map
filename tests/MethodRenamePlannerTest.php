<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentMap\Cli\RenameCliApplication;
use voku\AgentMap\Extract\SimplePhpParserSymbolExtractor;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Rename\MethodRenamePlan;
use voku\AgentMap\Rename\MethodRenamePlanner;
use voku\AgentMap\Rename\RenameEdit;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

final class MethodRenamePlannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-map-method-rename-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPlansInterfaceImplementationAndResolvedCallerAsExactEdits(): void
    {
        $this->writeFixture();
        $plan = (new MethodRenamePlanner())->plan($this->map(), 'Demo\\Contract::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_SAFE, $plan->status);
        self::assertSame(
            ['method:Demo\\Contract::oldName', 'method:Demo\\Impl::oldName'],
            $plan->family,
        );
        self::assertCount(3, $plan->edits);
        self::assertSame(['call', 'declaration', 'declaration'], $this->sortedRoles($plan->edits));
        self::assertSame([], $plan->blockers);
        self::assertSame([], $plan->staleEvidence);
        self::assertSame('simple-php-code-parser+phpstan', $plan->provenance->backend);
        self::assertStringStartsWith('sha256:', $plan->provenance->mapDigest);

        $rewritten = $this->applyEdits($plan->edits);
        foreach ($rewritten as $source) {
            self::assertStringNotContainsString('oldName', $source);
            self::assertNotSame([], PhpCodeParser::getAstFromString($source));
        }
    }

    public function testMultipleTargetCallOutsideRenameFamilyBlocksWithoutPublishingEdits(): void
    {
        $this->writeFixture(withOtherTarget: true);
        $map = $this->map(callTargets: ['method:Demo\\Contract::oldName', 'method:Demo\\Other::oldName'], callResolution: 'multiple_targets');
        $plan = (new MethodRenamePlanner())->plan($map, 'Demo\\Contract::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('outside method', implode("\n", $plan->blockers));
    }

    public function testTypedDynamicDispatchRequiresReviewButKeepsDeterministicEdits(): void
    {
        $this->writeFixture(withDynamicCall: true);
        $relations = $this->relations();
        $relations[] = RelationEntry::create(
            sourceId: 'method:Demo\\Caller::run',
            kind: 'calls',
            targetIds: ['unresolved:calls'],
            file: 'src/Caller.php',
            lineStart: 9,
            lineEnd: 9,
            resolution: 'dynamic',
            receiverType: 'Demo\\Contract',
        );
        $plan = (new MethodRenamePlanner())->plan($this->map(relations: $relations), 'Demo\\Contract::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_REVIEW_REQUIRED, $plan->status);
        self::assertCount(3, $plan->edits);
        self::assertCount(1, $plan->blindSpots);
        self::assertSame('dynamic_method_name', $plan->blindSpots[0]->kind);
    }

    public function testReplacementCollisionInRelatedImplementationBlocks(): void
    {
        $this->writeFixture(withCollision: true);
        $plan = (new MethodRenamePlanner())->plan($this->map(), 'Demo\\Contract::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('collide', implode("\n", $plan->blockers));
        self::assertStringContainsString('method:Demo\\Impl::renamed', implode("\n", $plan->blockers));
    }

    public function testUnindexedPrototypeBlocksTheWholeFamily(): void
    {
        $this->writeFixture();
        $relations = $this->relations();
        $relations[] = RelationEntry::create(
            sourceId: 'method:Demo\\Impl::oldName',
            kind: 'overrides',
            targetIds: ['method:Vendor\\Contract::oldName'],
            file: 'src/Impl.php',
            lineStart: 5,
            lineEnd: 7,
            resolution: 'phpstan_resolved',
        );
        $plan = (new MethodRenamePlanner())->plan($this->map(relations: $relations), 'Demo\\Impl::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('unindexed method contract', implode("\n", $plan->blockers));
    }

    public function testTwoSameNameCallsOnOneLineFailClosedInsteadOfGuessing(): void
    {
        $this->writeFixture(twoCallsOnOneLine: true);
        $plan = (new MethodRenamePlanner())->plan($this->map(), 'Demo\\Contract::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('found 2 candidate(s)', implode("\n", $plan->blockers));
    }

    public function testStructuralOnlyMapCannotClaimSemanticRenameSafety(): void
    {
        $this->writeFixture();
        $map = $this->map(backend: 'simple-php-code-parser+structural-only');
        $plan = (new MethodRenamePlanner())->plan($map, 'Demo\\Contract::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertStringContainsString('PHPStan-backed map', implode("\n", $plan->blockers));
    }

    public function testStaleEvidenceIsMachineDistinguishableFromSemanticBlockers(): void
    {
        $this->writeFixture();
        $map = $this->map();
        file_put_contents($this->root . '/src/Caller.php', "\n// changed after mapping\n", FILE_APPEND);

        $plan = (new MethodRenamePlanner())->plan($map, 'Demo\\Contract::oldName', 'renamed');

        self::assertSame(MethodRenamePlan::STATUS_BLOCKED, $plan->status);
        self::assertSame([], $plan->edits);
        self::assertSame([], $plan->blockers);
        self::assertCount(1, $plan->staleEvidence);
        self::assertSame('src/Caller.php', $plan->staleEvidence[0]->path);
        self::assertSame('hash', $plan->staleEvidence[0]->reason);
    }

    public function testCliPublishesMachineReadablePlanWithoutMutatingSource(): void
    {
        $this->writeFixture();
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map(), $mapPath, 'json');
        $before = (string) file_get_contents($this->root . '/src/Caller.php');

        ob_start();
        $status = (new RenameCliApplication())->run([
            'agent-map',
            'rename-plan',
            'Demo\\Contract::oldName',
            'renamed',
            '--index',
            $mapPath,
            '--format',
            'json',
        ]);
        $output = ob_get_clean();

        self::assertSame(0, $status);
        self::assertIsString($output);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('safe', $payload['status'] ?? null);
        self::assertSame(MethodRenamePlan::CONTRACT_VERSION, $payload['contract_version'] ?? null);
        self::assertSame('simple-php-code-parser+phpstan', $payload['provenance']['backend'] ?? null);
        self::assertArrayNotHasKey('source_hashes', $payload['provenance'] ?? []);
        self::assertSame([], $payload['stale_evidence'] ?? null);
        self::assertCount(3, $payload['edits'] ?? []);
        self::assertSame($before, file_get_contents($this->root . '/src/Caller.php'));
    }

    /**
     * Writes one small polymorphic fixture while varying only the risk shape under test.
     */
    private function writeFixture(
        bool $withDynamicCall = false,
        bool $withCollision = false,
        bool $twoCallsOnOneLine = false,
        bool $withOtherTarget = false,
    ): void {
        file_put_contents($this->root . '/src/Contract.php', <<<'PHP'
<?php
namespace Demo;
interface Contract
{
    public function oldName(): void;
}
PHP);

        $collision = $withCollision ? <<<'PHP'

    public function renamed(): void
    {
    }
PHP : '';
        file_put_contents($this->root . '/src/Impl.php', <<<PHP
<?php
namespace Demo;
final class Impl implements Contract
{
    public function oldName(): void
    {
    }{$collision}
}
PHP);

        $call = $twoCallsOnOneLine
            ? '        $service->oldName(); $service->oldName();'
            : '        $service->oldName();';
        $dynamic = $withDynamicCall
            ? "\n        \$name = 'oldName';\n        \$service->{\$name}();"
            : '';
        file_put_contents($this->root . '/src/Caller.php', <<<PHP
<?php
namespace Demo;
final class Caller
{
    public function run(Contract \$service): void
    {
{$call}{$dynamic}
    }
}
PHP);

        if ($withOtherTarget) {
            file_put_contents($this->root . '/src/Other.php', <<<'PHP'
<?php
namespace Demo;
final class Other
{
    public function oldName(): void
    {
    }
}
PHP);
        }
    }

    /**
     * Builds the smallest faithful map: structural declarations come from the real parser, while
     * semantic override/call relations are supplied exactly as the PHPStan backend would publish them.
     *
     * @param non-empty-list<string>|null $callTargets
     * @param list<RelationEntry>|null $relations
     */
    private function map(
        ?array $callTargets = null,
        string $callResolution = 'phpstan_resolved',
        ?array $relations = null,
        string $backend = 'simple-php-code-parser+phpstan',
    ): AgentMapIndex {
        $files = [];
        foreach (['src/Contract.php', 'src/Impl.php', 'src/Caller.php', 'src/Other.php'] as $path) {
            if (is_file($this->root . '/' . $path)) {
                $files[] = $this->fileEntry($path);
            }
        }

        return new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: $backend,
            files: $files,
            relations: $relations ?? $this->relations($callTargets, $callResolution),
        );
    }

    /**
     * Returns the semantic and type-family edges needed by the rename planner.
     *
     * @param non-empty-list<string>|null $callTargets
     * @return list<RelationEntry>
     */
    private function relations(?array $callTargets = null, string $callResolution = 'phpstan_resolved'): array
    {
        return [
            RelationEntry::create(
                sourceId: 'class:Demo\\Impl',
                kind: 'implements',
                targetIds: ['interface:Demo\\Contract'],
                file: 'src/Impl.php',
                lineStart: 3,
                lineEnd: 8,
                resolution: 'structural_only',
            ),
            RelationEntry::create(
                sourceId: 'method:Demo\\Impl::oldName',
                kind: 'overrides',
                targetIds: ['method:Demo\\Contract::oldName'],
                file: 'src/Impl.php',
                lineStart: 5,
                lineEnd: 7,
                resolution: 'phpstan_resolved',
            ),
            RelationEntry::create(
                sourceId: 'method:Demo\\Caller::run',
                kind: 'calls',
                targetIds: $callTargets ?? ['method:Demo\\Contract::oldName'],
                file: 'src/Caller.php',
                lineStart: 7,
                lineEnd: 7,
                resolution: $callResolution,
                receiverType: 'Demo\\Contract',
                resultType: 'void',
            ),
        ];
    }

    /** Builds one map file entry from the parser used by production extraction. */
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

    /**
     * Applies a successful plan only inside the test, in descending byte order per file, so the
     * produced offsets are proven usable without adding a source-mutating product API.
     *
     * @param list<RenameEdit> $edits
     * @return array<string, string>
     */
    private function applyEdits(array $edits): array
    {
        $byPath = [];
        foreach ($edits as $edit) {
            $byPath[$edit->path][] = $edit;
        }

        $rewritten = [];
        foreach ($byPath as $path => $pathEdits) {
            $absolute = $this->root . '/' . $path;
            $source = file_get_contents($absolute);
            self::assertIsString($source);
            $hash = hash('sha256', $source);
            foreach ($pathEdits as $edit) {
                self::assertSame('sha256:' . $hash, $edit->sourceSha256);
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

    /**
     * @param list<RenameEdit> $edits
     * @return list<string>
     */
    private function sortedRoles(array $edits): array
    {
        $roles = array_map(static fn (RenameEdit $edit): string => $edit->role, $edits);
        sort($roles, SORT_STRING);

        return $roles;
    }

    /** Removes the isolated test workspace without touching any project checkout state. */
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
