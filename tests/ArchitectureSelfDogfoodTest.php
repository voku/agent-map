<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\ArchitectureMapBuilder;
use voku\AgentMap\Discovery\ArchitectureRegion;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\FileEntry;

final class ArchitectureSelfDogfoodTest extends TestCase
{
    public function testAgentMapDiscoversArchitectureFromItsOwnRealPHPSource(): void
    {
        $root = dirname(__DIR__);
        $paths = [
            'src/Discovery/ArchitectureDiscovery.php',
            'src/Discovery/GraphRanker.php',
            'src/Discovery/ImpactAnalyzer.php',
            'src/Index/AgentMapBuilder.php',
            'src/Index/AgentMapIndex.php',
            'src/Index/IndexReader.php',
            'src/Search/ChunkExtractor.php',
            'src/Search/HybridSearch.php',
            'src/Search/SearchIndexStore.php',
        ];

        $map = (new AgentMapBuilder())->build(
            $root,
            $paths,
            [],
            phpStanMemoryLimit: '512M',
        );

        $indexedPaths = array_map(static fn (FileEntry $file): string => $file->path, $map->files);
        sort($indexedPaths, SORT_STRING);
        $expectedPaths = $paths;
        sort($expectedPaths, SORT_STRING);
        self::assertSame($expectedPaths, $indexedPaths);

        $builder = new ArchitectureMapBuilder();
        $architecture = $builder->build($map);

        self::assertGreaterThanOrEqual(2, count($architecture->regions));
        self::assertSame([], $architecture->unassignedFiles);
        self::assertSame($architecture->toArray(), $builder->build($map)->toArray());
        self::assertNotEmpty($architecture->pathForFile('src/Search/HybridSearch.php'));
        self::assertTrue($this->hasPhysicallyCoherentRegion($architecture->regions));
    }

    /** @param list<ArchitectureRegion> $regions */
    private function hasPhysicallyCoherentRegion(array $regions): bool
    {
        foreach ($regions as $region) {
            if (count($region->files) >= 2 && $region->directoryAgreement >= 0.66) {
                return true;
            }
        }

        return false;
    }
}
