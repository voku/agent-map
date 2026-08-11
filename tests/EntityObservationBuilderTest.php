<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;
use voku\AgentMap\Temporal\EntityObservation;
use voku\AgentMap\Temporal\EntityObservationBuilder;

final class EntityObservationBuilderTest extends TestCase
{
    public function testCountsUniqueGraphNeighboursAndAssignsArchitectureRegion(): void
    {
        $map = new AgentMapIndex(
            '2.0',
            '/tmp/agent-map-observation',
            'test',
            [
                $this->file('src/A.php', 'App\\A'),
                $this->file('src/B.php', 'App\\B'),
            ],
            [
                $this->call('method:App\\A::run', 'method:App\\B::run', 10),
                $this->call('method:App\\A::run', 'method:App\\B::run', 11),
                RelationEntry::create(
                    'method:App\\A::run',
                    'references_type',
                    ['class:App\\B'],
                    'src/A.php',
                    12,
                    12,
                    'phpstan_resolved',
                ),
            ],
        );

        $observations = (new EntityObservationBuilder())->build($map);
        $a = $this->observation($observations, 'method:App\\A::run');
        $b = $this->observation($observations, 'method:App\\B::run');

        self::assertSame(1, $a->callees, 'Repeated call sites count as one graph neighbour.');
        self::assertSame(2, $a->dependencies, 'Call target and referenced class are distinct dependencies.');
        self::assertSame(1, $b->callers);
        self::assertNotNull($a->regionId);
        self::assertNotNull($a->regionLabel);
    }

    /** @param list<EntityObservation> $observations */
    private function observation(array $observations, string $id): EntityObservation
    {
        foreach ($observations as $observation) {
            if ($observation->entityId === $id) {
                return $observation;
            }
        }

        self::fail('Observation not found: ' . $id);
    }

    private function file(string $path, string $fqn): FileEntry
    {
        $parts = explode('\\', $fqn);
        $name = (string) end($parts);
        $namespace = implode('\\', array_slice($parts, 0, -1));

        return new FileEntry(
            $path,
            'sha256:' . hash('sha256', $path),
            $namespace,
            [new SymbolEntry('class', $name, $fqn, 1, 30, [new MethodEntry('run', 'public', 5, 20)])],
        );
    }

    private function call(string $source, string $target, int $line): RelationEntry
    {
        return RelationEntry::create(
            $source,
            'calls',
            [$target],
            'src/A.php',
            $line,
            $line,
            'phpstan_resolved',
        );
    }
}
