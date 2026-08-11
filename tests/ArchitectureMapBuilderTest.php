<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\ArchitectureMapBuilder;
use voku\AgentMap\Discovery\ArchitectureRegion;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class ArchitectureMapBuilderTest extends TestCase
{
    public function testInfersSemanticPHPRegionsWithTransparentBoundaryEvidence(): void
    {
        $map = $this->semanticMap();

        $architecture = (new ArchitectureMapBuilder())->build($map);
        $labels = array_map(static fn (ArchitectureRegion $region): string => $region->label, $architecture->regions);

        self::assertContains('Auth', $labels);
        self::assertContains('Billing', $labels);
        self::assertSame([], $architecture->unassignedFiles);
        self::assertStringStartsWith('sha256:', $architecture->mapDigest);

        $auth = $this->regionByLabel($architecture->regions, 'Auth');
        self::assertGreaterThan($auth->externalWeight, $auth->internalWeight);
        self::assertGreaterThan(0.5, $auth->boundaryStrength);
        self::assertSame(1.0, $auth->namespaceAgreement);
        self::assertSame(1.0, $auth->directoryAgreement);
        self::assertContains('calls', $auth->dominantSignals);

        $payload = $auth->toArray();
        self::assertArrayHasKey('evidence', $payload);
        self::assertArrayNotHasKey('confidence', $payload);
    }

    public function testNamespaceLessLegacyPHPStillFormsDirectoryBackedRegions(): void
    {
        $files = [];
        foreach (['Login', 'Logout', 'Session'] as $name) {
            $files[] = new FileEntry('legacy/auth/' . $name . '.php', 'sha256:' . $name, '', []);
        }
        foreach (['Order', 'Invoice', 'Price'] as $name) {
            $files[] = new FileEntry('legacy/billing/' . $name . '.php', 'sha256:' . $name, '', []);
        }
        $map = new AgentMapIndex('2.0', '/tmp/agent-map-regions-legacy', 'test', $files);

        $architecture = (new ArchitectureMapBuilder())->build($map);
        $labels = array_map(static fn (ArchitectureRegion $region): string => $region->label, $architecture->regions);

        self::assertContains('Auth', $labels);
        self::assertContains('Billing', $labels);
        self::assertSame(0.0, $this->regionByLabel($architecture->regions, 'Auth')->namespaceAgreement);
        self::assertSame(1.0, $this->regionByLabel($architecture->regions, 'Auth')->directoryAgreement);
    }

    public function testSameDirectoryDuplicateBaseLabelsRemainUniqueAndResolvable(): void
    {
        $files = [
            $this->file('src/Feature/AlphaFirst.php', 'Alpha\\Shared', 'First'),
            $this->file('src/Feature/AlphaSecond.php', 'Alpha\\Shared', 'Second'),
            $this->file('src/Feature/BetaThird.php', 'Beta\\Shared', 'Third'),
            $this->file('src/Feature/BetaFourth.php', 'Beta\\Shared', 'Fourth'),
        ];
        $relations = [];
        $line = 10;
        for ($repeat = 0; $repeat < 20; ++$repeat) {
            $relations[] = RelationEntry::create(
                'method:Alpha\\Shared\\First::run',
                'calls',
                ['method:Alpha\\Shared\\Second::run'],
                'src/Feature/AlphaFirst.php',
                $line,
                $line,
                'phpstan_resolved',
            );
            ++$line;
            $relations[] = RelationEntry::create(
                'method:Beta\\Shared\\Third::run',
                'calls',
                ['method:Beta\\Shared\\Fourth::run'],
                'src/Feature/BetaThird.php',
                $line,
                $line,
                'phpstan_resolved',
            );
            ++$line;
        }
        $architecture = (new ArchitectureMapBuilder())->build(
            new AgentMapIndex('2.0', '/tmp/agent-map-duplicate-labels', 'test', $files, $relations),
        );

        $labels = array_values(array_map(
            static fn (ArchitectureRegion $region): string => $region->label,
            array_filter(
                $architecture->regions,
                static fn (ArchitectureRegion $region): bool => str_starts_with($region->label, 'Shared / Feature'),
            ),
        ));

        self::assertCount(2, $labels);
        self::assertCount(2, array_unique($labels));
        foreach ($labels as $label) {
            self::assertSame($label, $architecture->resolveRegion($label)->label);
        }
    }

    public function testRegionIdsAndHierarchyAreDeterministicForSameMapDigest(): void
    {
        $builder = new ArchitectureMapBuilder();
        $map = $this->semanticMap();

        self::assertSame($builder->build($map)->toArray(), $builder->build($map)->toArray());
    }

    /** @param list<ArchitectureRegion> $regions */
    private function regionByLabel(array $regions, string $label): ArchitectureRegion
    {
        foreach ($regions as $region) {
            if ($region->label === $label) {
                return $region;
            }
        }

        self::fail('Region not found: ' . $label);
    }

    private function semanticMap(): AgentMapIndex
    {
        $files = [];
        foreach ([
            ['src/Auth/Login.php', 'App\\Auth', 'Login'],
            ['src/Auth/Session.php', 'App\\Auth', 'Session'],
            ['src/Auth/Token.php', 'App\\Auth', 'Token'],
            ['src/Billing/Invoice.php', 'App\\Billing', 'Invoice'],
            ['src/Billing/Order.php', 'App\\Billing', 'Order'],
            ['src/Billing/Price.php', 'App\\Billing', 'Price'],
        ] as [$path, $namespace, $class]) {
            $files[] = $this->file($path, $namespace, $class);
        }

        $relations = [];
        $line = 5;
        foreach ([
            ['App\\Auth\\Login', 'App\\Auth\\Session'],
            ['App\\Auth\\Login', 'App\\Auth\\Token'],
            ['App\\Auth\\Session', 'App\\Auth\\Token'],
            ['App\\Billing\\Invoice', 'App\\Billing\\Order'],
            ['App\\Billing\\Invoice', 'App\\Billing\\Price'],
            ['App\\Billing\\Order', 'App\\Billing\\Price'],
        ] as [$from, $to]) {
            for ($repeat = 0; $repeat < 3; ++$repeat) {
                $relations[] = RelationEntry::create(
                    'method:' . $from . '::run',
                    'calls',
                    ['method:' . $to . '::run'],
                    $this->pathForClass($from),
                    $line,
                    $line,
                    'phpstan_resolved',
                );
                ++$line;
            }
        }
        $relations[] = RelationEntry::create(
            'method:App\\Auth\\Token::run',
            'references_type',
            ['class:App\\Billing\\Invoice'],
            'src/Auth/Token.php',
            $line,
            $line,
            'multiple_targets',
        );

        return new AgentMapIndex('2.0', '/tmp/agent-map-regions', 'test', $files, $relations);
    }

    private function file(string $path, string $namespace, string $class): FileEntry
    {
        $symbol = new SymbolEntry(
            'class',
            $class,
            $namespace . '\\' . $class,
            1,
            20,
            [new MethodEntry('run', 'public', 5, 15)],
        );

        return new FileEntry($path, 'sha256:' . strtolower($class), $namespace, [$symbol]);
    }

    private function pathForClass(string $class): string
    {
        $parts = explode('\\', $class);
        $name = (string) end($parts);
        $area = $parts[count($parts) - 2];

        return 'src/' . $area . '/' . $name . '.php';
    }
}
