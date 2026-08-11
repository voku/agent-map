<?php

declare(strict_types=1);

namespace voku\AgentMap\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Temporal\CoChangePair;
use voku\AgentMap\Temporal\CoChangeReport;
use voku\AgentMap\Temporal\TemporalClaimAnalyzer;

final class TemporalClaimAnalyzerTest extends TestCase
{
    public function testClaimsOnlyStrongRepeatedCouplingMissingFromSemanticGraph(): void
    {
        $report = new CoChangeReport(20, 0, [
            $this->pair('src/A.php', 'src/B.php', ratio: 0.8, semanticWeight: 0.0),
            $this->pair('src/A.php', 'src/C.php', ratio: 0.9, semanticWeight: 1.2),
            $this->pair('src/A.php', 'src/D.php', ratio: 0.4, semanticWeight: 0.0),
        ]);

        $claims = (new TemporalClaimAnalyzer())->hiddenCoupling($report, 0.6);

        self::assertCount(1, $claims);
        self::assertSame('hidden_temporal_coupling', $claims[0]->kind);
        self::assertSame('src/A.php', $claims[0]->left);
        self::assertSame('src/B.php', $claims[0]->right);
        self::assertSame([str_repeat('a', 40), str_repeat('b', 40)], $claims[0]->revisions);
        self::assertSame(4, $claims[0]->evidence['cochanges']);
        self::assertSame(0.8, $claims[0]->evidence['smaller_side_ratio']);
        self::assertSame(0.0, $claims[0]->evidence['semantic_static_weight']);
    }

    private function pair(string $left, string $right, float $ratio, float $semanticWeight): CoChangePair
    {
        return new CoChangePair(
            left: $left,
            right: $right,
            coChanges: 4,
            leftChanges: 5,
            rightChanges: 5,
            jaccard: 0.666667,
            smallerSideRatio: $ratio,
            revisions: [str_repeat('a', 40), str_repeat('b', 40)],
            semanticStaticWeight: $semanticWeight,
            pathStaticWeight: 0.0,
            staticSignals: [],
        );
    }
}
