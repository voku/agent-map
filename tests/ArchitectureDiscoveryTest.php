<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\ArchitectureDiscovery;
use voku\AgentMap\Discovery\RankedNode;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class ArchitectureDiscoveryTest extends TestCase
{
    public function testDiscoversStartingPointsHubsAndIndependentCouplingViews(): void
    {
        $report = (new ArchitectureDiscovery())->discover($this->map(), 10);

        self::assertSame('method:App\\Controller::handle', $report->entrypointCandidates[0]->node->id);
        self::assertSame(2, $report->entrypointCandidates[0]->score);

        self::assertSame('method:Domain\\Service::run', $report->callHubs[0]->node->id);
        self::assertSame(3, $report->callHubs[0]->score);

        self::assertSame('method:App\\Controller::handle', $report->orchestrators[0]->node->id);
        self::assertSame(2, $report->orchestrators[0]->score);

        self::assertSame('class:Domain\\Service', $report->typeHubs[0]->node->id);
        self::assertSame(2, $report->typeHubs[0]->score);

        self::assertSame([
            'from' => 'App',
            'to' => 'Domain',
            'links' => 6,
            'uncertain_links' => 2,
        ], $report->namespaceCoupling[0]);
        self::assertSame([
            'from' => 'src/App',
            'to' => 'src/Domain',
            'links' => 6,
            'uncertain_links' => 2,
        ], $report->directoryCoupling[0]);
        self::assertContains([
            'from' => 'src/App/Controller.php',
            'to' => 'src/Domain/Service.php',
            'links' => 2,
            'uncertain_links' => 0,
        ], $report->fileCoupling);
        self::assertSame(1, $report->quality['multiple_target_relations']);
        self::assertSame(1, $report->quality['uncertain_relations']);
        self::assertStringStartsWith('sha256:', $report->mapDigest);
    }

    public function testUsesDirectoryAndFileCouplingWhenNamespacesAreAbsent(): void
    {
        $front = new SymbolEntry('class', 'Front', 'Front', 1, 20, [new MethodEntry('handle', 'public', 10, 15)]);
        $service = new SymbolEntry('class', 'Service', 'Service', 1, 20, [new MethodEntry('run', 'public', 10, 15)]);
        $bootstrap = new SymbolEntry('function', 'bootstrap', 'bootstrap', 1, 8);
        $map = new AgentMapIndex(
            '2.0',
            '/tmp/agent-map-global-discovery',
            'test',
            [
                new FileEntry('legacy/web/Front.php', 'sha256:front', '', [$front]),
                new FileEntry('legacy/domain/Service.php', 'sha256:service', '', [$service]),
                new FileEntry('bootstrap.php', 'sha256:bootstrap', '', [$bootstrap]),
            ],
            [
                RelationEntry::create('method:Front::handle', 'calls', ['method:Service::run'], 'legacy/web/Front.php', 12, 12, 'phpstan_resolved'),
                RelationEntry::create('function:bootstrap', 'calls', ['method:Service::run'], 'bootstrap.php', 4, 4, 'phpstan_resolved'),
            ],
        );

        $report = (new ArchitectureDiscovery())->discover($map, 10);

        self::assertSame([], $report->namespaceCoupling);
        self::assertContains([
            'from' => 'legacy/web',
            'to' => 'legacy/domain',
            'links' => 1,
            'uncertain_links' => 0,
        ], $report->directoryCoupling);
        self::assertContains([
            'from' => '(root)',
            'to' => 'legacy/domain',
            'links' => 1,
            'uncertain_links' => 0,
        ], $report->directoryCoupling);
        self::assertContains([
            'from' => 'legacy/web/Front.php',
            'to' => 'legacy/domain/Service.php',
            'links' => 1,
            'uncertain_links' => 0,
        ], $report->fileCoupling);
        self::assertContains([
            'from' => 'bootstrap.php',
            'to' => 'legacy/domain/Service.php',
            'links' => 1,
            'uncertain_links' => 0,
        ], $report->fileCoupling);
    }

    public function testAbstractMethodsAreNotEntrypointCandidates(): void
    {
        $report = (new ArchitectureDiscovery())->discover($this->map(), 20);
        $ids = array_map(static fn (RankedNode $row): string => $row->node->id, $report->entrypointCandidates);

        self::assertNotContains('method:Domain\\Contract::run', $ids);
    }

    public function testRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ArchitectureDiscovery())->discover($this->map(), 0);
    }

    private function map(): AgentMapIndex
    {
        $controller = new SymbolEntry('class', 'Controller', 'App\\Controller', 1, 30, [new MethodEntry('handle', 'public', 10, 20)]);
        $command = new SymbolEntry('class', 'Command', 'App\\Command', 1, 30, [new MethodEntry('execute', 'public', 10, 20)]);
        $hook = new SymbolEntry('class', 'Hook', 'App\\Hook', 1, 30, [new MethodEntry('invoke', 'public', 10, 20)]);
        $contract = new SymbolEntry('interface', 'Contract', 'Domain\\Contract', 1, 10, [new MethodEntry('run', 'public', 5, 5, abstract: true)]);
        $service = new SymbolEntry('class', 'Service', 'Domain\\Service', 1, 40, [new MethodEntry('run', 'public', 10, 30)]);
        $repository = new SymbolEntry('class', 'Repository', 'Infra\\Repository', 1, 30, [new MethodEntry('save', 'public', 10, 20)]);
        $logger = new SymbolEntry('class', 'Logger', 'Infra\\Logger', 1, 30, [new MethodEntry('info', 'public', 10, 20)]);

        $files = [
            new FileEntry('src/App/Controller.php', 'sha256:controller', 'App', [$controller]),
            new FileEntry('src/App/Command.php', 'sha256:command', 'App', [$command]),
            new FileEntry('src/App/Hook.php', 'sha256:hook', 'App', [$hook]),
            new FileEntry('src/Domain/Contract.php', 'sha256:contract', 'Domain', [$contract]),
            new FileEntry('src/Domain/Service.php', 'sha256:service', 'Domain', [$service]),
            new FileEntry('src/Infra/Repository.php', 'sha256:repository', 'Infra', [$repository]),
            new FileEntry('src/Infra/Logger.php', 'sha256:logger', 'Infra', [$logger]),
        ];

        $relations = [
            RelationEntry::create('method:App\\Controller::handle', 'calls', ['method:Domain\\Service::run'], 'src/App/Controller.php', 12, 12, 'phpstan_resolved'),
            RelationEntry::create('method:App\\Controller::handle', 'calls', ['method:Infra\\Logger::info'], 'src/App/Controller.php', 13, 13, 'phpstan_resolved'),
            RelationEntry::create('method:App\\Command::execute', 'calls', ['method:Domain\\Service::run'], 'src/App/Command.php', 12, 12, 'phpstan_resolved'),
            RelationEntry::create(
                'method:App\\Hook::invoke',
                'calls',
                ['method:Domain\\Service::run', 'method:Domain\\Contract::run'],
                'src/App/Hook.php',
                12,
                12,
                'multiple_targets',
            ),
            RelationEntry::create('method:Domain\\Service::run', 'calls', ['method:Infra\\Repository::save'], 'src/Domain/Service.php', 15, 15, 'phpstan_resolved'),
            RelationEntry::create('method:Domain\\Service::run', 'calls', ['method:Infra\\Logger::info'], 'src/Domain/Service.php', 16, 16, 'phpstan_resolved'),
            RelationEntry::create('method:App\\Controller::handle', 'instantiates', ['class:Domain\\Service'], 'src/App/Controller.php', 11, 11, 'phpstan_resolved'),
            RelationEntry::create('method:App\\Command::execute', 'references_type', ['class:Domain\\Service'], 'src/App/Command.php', 10, 10, 'phpstan_resolved'),
            RelationEntry::create('method:Domain\\Service::run', 'overrides', ['method:Domain\\Contract::run'], 'src/Domain/Service.php', 10, 30, 'phpstan_resolved'),
        ];

        return new AgentMapIndex('2.0', '/tmp/agent-map-discovery', 'test', $files, $relations);
    }
}
