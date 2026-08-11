<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\FileCouplingGraphBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class FileCouplingGraphBuilderTest extends TestCase
{
    public function testBuildsPHPWeightedFileGraphAndDiscountsUncertainEvidence(): void
    {
        $controller = new SymbolEntry('class', 'Controller', 'App\\Controller', 1, 20, [new MethodEntry('run', 'public', 5, 15)]);
        $certainService = new SymbolEntry('class', 'CertainService', 'Domain\\CertainService', 1, 20, [new MethodEntry('go', 'public', 5, 15)]);
        $uncertainService = new SymbolEntry('class', 'UncertainService', 'Domain\\UncertainService', 1, 20, [new MethodEntry('go', 'public', 5, 15)]);
        $repository = new SymbolEntry('class', 'Repository', 'Infra\\Repository', 1, 20);
        $map = new AgentMapIndex(
            '2.0',
            '/tmp/agent-map-file-coupling',
            'test',
            [
                new FileEntry('src/App/Controller.php', 'sha256:a', 'App', [$controller]),
                new FileEntry('src/Domain/CertainService.php', 'sha256:b', 'Domain', [$certainService]),
                new FileEntry('src/Domain/UncertainService.php', 'sha256:c', 'Domain', [$uncertainService]),
                new FileEntry('src/Infra/Repository.php', 'sha256:d', 'Infra', [$repository]),
            ],
            [
                RelationEntry::create('method:App\\Controller::run', 'calls', ['method:Domain\\CertainService::go'], 'src/App/Controller.php', 7, 7, 'phpstan_resolved'),
                RelationEntry::create('method:App\\Controller::run', 'calls', ['method:Domain\\UncertainService::go'], 'src/App/Controller.php', 8, 8, 'multiple_targets'),
                RelationEntry::create('method:Domain\\CertainService::go', 'instantiates', ['class:Infra\\Repository'], 'src/Domain/CertainService.php', 9, 9, 'phpstan_resolved'),
            ],
        );

        $graph = (new FileCouplingGraphBuilder())->build($map);

        self::assertSame([
            'src/App/Controller.php',
            'src/Domain/CertainService.php',
            'src/Domain/UncertainService.php',
            'src/Infra/Repository.php',
        ], $graph->files);
        self::assertGreaterThan(
            $graph->edgeWeight('src/App/Controller.php', 'src/Domain/UncertainService.php'),
            $graph->edgeWeight('src/App/Controller.php', 'src/Domain/CertainService.php'),
        );
        self::assertArrayHasKey('calls', $graph->signalsBetween('src/App/Controller.php', 'src/Domain/CertainService.php'));
        self::assertArrayHasKey('instantiates', $graph->signalsBetween('src/Domain/CertainService.php', 'src/Infra/Repository.php'));
        self::assertGreaterThan(0.0, $graph->totalWeight());
    }

    public function testPathPriorKeepsNamespaceLessIsolatedLegacyFilesDiscoverable(): void
    {
        $map = new AgentMapIndex(
            '2.0',
            '/tmp/agent-map-file-coupling-legacy',
            'test',
            [
                new FileEntry('legacy/auth/Login.php', 'sha256:a', '', []),
                new FileEntry('legacy/auth/Logout.php', 'sha256:b', '', []),
                new FileEntry('legacy/orders/Order.php', 'sha256:c', '', []),
            ],
        );

        $graph = (new FileCouplingGraphBuilder())->build($map);

        self::assertGreaterThan(0.0, $graph->edgeWeight('legacy/auth/Login.php', 'legacy/auth/Logout.php'));
        self::assertSame(['path'], array_keys($graph->signalsBetween('legacy/auth/Login.php', 'legacy/auth/Logout.php')));
        self::assertSame(0.0, $graph->edgeWeight('legacy/auth/Login.php', 'legacy/orders/Order.php'));
    }

    public function testLargeFlatDirectoriesUseBoundedPathPriorInsteadOfQuadraticClique(): void
    {
        $files = [];
        for ($index = 0; $index < 50; ++$index) {
            $files[] = new FileEntry(sprintf('legacy/flat/File%02d.php', $index), 'sha256:' . $index, '', []);
        }
        $map = new AgentMapIndex('2.0', '/tmp/agent-map-file-coupling-flat', 'test', $files);

        $graph = (new FileCouplingGraphBuilder())->build($map);

        self::assertCount(50, $graph->files);
        foreach ($graph->files as $file) {
            self::assertLessThanOrEqual(4, count($graph->neighbours($file)));
        }
    }
}
