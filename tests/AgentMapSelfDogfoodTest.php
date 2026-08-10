<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\ArchitectureDiscovery;
use voku\AgentMap\Discovery\RankedNode;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;

/** @internal */
final class AgentMapSelfDogfoodTest extends TestCase
{
    public function testIndexesAndDiscoversItsOwnFocusedBuilderAndTestFiles(): void
    {
        $root = dirname(__DIR__);
        $builder = (new AgentMapBuilder())->build(
            $root,
            ['src/Index/AgentMapBuilder.php', 'tests/AgentMapBuilderTest.php'],
            [],
            phpStanMemoryLimit: '512M',
        );

        self::assertSame(
            ['src/Index/AgentMapBuilder.php', 'tests/AgentMapBuilderTest.php'],
            array_map(static fn (FileEntry $file): string => $file->path, $builder->files),
        );

        $match = $builder->query('build');
        self::assertSame('exact', $match->matchType);
        self::assertSame(
            ['src/Index/AgentMapBuilder.php', 'tests/AgentMapBuilderTest.php'],
            array_map(static fn (FileEntry $file): string => $file->path, $match->files),
        );
        self::assertContains('build', array_map(static fn (MethodEntry $method): string => $method->name, $match->files[0]->symbols[0]->methods));

        $discovery = (new ArchitectureDiscovery())->discover($builder, 20);
        self::assertContains(
            'method:voku\\AgentMap\\Index\\AgentMapBuilder::build',
            array_map(static fn (RankedNode $row): string => $row->node->id, $discovery->callHubs),
        );
        self::assertNotEmpty(array_filter(
            $discovery->fileCoupling,
            static fn (array $row): bool => $row['from'] === 'tests/AgentMapBuilderTest.php'
                && $row['to'] === 'src/Index/AgentMapBuilder.php',
        ));
    }
}
