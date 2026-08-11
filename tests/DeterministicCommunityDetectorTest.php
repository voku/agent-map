<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\DeterministicCommunityDetector;
use voku\AgentMap\Discovery\WeightedFileGraph;

final class DeterministicCommunityDetectorTest extends TestCase
{
    public function testFindsStrongCommunitiesAcrossWeakBridge(): void
    {
        $graph = $this->twoClusterGraph();

        $levels = (new DeterministicCommunityDetector())->cluster($graph);

        self::assertNotEmpty($levels);
        self::assertContains(['a/A.php', 'a/B.php', 'a/C.php'], $levels[0]->communities);
        self::assertContains(['b/D.php', 'b/E.php', 'b/F.php'], $levels[0]->communities);
    }

    public function testRetainsSingleCoherentSystemInsteadOfReportingNoArchitecture(): void
    {
        $files = ['src/A.php', 'src/B.php', 'src/C.php'];
        $adjacency = array_fill_keys($files, []);
        foreach ([
            ['src/A.php', 'src/B.php'],
            ['src/A.php', 'src/C.php'],
            ['src/B.php', 'src/C.php'],
        ] as [$left, $right]) {
            $adjacency[$left][$right] = 4.0;
            $adjacency[$right][$left] = 4.0;
        }

        $levels = (new DeterministicCommunityDetector())->cluster(new WeightedFileGraph($files, $adjacency, []));

        self::assertCount(1, $levels);
        self::assertSame([$files], $levels[0]->communities);
    }

    public function testSameGraphAlwaysProducesByteIdenticalPartitions(): void
    {
        $detector = new DeterministicCommunityDetector();
        $graph = $this->twoClusterGraph();

        $first = $detector->cluster($graph);
        $second = $detector->cluster($graph);

        self::assertEquals($first, $second);
    }

    public function testResolutionGetsCoarserForLargeRepositories(): void
    {
        $detector = new DeterministicCommunityDetector();

        self::assertGreaterThan($detector->resolutionForFileCount(3000), $detector->resolutionForFileCount(50));
        self::assertGreaterThan($detector->resolutionForFileCount(1000), $detector->resolutionForFileCount(500));
    }

    private function twoClusterGraph(): WeightedFileGraph
    {
        $files = ['a/A.php', 'a/B.php', 'a/C.php', 'b/D.php', 'b/E.php', 'b/F.php'];
        $adjacency = array_fill_keys($files, []);
        foreach ([
            ['a/A.php', 'a/B.php', 4.0],
            ['a/A.php', 'a/C.php', 4.0],
            ['a/B.php', 'a/C.php', 4.0],
            ['b/D.php', 'b/E.php', 4.0],
            ['b/D.php', 'b/F.php', 4.0],
            ['b/E.php', 'b/F.php', 4.0],
            ['a/C.php', 'b/D.php', 0.1],
        ] as [$left, $right, $weight]) {
            $adjacency[$left][$right] = $weight;
            $adjacency[$right][$left] = $weight;
        }

        return new WeightedFileGraph($files, $adjacency, []);
    }
}
