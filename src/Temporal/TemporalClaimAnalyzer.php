<?php

declare(strict_types=1);

namespace voku\AgentMap\Temporal;

final readonly class TemporalClaimAnalyzer
{
    /** @return list<TemporalClaim> */
    public function hiddenCoupling(CoChangeReport $report, float $minimumSmallerSideRatio = 0.6): array
    {
        $claims = [];
        foreach ($report->pairs as $pair) {
            if ($pair->semanticStaticWeight > 0.000001 || $pair->smallerSideRatio < $minimumSmallerSideRatio) {
                continue;
            }

            $claims[] = new TemporalClaim(
                kind: 'hidden_temporal_coupling',
                left: $pair->left,
                right: $pair->right,
                evidence: [
                    'cochanges' => $pair->coChanges,
                    'left_changes' => $pair->leftChanges,
                    'right_changes' => $pair->rightChanges,
                    'jaccard' => $pair->jaccard,
                    'smaller_side_ratio' => $pair->smallerSideRatio,
                    'semantic_static_weight' => $pair->semanticStaticWeight,
                    'path_static_weight' => $pair->pathStaticWeight,
                ],
            );
        }

        return $claims;
    }
}
