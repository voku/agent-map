<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\SingletonCommunityAbsorber;
use voku\AgentMap\Discovery\WeightedFileGraph;

final class SingletonCommunityAbsorberTest extends TestCase
{
    public function testLiveCommunitySizesPreventLaterSnapshotSingletonFromBeingStranded(): void
    {
        $files = ['A.php', 'B.php', 'C.php'];
        $graph = new WeightedFileGraph(
            $files,
            [
                'A.php' => ['B.php' => 1.0],
                'B.php' => ['A.php' => 1.0, 'C.php' => 1.0],
                'C.php' => ['B.php' => 1.0],
            ],
            [],
        );

        $assignment = (new SingletonCommunityAbsorber())->absorb($graph, [
            'A.php' => 0,
            'B.php' => 1,
            'C.php' => 2,
        ]);

        self::assertSame($assignment['A.php'], $assignment['B.php']);
        self::assertSame($assignment['B.php'], $assignment['C.php']);
    }
}
