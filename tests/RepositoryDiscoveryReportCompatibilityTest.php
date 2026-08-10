<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Discovery\RepositoryDiscoveryReport;

final class RepositoryDiscoveryReportCompatibilityTest extends TestCase
{
    public function testLegacyConstructorAndPayloadRemainValidWithoutArchitecture(): void
    {
        $report = new RepositoryDiscoveryReport(
            'sha256:legacy',
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            [
                'total_relations' => 0,
                'certain_relations' => 0,
                'uncertain_relations' => 0,
                'dynamic_relations' => 0,
                'multiple_target_relations' => 0,
                'diagnostics' => 0,
            ],
        );

        self::assertNull($report->architecture);
        self::assertArrayNotHasKey('architecture', $report->toArray());
        self::assertSame('sha256:legacy', $report->toArray()['map_digest']);
    }
}
