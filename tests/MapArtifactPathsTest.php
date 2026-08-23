<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\MapArtifactPaths;

final class MapArtifactPathsTest extends TestCase
{
    public function testStandaloneArtifactsStayUnderAgentMapRoot(): void
    {
        $paths = MapArtifactPaths::forProject('/project');

        self::assertSame('/project/.agent-map/php-symbols.json', $paths->indexJson());
        self::assertSame('/project/.agent-map/php-symbols.toon', $paths->indexToon());
        self::assertSame('/project/.agent-map/search.sqlite', $paths->searchDatabase());
        self::assertSame('/project/.agent-map/history.sqlite', $paths->historyDatabase());
        self::assertSame('/project/.agent-map/structural-cache.json', $paths->structuralCache());
        self::assertSame('/project/.agent-map/phpstan-cache', $paths->phpStanCache());
    }

    public function testEmbeddingChoosesOnlyTheMapRoot(): void
    {
        $paths = MapArtifactPaths::forProject('/project', '/project/var/agent-state/map');

        self::assertSame('/project/var/agent-state/map/php-symbols.json', $paths->indexJson());
        self::assertSame('/project/var/agent-state/map/search.sqlite', $paths->searchDatabase());
        self::assertSame('/project/var/agent-state/map/history.sqlite', $paths->historyDatabase());
        self::assertSame('/project/var/agent-state/map/structural-cache.json', $paths->structuralCache());
        self::assertSame('/project/var/agent-state/map/phpstan-cache', $paths->phpStanCache());
    }

    public function testRelativeAndWindowsRootsAreNormalized(): void
    {
        self::assertSame(
            'C:/project/var/map/php-symbols.json',
            MapArtifactPaths::forProject('C:\\project', 'var\\map')->indexJson(),
        );
        self::assertSame(
            'D:/agent-map/php-symbols.json',
            MapArtifactPaths::forProject('C:\\project', 'D:\\agent-map')->indexJson(),
        );
    }

    public function testExplicitPathsResolveAgainstProjectRootAndPreserveAbsolutePaths(): void
    {
        $paths = MapArtifactPaths::forProject('/project', '/state/map');

        self::assertSame('/project/custom/map.json', $paths->projectPath('custom/map.json'));
        self::assertSame('/project/custom/map.json', $paths->projectPath('custom\\map.json'));
        self::assertSame('/elsewhere/map.json', $paths->projectPath('/elsewhere/map.json'));
        self::assertSame('D:/maps/map.json', $paths->projectPath('D:\\maps\\map.json'));
    }
}
