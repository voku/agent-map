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

        self::assertSame('/project/.agent-map', $paths->root());
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

        self::assertSame('/project/var/agent-state/map', $paths->root());
        self::assertSame('/project/var/agent-state/map/php-symbols.json', $paths->indexJson());
        self::assertSame('/project/var/agent-state/map/search.sqlite', $paths->searchDatabase());
        self::assertSame('/project/var/agent-state/map/history.sqlite', $paths->historyDatabase());
        self::assertSame('/project/var/agent-state/map/structural-cache.json', $paths->structuralCache());
        self::assertSame('/project/var/agent-state/map/phpstan-cache', $paths->phpStanCache());
    }

    public function testRelativeAndWindowsRootsAreNormalized(): void
    {
        self::assertSame(
            'C:/project/var/map',
            MapArtifactPaths::forProject('C:\\project', 'var\\map')->root(),
        );
        self::assertSame(
            'D:/agent-map',
            MapArtifactPaths::forProject('C:\\project', 'D:\\agent-map')->root(),
        );
    }
}
