<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\ArchitectureDiscovery;
use voku\AgentMap\Discovery\RankedNode;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;

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

    public function testDiscoversItsGovernedRenameExtensionPointAndTestOwner(): void
    {
        $root = dirname(__DIR__);
        $map = (new AgentMapBuilder())->build(
            $root,
            [
                'src/Cli/CliApplication.php',
                'src/Cli/ClassRenameCliApplication.php',
                'src/Cli/FunctionRenameCliApplication.php',
                'src/Cli/RenameCliApplication.php',
                'src/Cli/RenamePlanCapability.php',
                'src/Cli/RenamePlanCliApplication.php',
                'tests/CliApplicationTest.php',
                'tests/ClassRenamePlannerTest.php',
            ],
            [],
            phpStanMemoryLimit: '512M',
        );

        $match = $map->query('RenamePlanCliApplication');
        self::assertSame('exact', $match->matchType);
        self::assertSame(
            ['src/Cli/RenamePlanCliApplication.php'],
            array_map(static fn (FileEntry $file): string => $file->path, $match->files),
        );

        $likelyTests = $map->likelyTestFilesFor($match->files, 10);
        self::assertContains(
            'tests/CliApplicationTest.php',
            array_map(static fn (FileEntry $file): string => $file->path, $likelyTests),
        );

        $interfaceId = 'interface:voku\\AgentMap\\Cli\\RenamePlanCliApplication';
        $implementers = [];
        foreach ($map->relations as $relation) {
            if ($relation->kind === 'implements' && in_array($interfaceId, $relation->targetIds, true)) {
                $implementers[] = $relation->sourceId;
            }
        }
        sort($implementers, SORT_STRING);
        self::assertSame([
            'class:voku\\AgentMap\\Cli\\ClassRenameCliApplication',
            'class:voku\\AgentMap\\Cli\\FunctionRenameCliApplication',
            'class:voku\\AgentMap\\Cli\\RenameCliApplication',
        ], $implementers);

        self::assertNotEmpty(array_filter(
            $map->relations,
            static fn (RelationEntry $relation): bool => $relation->sourceId === 'method:voku\\AgentMap\\Cli\\CliApplication::renameApplications'
                && $relation->kind === 'references_type'
                && in_array($interfaceId, $relation->targetIds, true),
        ));
    }
}
