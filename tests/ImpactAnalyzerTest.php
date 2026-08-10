<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\ImpactAnalyzer;
use voku\AgentMap\Discovery\ImpactNode;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
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
