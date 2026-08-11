<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\ArchitectureMapReport;
use voku\AgentMap\Discovery\ArchitectureRegion;

final class ArchitectureMapReportTest extends TestCase
{
    public function testPathForSelectedParentDoesNotFallIntoFinestChild(): void
    {
        $child = $this->region('region:child', 'Child', 'module', 1, ['src/Child.php'], 'region:root');
        $root = $this->region('region:root', 'Root', 'system', 2, ['src/Child.php', 'src/Other.php'], null, ['region:child']);
        $report = new ArchitectureMapReport('sha256:test', [$root, $child], ['region:root'], [], []);

        self::assertSame(['region:root'], array_map(
            static fn (ArchitectureRegion $region): string => $region->id,
            $report->pathForRegion($root),
        ));
        self::assertSame(['region:child', 'region:root'], array_map(
            static fn (ArchitectureRegion $region): string => $region->id,
            $report->pathForRegion($child),
        ));
        self::assertSame(['region:child', 'region:root'], array_map(
            static fn (ArchitectureRegion $region): string => $region->id,
            $report->pathForFile('src/Child.php'),
        ));
    }

    public function testExactLabelWinsOverAnotherRegionsIdPrefix(): void
    {
        $exact = $this->region('region:exact', 'region:abc', 'system', 1, ['exact.php']);
        $prefix = $this->region('region:abcdef', 'Other', 'system', 1, ['prefix.php']);
        $report = new ArchitectureMapReport('sha256:test', [$exact, $prefix], ['region:exact', 'region:abcdef'], [], []);

        self::assertSame('region:exact', $report->resolveRegion('region:abc')->id);
    }

    /**
     * @param 'module'|'subsystem'|'system' $kind
     * @param non-empty-list<string> $files
     * @param list<string> $childIds
     */
    private function region(
        string $id,
        string $label,
        string $kind,
        int $level,
        array $files,
        ?string $parentId = null,
        array $childIds = [],
    ): ArchitectureRegion {
        return new ArchitectureRegion(
            id: $id,
            label: $label,
            kind: $kind,
            level: $level,
            files: $files,
            parentId: $parentId,
            childIds: $childIds,
            internalWeight: 1.0,
            externalWeight: 0.0,
            boundaryRatio: 100.0,
            boundaryStrength: 1.0,
            internalDensity: 1.0,
            crosscutScore: 0.0,
            namespaceAgreement: 0.0,
            directoryAgreement: 1.0,
            interfaceFiles: [],
            dominantSignals: [],
        );
    }
}
