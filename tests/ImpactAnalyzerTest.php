<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentGraph\Sqlite\GraphStore;
use RuntimeException;
use voku\AgentMap\Discovery\GraphNode;
use voku\AgentMap\Discovery\ImpactAnalyzer;
use voku\AgentMap\Discovery\ImpactNode;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\GraphProjectionFactory;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class ImpactAnalyzerTest extends TestCase
{
    public function testTraversesContractImplementorCallerAndCallerOfCaller(): void
    {
        $report = (new ImpactAnalyzer())->forMethod($this->map(), 'Demo\\Contract::run', 3, 20);

        self::assertSame('method:Demo\\Contract::run', $report->target->id);
        self::assertSame([
            ['method:Demo\\Implementation::run', 1, false],
            ['method:Demo\\Caller::call', 2, false],
            ['method:Demo\\MaybeCaller::call', 2, true],
            ['method:Demo\\DualTop::execute', 3, false],
            ['method:Demo\\Top::execute', 3, false],
            ['method:Demo\\MaybeTop::execute', 3, true],
        ], array_map(
            static fn (ImpactNode $impact): array => [$impact->node->id, $impact->depth, $impact->uncertain],
            $report->impacts,
        ));
        self::assertFalse($report->truncated);
        self::assertStringStartsWith('sha256:', $report->mapDigest);
    }

    public function testSqliteTopologyProducesExactImpactParityWithoutMapRelations(): void
    {
        $map = $this->map();
        $database = sys_get_temp_dir() . '/agent-map-impact-graph-' . bin2hex(random_bytes(8)) . '.sqlite';

        try {
            $graph = new GraphStore($database);
            $graph->replace((new GraphProjectionFactory())->relations($map), 'map:test', 'sha256:test');
            $filesOnly = new AgentMapIndex(
                $map->schemaVersion,
                $map->root,
                $map->backend,
                $map->files,
                [],
                $map->diagnostics,
                $map->fingerprint,
            );
            $digest = $map->mapDigest();

            $expected = (new ImpactAnalyzer())->forMethod($map, 'Demo\\Contract::run', 3, 20);
            $actual = (new ImpactAnalyzer())->forMethodUsingGraph(
                $filesOnly,
                $graph,
                $digest,
                'Demo\\Contract::run',
                3,
                20,
            );

            self::assertSame($expected->toArray(), $actual->toArray());
        } finally {
            if (is_file($database)) {
                unlink($database);
            }
        }
    }

    public function testPropagatesUncertaintyAndKeepsTheImmediatePathEvidence(): void
    {
        $report = (new ImpactAnalyzer())->forMethod($this->map(), 'Demo\\Implementation::run', 2, 20);

        $maybeTop = $this->impact($report->impacts, 'method:Demo\\MaybeTop::execute');
        self::assertTrue($maybeTop->uncertain);
        self::assertSame(['method:Demo\\MaybeCaller::call'], $maybeTop->viaNodeIds);
        self::assertContains('calls', $maybeTop->relationKinds);
        self::assertNotEmpty($maybeTop->evidenceIds);
    }

    public function testCertainPathDominatesAnAlternativeUncertainPath(): void
    {
        $report = (new ImpactAnalyzer())->forMethod($this->map(), 'Demo\\Implementation::run', 2, 20);

        $dualTop = $this->impact($report->impacts, 'method:Demo\\DualTop::execute');
        self::assertFalse($dualTop->uncertain);
        self::assertSame([
            'method:Demo\\Caller::call',
            'method:Demo\\MaybeCaller::call',
        ], $dualTop->viaNodeIds);
    }

    public function testMarksMultipleTargetPropagationAsUncertain(): void
    {
        $report = (new ImpactAnalyzer())->forMethod($this->map(), 'Demo\\Implementation::run', 1, 20);

        $uncertain = $this->impact($report->impacts, 'method:Demo\\MaybeCaller::call');
        self::assertTrue($uncertain->uncertain);
        self::assertSame(['method:Demo\\Implementation::run'], $uncertain->viaNodeIds);
        self::assertContains('calls', $uncertain->relationKinds);
        self::assertNotEmpty($uncertain->evidenceIds);
    }

    public function testTruncatesInsteadOfPretendingTheImpactSetIsComplete(): void
    {
        $report = (new ImpactAnalyzer())->forMethod($this->map(), 'Demo\\Implementation::run', 2, 1);

        self::assertTrue($report->truncated);
        self::assertCount(1, $report->impacts);
    }

    public function testFileImpactUnionsEveryDeclarationInTheFile(): void
    {
        // src/Pair.php declares Alpha and Beta, and each has a dependent the
        // other does not. Seeding one declaration would answer for half the file.
        $report = (new ImpactAnalyzer())->forFile($this->pairMap(), 'src/Pair.php', 2, 20);

        self::assertSame('src/Pair.php', $report->path);
        self::assertSame([
            'class:Demo\\Alpha',
            'class:Demo\\Beta',
            'method:Demo\\Alpha::run',
            'method:Demo\\Beta::run',
        ], array_map(static fn (GraphNode $seed): string => $seed->id, $report->seeds));
        self::assertSame([
            'method:Demo\\CallsAlpha::call',
            'method:Demo\\CallsBeta::call',
        ], array_map(static fn (ImpactNode $impact): string => $impact->node->id, $report->impacts));
        self::assertSame(['src/CallsAlpha.php', 'src/CallsBeta.php'], $report->impactedFiles());
        self::assertFalse($report->truncated);
        self::assertStringStartsWith('sha256:', $report->mapDigest);
    }

    public function testFileImpactExcludesTheFilesOwnDeclarations(): void
    {
        // Beta::run calls Alpha::run inside the same file, so the traversal
        // reaches it. Changing src/Pair.php is not something src/Pair.php
        // notices, and reporting it would inflate every scope comparison.
        $report = (new ImpactAnalyzer())->forFile($this->pairMap(), 'src/Pair.php', 3, 20);

        foreach ($report->impacts as $impact) {
            self::assertNotSame('src/Pair.php', $impact->node->file);
        }
        self::assertNotContains(
            'method:Demo\\Beta::run',
            array_map(static fn (ImpactNode $impact): string => $impact->node->id, $report->impacts),
        );
    }

    public function testFileImpactBoundsTheUnionRatherThanEachDeclaration(): void
    {
        $report = (new ImpactAnalyzer())->forFile($this->pairMap(), 'src/Pair.php', 2, 1);

        self::assertCount(1, $report->impacts);
        self::assertTrue($report->truncated);
    }

    public function testFileImpactKeepsUncertaintyFromTheDeclarationItArrivedThrough(): void
    {
        $report = (new ImpactAnalyzer())->forFile($this->map(), 'src/Implementation.php', 2, 20);

        self::assertSame([
            ['method:Demo\\Caller::call', 1, false],
            ['method:Demo\\MaybeCaller::call', 1, true],
            ['method:Demo\\DualTop::execute', 2, false],
            ['method:Demo\\Top::execute', 2, false],
            ['method:Demo\\MaybeTop::execute', 2, true],
        ], array_map(
            static fn (ImpactNode $impact): array => [$impact->node->id, $impact->depth, $impact->uncertain],
            $report->impacts,
        ));
    }

    public function testFileImpactRefusesAPathTheMapDoesNotIndex(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Impact target is not an indexed repository file: src/Absent.php');

        (new ImpactAnalyzer())->forFile($this->map(), 'src/Absent.php', 2, 20);
    }

    public function testFileImpactRejectsZeroDepthBeforeReadingTheFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ImpactAnalyzer())->forFile($this->map(), 'src/Absent.php', 0);
    }

    private function pairMap(): AgentMapIndex
    {
        $alpha = new SymbolEntry('class', 'Alpha', 'Demo\\Alpha', 1, 10, [new MethodEntry('run', 'public', 5, 6)]);
        $beta = new SymbolEntry('class', 'Beta', 'Demo\\Beta', 12, 20, [new MethodEntry('run', 'public', 15, 16)]);
        $callsAlpha = new SymbolEntry('class', 'CallsAlpha', 'Demo\\CallsAlpha', 1, 10, [new MethodEntry('call', 'public', 5, 6)]);
        $callsBeta = new SymbolEntry('class', 'CallsBeta', 'Demo\\CallsBeta', 1, 10, [new MethodEntry('call', 'public', 5, 6)]);

        $files = [
            new FileEntry('src/Pair.php', 'sha256:pair', 'Demo', [$alpha, $beta]),
            new FileEntry('src/CallsAlpha.php', 'sha256:calls-alpha', 'Demo', [$callsAlpha]),
            new FileEntry('src/CallsBeta.php', 'sha256:calls-beta', 'Demo', [$callsBeta]),
        ];

        $relations = [
            RelationEntry::create('method:Demo\\Beta::run', 'calls', ['method:Demo\\Alpha::run'], 'src/Pair.php', 15, 15, 'phpstan_resolved'),
            RelationEntry::create('method:Demo\\CallsAlpha::call', 'calls', ['method:Demo\\Alpha::run'], 'src/CallsAlpha.php', 5, 5, 'phpstan_resolved'),
            RelationEntry::create('method:Demo\\CallsBeta::call', 'calls', ['method:Demo\\Beta::run'], 'src/CallsBeta.php', 5, 5, 'phpstan_resolved'),
        ];

        return new AgentMapIndex('2.0', '/tmp/agent-map-file-impact', 'test', $files, $relations);
    }

    public function testRejectsZeroDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ImpactAnalyzer())->forMethod($this->map(), 'Demo\\Contract::run', 0);
    }

    /** @param list<ImpactNode> $impacts */
    private function impact(array $impacts, string $id): ImpactNode
    {
        foreach ($impacts as $impact) {
            if ($impact->node->id === $id) {
                return $impact;
            }
        }

        self::fail('Impact node not found: ' . $id);
    }

    private function map(): AgentMapIndex
    {
        $contract = new SymbolEntry('interface', 'Contract', 'Demo\\Contract', 1, 10, [new MethodEntry('run', 'public', 5, 5, abstract: true)]);
        $implementation = new SymbolEntry('class', 'Implementation', 'Demo\\Implementation', 1, 20, [new MethodEntry('run', 'public', 10, 12)]);
        $other = new SymbolEntry('class', 'Other', 'Demo\\Other', 1, 20, [new MethodEntry('run', 'public', 10, 12)]);
        $caller = new SymbolEntry('class', 'Caller', 'Demo\\Caller', 1, 20, [new MethodEntry('call', 'public', 10, 12)]);
        $maybeCaller = new SymbolEntry('class', 'MaybeCaller', 'Demo\\MaybeCaller', 1, 20, [new MethodEntry('call', 'public', 10, 12)]);
        $top = new SymbolEntry('class', 'Top', 'Demo\\Top', 1, 20, [new MethodEntry('execute', 'public', 10, 12)]);
        $maybeTop = new SymbolEntry('class', 'MaybeTop', 'Demo\\MaybeTop', 1, 20, [new MethodEntry('execute', 'public', 10, 12)]);
        $dualTop = new SymbolEntry('class', 'DualTop', 'Demo\\DualTop', 1, 20, [new MethodEntry('execute', 'public', 10, 12)]);

        $files = [
            new FileEntry('src/Contract.php', 'sha256:contract', 'Demo', [$contract]),
            new FileEntry('src/Implementation.php', 'sha256:implementation', 'Demo', [$implementation, $other]),
            new FileEntry('src/Caller.php', 'sha256:caller', 'Demo', [$caller]),
            new FileEntry('src/MaybeCaller.php', 'sha256:maybe', 'Demo', [$maybeCaller]),
            new FileEntry('src/Top.php', 'sha256:top', 'Demo', [$top]),
            new FileEntry('src/MaybeTop.php', 'sha256:maybe-top', 'Demo', [$maybeTop]),
            new FileEntry('src/DualTop.php', 'sha256:dual-top', 'Demo', [$dualTop]),
        ];

        $relations = [
            RelationEntry::create('method:Demo\\Implementation::run', 'overrides', ['method:Demo\\Contract::run'], 'src/Implementation.php', 10, 12, 'phpstan_resolved'),
            RelationEntry::create('method:Demo\\Caller::call', 'calls', ['method:Demo\\Implementation::run'], 'src/Caller.php', 11, 11, 'phpstan_resolved'),
            RelationEntry::create(
                'method:Demo\\MaybeCaller::call',
                'calls',
                ['method:Demo\\Implementation::run', 'method:Demo\\Other::run'],
                'src/MaybeCaller.php',
                11,
                11,
                'multiple_targets',
            ),
            RelationEntry::create('method:Demo\\Top::execute', 'calls', ['method:Demo\\Caller::call'], 'src/Top.php', 11, 11, 'phpstan_resolved'),
            RelationEntry::create('method:Demo\\MaybeTop::execute', 'calls', ['method:Demo\\MaybeCaller::call'], 'src/MaybeTop.php', 11, 11, 'phpstan_resolved'),
            RelationEntry::create('method:Demo\\DualTop::execute', 'calls', ['method:Demo\\Caller::call'], 'src/DualTop.php', 11, 11, 'phpstan_resolved'),
            RelationEntry::create('method:Demo\\DualTop::execute', 'calls', ['method:Demo\\MaybeCaller::call'], 'src/DualTop.php', 12, 12, 'phpstan_resolved'),
        ];

        return new AgentMapIndex('2.0', '/tmp/agent-map-impact', 'test', $files, $relations);
    }
}
