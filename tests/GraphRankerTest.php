<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\GraphMetric;
use voku\AgentMap\Discovery\GraphRanker;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class GraphRankerTest extends TestCase
{
    public function testRanksUniqueCallersAndKeepsMultipleTargetsVisibleAsUncertainty(): void
    {
        $ranked = (new GraphRanker())->rank($this->map(), GraphMetric::CALLERS, 'method', 10);

        self::assertSame('method:Demo\\Target::run', $ranked[0]->node->id);
        self::assertSame(3, $ranked[0]->score);
        self::assertSame(1, $ranked[0]->uncertainRelations);
    }

    public function testRepeatedCallSitesFromSameMethodCountAsOneNeighbour(): void
    {
        $ranked = (new GraphRanker())->rank($this->map(), GraphMetric::CALLERS, 'method', 10);

        self::assertSame(3, $ranked[0]->score);
    }

    public function testRanksClassMembersWithoutMixingOtherRelationKinds(): void
    {
        $ranked = (new GraphRanker())->rank($this->map(), GraphMetric::MEMBERS, 'class', 10);

        self::assertSame('class:Demo\\Target', $ranked[0]->node->id);
        self::assertSame(1, $ranked[0]->score);
        self::assertSame(0, $ranked[0]->uncertainRelations);
    }

    public function testRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GraphRanker())->rank($this->map(), limit: 0);
    }

    private function map(): AgentMapIndex
    {
        $targetMethod = new MethodEntry('run', 'public', 10, 12);
        $otherMethod = new MethodEntry('run', 'public', 20, 22);
        $callerAMethod = new MethodEntry('callA', 'public', 5, 8);
        $callerBMethod = new MethodEntry('callB', 'public', 5, 8);
        $callerCMethod = new MethodEntry('callC', 'public', 5, 8);

        $target = new SymbolEntry('class', 'Target', 'Demo\\Target', 1, 30, [$targetMethod]);
        $other = new SymbolEntry('class', 'Other', 'Demo\\Other', 1, 30, [$otherMethod]);
        $callerA = new SymbolEntry('class', 'CallerA', 'Demo\\CallerA', 1, 20, [$callerAMethod]);
        $callerB = new SymbolEntry('class', 'CallerB', 'Demo\\CallerB', 1, 20, [$callerBMethod]);
        $callerC = new SymbolEntry('class', 'CallerC', 'Demo\\CallerC', 1, 20, [$callerCMethod]);

        $files = [
            new FileEntry('src/Target.php', 'sha256:target', 'Demo', [$target, $other]),
            new FileEntry('src/CallerA.php', 'sha256:a', 'Demo', [$callerA]),
            new FileEntry('src/CallerB.php', 'sha256:b', 'Demo', [$callerB]),
            new FileEntry('src/CallerC.php', 'sha256:c', 'Demo', [$callerC]),
        ];

        $relations = [
            RelationEntry::create('class:Demo\\Target', 'declares_method', ['method:Demo\\Target::run'], 'src/Target.php', 10, 12, 'structural_only'),
            RelationEntry::create('method:Demo\\CallerA::callA', 'calls', ['method:Demo\\Target::run'], 'src/CallerA.php', 6, 6, 'phpstan_resolved'),
            RelationEntry::create('method:Demo\\CallerA::callA', 'calls', ['method:Demo\\Target::run'], 'src/CallerA.php', 7, 7, 'phpstan_resolved'),
            RelationEntry::create('method:Demo\\CallerB::callB', 'calls', ['method:Demo\\Target::run'], 'src/CallerB.php', 6, 6, 'phpstan_resolved'),
            RelationEntry::create(
                'method:Demo\\CallerC::callC',
                'calls',
                ['method:Demo\\Other::run', 'method:Demo\\Target::run'],
                'src/CallerC.php',
                6,
                6,
                'multiple_targets',
            ),
        ];

        return new AgentMapIndex('2.0', '/tmp/agent-map-rank', 'test', $files, $relations);
    }
}
