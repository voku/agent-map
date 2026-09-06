<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\GraphAdjacency;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\GraphProjectionFactory;
use voku\AgentMap\Index\RelationEntry;

final class AgentGraphIntegrationTest extends TestCase
{
    public function testSharedAdjacencyPreservesMapOwnerRelationsAndOrder(): void
    {
        $map = $this->map();
        $adjacency = new GraphAdjacency($map);

        self::assertSame($map->incoming('target-a'), $adjacency->incoming('target-a'));
        self::assertSame($map->outgoing('source'), $adjacency->outgoing('source'));
    }

    public function testProjectionPreservesOnlyStructuralRelationIdentity(): void
    {
        $map = $this->map();
        $projection = (new GraphProjectionFactory())->fromIndex($map);

        self::assertCount(3, $projection->relations);
        self::assertSame('r2', $projection->relations[0]->id);
        self::assertSame('source', $projection->relations[0]->sourceId);
        self::assertSame('calls', $projection->relations[0]->kind);
        self::assertSame(['target-b', 'target-a'], $projection->relations[0]->targetIds);
        self::assertSame(['r2', 'r1', 'r3'], array_map(
            static fn ($relation): string => $relation->id,
            $projection->relations,
        ));
    }

    private function map(): AgentMapIndex
    {
        return new AgentMapIndex(
            schemaVersion: AgentMapIndex::SCHEMA_VERSION,
            root: '/repo',
            backend: 'structural',
            files: [],
            relations: [
                new RelationEntry('r2', 'source', 'calls', ['target-b', 'target-a'], 'src/A.php', 1, 1, 'resolved'),
                new RelationEntry('r1', 'source', 'extends', ['target-a'], 'src/A.php', 2, 2, 'resolved'),
                new RelationEntry('r3', 'other', 'calls', ['target-a'], 'src/B.php', 1, 1, 'resolved'),
            ],
        );
    }
}
