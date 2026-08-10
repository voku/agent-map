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
            $symbol = new SymbolEntry('class', $class, $namespace . '\\' . $class, 1, 20, [new MethodEntry('run', 'public', 5, 15)]);
            $files[] = new FileEntry($path, 'sha256:' . strtolower($class), $namespace, [$symbol]);
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

    private function pathForClass(string $class): string
    {
        $parts = explode('\\', $class);
        $name = (string) end($parts);
        $area = $parts[count($parts) - 2];

        return 'src/' . $area . '/' . $name . '.php';
    }
}
