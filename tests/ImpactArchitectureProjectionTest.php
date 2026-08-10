<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\ArchitectureImpactAnalyzer;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class ImpactArchitectureProjectionTest extends TestCase
{
    public function testImpactIsGroupedByInferredPHPRegion(): void
    {
        $service = $this->symbol('Domain', 'Service', 'run');
        $helper = $this->symbol('Domain', 'Helper', 'help');
        $controller = $this->symbol('App', 'Controller', 'handle');
        $command = $this->symbol('App', 'Command', 'execute');

        $relations = [];
        foreach ([10, 11, 12] as $line) {
            $relations[] = RelationEntry::create(
                'method:Domain\\Service::run',
                'calls',
                ['method:Domain\\Helper::help'],
                'src/Domain/Service.php',
                $line,
                $line,
                'phpstan_resolved',
            );
            $relations[] = RelationEntry::create(
                'method:App\\Command::execute',
                'calls',
                ['method:App\\Controller::handle'],
                'src/App/Command.php',
                $line,
                $line,
                'phpstan_resolved',
            );
        }
        $relations[] = RelationEntry::create(
            'method:App\\Controller::handle',
            'calls',
            ['method:Domain\\Service::run'],
            'src/App/Controller.php',
            20,
            20,
            'phpstan_resolved',
        );

        $map = new AgentMapIndex(
            '2.0',
            '/tmp/agent-map-impact-regions',
            'test',
            [
                new FileEntry('src/Domain/Service.php', 'sha256:service', 'Domain', [$service]),
                new FileEntry('src/Domain/Helper.php', 'sha256:helper', 'Domain', [$helper]),
                new FileEntry('src/App/Controller.php', 'sha256:controller', 'App', [$controller]),
                new FileEntry('src/App/Command.php', 'sha256:command', 'App', [$command]),
            ],
            $relations,
        );

        $report = (new ArchitectureImpactAnalyzer())->forMethod($map, 'Domain\\Service::run', 2, 20);

        self::assertContains('Domain', $report->targetArchitecturePath);
        self::assertNotEmpty($report->regionBuckets);
        self::assertSame('App', $report->regionBuckets[0]->label);
        self::assertCount(2, $report->regionBuckets[0]->nodeIds);
        self::assertContains('App', $report->regionBuckets[0]->pathLabels);
    }

    private function symbol(string $namespace, string $class, string $method): SymbolEntry
    {
        return new SymbolEntry(
            'class',
            $class,
            $namespace . '\\' . $class,
            1,
            20,
            [new MethodEntry($method, 'public', 5, 15)],
        );
    }
}
