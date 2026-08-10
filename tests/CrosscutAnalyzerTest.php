<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\CrosscutAnalyzer;
use voku\AgentMap\Discovery\WeightedFileGraph;

final class CrosscutAnalyzerTest extends TestCase
{
    public function testRegularModuleDoesNotMarkEveryFileAsCrossCutting(): void
    {
        $files = ['src/A.php', 'src/B.php', 'src/C.php'];
        $adjacency = array_fill_keys($files, []);
        foreach ([
            ['src/A.php', 'src/B.php'],
            ['src/A.php', 'src/C.php'],
            ['src/B.php', 'src/C.php'],
        ] as [$left, $right]) {
            $adjacency[$left][$right] = 1.0;
            $adjacency[$right][$left] = 1.0;
        }

        $evidence = (new CrosscutAnalyzer())->analyze(new WeightedFileGraph($files, $adjacency, []));

        foreach ($evidence as $item) {
            self::assertNotContains('high_degree', $item->signals);
            self::assertSame(0.0, $item->score);
        }
    }

    public function testDistinctiveHubIsDetected(): void
    {
        $files = ['src/Hub.php', 'src/A.php', 'src/B.php', 'src/C.php', 'src/D.php'];
        $adjacency = array_fill_keys($files, []);
        foreach (['src/A.php', 'src/B.php', 'src/C.php', 'src/D.php'] as $leaf) {
            $adjacency['src/Hub.php'][$leaf] = 1.0;
            $adjacency[$leaf]['src/Hub.php'] = 1.0;
        }

        $evidence = (new CrosscutAnalyzer())->analyze(new WeightedFileGraph($files, $adjacency, []));

        self::assertContains('high_degree', $evidence['src/Hub.php']->signals);
        self::assertGreaterThan(0.5, $evidence['src/Hub.php']->score);
        self::assertNotContains('high_degree', $evidence['src/A.php']->signals);
    }
}
