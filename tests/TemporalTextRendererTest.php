<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\TemporalTextRenderer;
use voku\AgentMap\Temporal\CoChangePair;
use voku\AgentMap\Temporal\CoChangeReport;
use voku\AgentMap\Temporal\TemporalClaim;

final class TemporalTextRendererTest extends TestCase
{
    public function testCouplingAndClaimsTextPreserveRevisionEvidence(): void
    {
        $revisions = [str_repeat('a', 40), str_repeat('b', 40)];
        $pair = new CoChangePair(
            left: 'src/A.php',
            right: 'src/B.php',
            coChanges: 2,
            leftChanges: 3,
            rightChanges: 2,
            jaccard: 0.666667,
            smallerSideRatio: 1.0,
            revisions: $revisions,
            semanticStaticWeight: 0.0,
            pathStaticWeight: 0.2,
            staticSignals: ['path' => 0.2],
        );
        $claim = new TemporalClaim(
            'hidden_temporal_coupling',
            'src/A.php',
            'src/B.php',
            $revisions,
            [
                'cochanges' => 2,
                'left_changes' => 3,
                'right_changes' => 2,
                'jaccard' => 0.666667,
                'smaller_side_ratio' => 1.0,
                'semantic_static_weight' => 0.0,
                'path_static_weight' => 0.2,
            ],
        );
        $renderer = new TemporalTextRenderer();

        $coupling = $renderer->coupling(new CoChangeReport(10, 0, [$pair]));
        $claims = $renderer->claims([$claim], 0.6);

        self::assertStringContainsString('revisions=aaaaaaaaaaaa,bbbbbbbbbbbb', $coupling);
        self::assertStringContainsString('revisions=aaaaaaaaaaaa,bbbbbbbbbbbb', $claims);
    }
}
